<?php

namespace App\Services\Import;

use App\Services\Catalog\CatalogConfigurationSource;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/** CSV projection only; neither installation approval nor a diff of persisted variants. */
final class CatalogImportConfigurationPlan
{
    public function __construct(private readonly CatalogConfigurationSource $source) {}

    /**
     * Configurations are stored once at matrix[type].configurations[signature].
     * Each expected product references that matrix entry through matrix_type.
     * A null reference/count means unresolved coverage, never zero variants to create.
     */
    public function plan(array $productPlan, string $csvPath): array
    {
        if (($productPlan['purpose'] ?? null) !== 'excel_assortment_only'
            || ! isset($productPlan['required_part_types'], $productPlan['expected_products'], $productPlan['file']['sha256'])) {
            throw new InvalidArgumentException('Нужен план Excel со всеми типами из заголовков. Повторите расчёт этапа 2А.');
        }

        return $this->project($productPlan, $csvPath);
    }

    /** CSV-only has no Excel coverage contract or expected-product input. */
    public function csv(string $csvPath, string $source = 'catalog'): array
    {
        return $this->project(null, $csvPath, $source);
    }

    private function project(?array $productPlan, string $csvPath, string $source = 'catalog'): array
    {
        $sha256 = is_file($csvPath) ? hash_file('sha256', $csvPath) : false;
        if (! is_string($sha256)) {
            throw new RuntimeException('Не удалось прочитать исходный CSV.');
        }
        $errors = ($productPlan['execution_allowed'] ?? $productPlan['valid'] ?? true) ? [] : [['code' => 'unsafe_product_plan',
            'message' => 'План идентичности/ассортимента содержит нерешённые конфликты.']];
        $rows = [];
        try {
            $rows = $this->source->read($csvPath);
        } catch (RuntimeException|ValidationException $exception) {
            $errors[] = ['code' => 'invalid_csv', 'message' => $exception->getMessage()];
        }
        if (! hash_equals($sha256, (string) hash_file('sha256', $csvPath))) {
            throw new RuntimeException('CSV изменился во время расчёта. Повторите предпросмотр.');
        }

        $matrix = [];
        foreach ($rows as $row) {
            foreach (CatalogConfigurationSource::targetPaths($row) as $type) {
                $matrix[$type] ??= ['valid' => true, 'configuration_count' => 0, 'configurations' => []];
                $error = null;
                if ($row['options'] === []) {
                    // The real matrix excludes empty option combinations from sale.
                    $error = 'empty_configuration';
                } elseif (isset($matrix[$type]['configurations'][$row['signature']])) {
                    // Do not silently choose between overlapping CSV groups after expansion.
                    $error = 'conflicting_configuration';
                }
                if ($error !== null) {
                    $matrix[$type]['valid'] = false;
                    $errors[] = ['code' => $error, 'part_type_full_slug' => $type,
                        'signature' => $row['signature'], 'message' => 'Неполная или неоднозначная комбинация CSV для типа '.$type.'.'];

                    continue;
                }
                $matrix[$type]['configurations'][$row['signature']] = [
                    'options' => $row['options'], 'price' => $row['price'], 'source_group' => $row['group'],
                ];
                $matrix[$type]['configuration_count']++;
            }
        }
        $required = array_values(array_unique($productPlan['required_part_types'] ?? []));
        // Defensive coverage also catches malformed callers omitting a positive type.
        foreach ($productPlan['execution_products'] ?? $productPlan['expected_products'] ?? [] as $product) {
            if (! in_array($product['part_type_full_slug'], $required, true)) {
                $required[] = $product['part_type_full_slug'];
            }
        }
        foreach ($required as $type) {
            if (! isset($matrix[$type])) {
                $errors[] = ['code' => 'missing_type', 'part_type_full_slug' => $type,
                    'message' => 'В новом CSV нет конфигураций для типа из заголовков Excel: '.$type.'.'];
            }
        }
        // Reuse the same bidirectional Excel/CSV contract as the actual full import.
        // Presence of every Excel type is not enough: extra CSV groups also block it.
        if ($productPlan !== null && $errors === []) {
            try {
                app(CatalogSourceContract::class)->validatePaths(array_fill_keys($required, 'Тип детали Excel'), $csvPath);
            } catch (ValidationException $exception) {
                foreach ($exception->errors()['configuration'] ?? [$exception->getMessage()] as $message) {
                    $errors[] = ['code' => 'incompatible_sources', 'message' => $message];
                }
            }
        }
        if (! hash_equals($sha256, (string) hash_file('sha256', $csvPath))) {
            throw new RuntimeException('CSV изменился во время расчёта. Повторите предпросмотр.');
        }
        $products = [];
        foreach ($productPlan['execution_products'] ?? $productPlan['expected_products'] ?? [] as $product) {
            $type = $product['part_type_full_slug'];
            $covered = $matrix[$type]['valid'] ?? false;
            $products[] = array_replace($product, [
                'matrix_type' => $covered ? $type : null,
                'configuration_count' => $covered ? $matrix[$type]['configuration_count'] : null,
            ]);
        }

        return [
            'version' => 1,
            'purpose' => 'projected_configuration_only',
            'valid' => $errors === [],
            'source' => $productPlan['source'] ?? $source,
            'files' => ['excel' => $productPlan['file'] ?? null, 'csv' => ['name' => basename($csvPath), 'sha256' => $sha256]],
            'required_part_types' => $required,
            'matrix' => $matrix,
            'expected_products' => $products,
            'diagnostics' => ['errors' => $errors, 'excel' => $productPlan['diagnostics'] ?? null],
        ];
    }
}

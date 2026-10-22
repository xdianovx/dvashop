<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Support\CatalogText;
use RuntimeException;

/** Shared read-only identity/OR/collision calculation for preview and snapshot preparation. */
final class CatalogImportExpectedProducts
{
    public function __construct(
        private readonly CatalogImportIdentity $identity,
        private readonly ImportPartTypeResolver $partTypes,
    ) {}

    /** @param iterable<array> $rows Normalized catalog rows with physical _row_number. */
    public function collect(iterable $rows, array $headers, string $source, bool $strict = true): array
    {
        $source = CatalogText::normKey($source ?: 'catalog', 'catalog', 60);
        $paths = [];
        foreach ($headers as $column => $header) {
            // Actual import has already resolved these paths. Preview resolves names only.
            $paths[$column] = $header['part_type_full_slug'] ?? $this->partTypes->structuralPath($header);
        }
        $this->identity->loadExisting();
        $expected = [];
        $vehicles = [];
        $vehicleRows = [];
        $duplicates = 0;
        $duplicateVehicles = 0;
        $acceptedRows = 0;
        $errors = [];
        $errorCount = 0;
        $errorCodes = [];
        $versions = [];
        $generationKeys = [];
        $diagnose = function (array $error) use ($strict, &$errors, &$errorCount, &$errorCodes): void {
            if ($strict) {
                throw new RuntimeException($error['message']);
            }
            $errorCount++;
            $errorCodes[$error['code']] = ($errorCodes[$error['code']] ?? 0) + 1;
            if (count($errors) < 25 && $errorCodes[$error['code']] <= 5) {
                $errors[] = $error;
            }
        };
        foreach ($rows as $row) {
            $acceptedRows++;
            $vehicleFingerprint = implode('|', array_map(
                static fn ($part) => preg_replace('/\s*([\p{Pd}()])\s*/u', '$1', mb_strtolower(CatalogText::plain($part, 500))),
                array_slice($row, 1, 5),
            ));
            $duplicateVehicles += isset($vehicleRows[$vehicleFingerprint]) ? 1 : 0;
            $vehicleRows[$vehicleFingerprint] = true;
            $vehicleResolution = $this->identity->vehicle($row);
            $vehicleId = $vehicleResolution['generation_id'];
            $versionConflict = $vehicleId !== null && isset($versions[$vehicleId])
                && $versions[$vehicleId] !== $vehicleResolution['version'];
            if ($versionConflict) {
                foreach ($generationKeys[$vehicleId] ?? [] as $previousKey) {
                    $expected[$previousKey]['identity_conflict'] = true;
                    $expected[$previousKey]['state'] = 'unresolved';
                }
            }
            if ($vehicleId !== null) {
                $versions[$vehicleId] = $vehicleResolution['version'];
            }
            foreach ($paths as $column => $path) {
                if (CatalogImportCell::parse($row['_details'][$column] ?? null) === null) {
                    continue;
                }
                $resolution = $this->identity->resolve($row, $path, $source);
                $key = $resolution['import_key'];
                $fingerprint = $vehicleFingerprint.'|'.$resolution['version'];
                $conflict = $resolution['error'] !== null || $versionConflict
                    || (isset($vehicles[$key]) && $vehicles[$key] !== $fingerprint);
                if ($conflict) {
                    $diagnose(['code' => $resolution['code'] ?? 'identity_collision',
                        'message' => $resolution['error'] ?? 'Коллизия идентичностей или отдельных применимостей в строке '.$row['_row_number'].': '.$key,
                        'row' => $row['_row_number'], 'new_key' => $resolution['new_import_key'],
                        'candidate_vehicle_ids' => $resolution['candidate_ids'], 'old_candidates' => $resolution['historical_candidates'], 'vehicle' => array_slice($row, 1, 5), 'part_type_full_slug' => $path]);
                }
                $vehicles[$key] = $fingerprint;
                if ($vehicleId !== null) {
                    $generationKeys[$vehicleId][] = $key;
                }
                $duplicates += isset($expected[$key]) ? 1 : 0;
                if (! isset($expected[$key])) {
                    $expected[$key] = [
                        'import_key' => $key,
                        'part_type_full_slug' => $path,
                        'vehicle' => array_combine(['make', 'model', 'generation', 'years', 'body'], array_slice($row, 1, 5)),
                        'source_rows' => [],
                        'restyling' => $row['_restyling'] ?? '',
                        'historical_description_match' => $resolution['product_id'] !== null && ($row['_restyling'] ?? '') !== '',
                        'proposed_import_key' => $resolution['new_import_key'],
                        'identity_match' => $key === $resolution['new_import_key'] ? 'exact_key' : 'historical',
                    ];
                }
                $expected[$key]['source_rows'][] = $row['_row_number'];
                if ($conflict || ($expected[$key]['identity_conflict'] ?? false)) {
                    $expected[$key]['identity_conflict'] = true;
                    $expected[$key]['state'] = 'unresolved';
                }
            }
        }
        if ($expected === []) {
            throw new RuntimeException('В файле нет ни одного товара. Пустой снимок не применяется автоматически.');
        }
        foreach (array_chunk(array_keys($expected), 500) as $keys) {
            if (Product::withTrashed()->whereIn('import_key', $keys)
                ->where(fn ($query) => $query->whereNull('import_source')->orWhere('import_source', '!=', $source))->exists()) {
                throw new RuntimeException('Ожидаемый импортный ключ уже занят товаром другого источника или ручным товаром.');
            }
        }

        return ['products' => $expected, 'required_part_types' => array_values(array_unique($paths)),
            'accepted_rows' => $acceptedRows,
            'identity_errors' => $errors, 'identity_error_count' => $errorCount, 'identity_error_codes' => $errorCodes,
            'duplicate_vehicle_rows' => $duplicateVehicles, 'duplicate_positive_cells' => $duplicates];
    }
}

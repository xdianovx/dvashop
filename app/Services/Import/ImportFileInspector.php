<?php

namespace App\Services\Import;

use App\Support\CatalogText;
use App\Services\SpreadsheetReader;

class ImportFileInspector
{
    public function __construct(private readonly SpreadsheetReader $reader) {}

    /** @return array<string, mixed> */
    public function inspect(string $path): array
    {
        $headers = $this->reader->readMergedDetailHeaders($path);
        $dataRows = 0;
        $makes = [];
        $models = [];
        $generations = [];
        $filledDetailCells = 0;
        $vehicleImageUrls = 0;
        $vehicleImageAvailability = 0;
        $vehicleImageNonStandard = 0;
        $productImageUrls = 0;
        $uniqueProducts = [];
        $offset = 0;
        $limit = 500;

        do {
            $rows = $this->reader->readChunk($path, $offset, $limit, 2);

            foreach ($rows as $row) {
                $dataRows++;
                $make = $this->cell($row[1] ?? null);
                $model = $this->cell($row[2] ?? null);
                $generation = $this->cell($row[3] ?? null);
                $years = $this->cell($row[4] ?? null);
                $body = $this->cell($row[5] ?? null);

                if ($make !== '') {
                    $makes[CatalogText::normKey($make, 'make')] = $make;
                }

                if ($make !== '' && $model !== '') {
                    $models[CatalogText::normKey($make, 'make').':'.CatalogText::normKey($model, 'model')] = $model;
                }

                if ($make !== '' && $model !== '' && $generation !== '') {
                    $generations[implode(':', [
                        CatalogText::normKey($make, 'make'),
                        CatalogText::normKey($model, 'model'),
                        CatalogText::normKey(trim($generation.' '.$years.' '.$body), 'generation'),
                    ])] = true;
                }

                $vehicleImage = $this->cell($row[0] ?? null);
                if ($vehicleImage !== '') {
                    if ($this->isUrl($vehicleImage)) {
                        $vehicleImageUrls++;
                    } elseif ($this->isAvailability($vehicleImage)) {
                        $vehicleImageAvailability++;
                    } else {
                        $vehicleImageNonStandard++;
                    }
                }

                foreach ($headers as $columnIndex => $header) {
                    $value = $this->cell($row[$columnIndex] ?? null);

                    if (! $this->isProductPresent($value)) {
                        continue;
                    }

                    $filledDetailCells++;

                    if ($this->isUrl($value)) {
                        $productImageUrls++;
                    }

                    if ($make !== '' && $model !== '' && $generation !== '') {
                        $uniqueProducts[implode(':', [
                            CatalogText::normKey($make, 'make'),
                            CatalogText::normKey($model, 'model'),
                            CatalogText::normKey(trim($generation.' '.$years.' '.$body), 'generation'),
                            CatalogText::normKey($header['group'] ?? '', 'root'),
                            CatalogText::normKey($header['category_title'] ?? $header['title'] ?? '', 'category'),
                        ])] = true;
                    }
                }
            }

            $offset += $limit;
        } while ($rows !== []);

        return [
            'file' => basename($path),
            'sheet' => SpreadsheetReader::CATALOG_SHEET_NAME,
            'data_rows' => $dataRows,
            'makes' => count($makes),
            'models' => count($models),
            'generations' => count($generations),
            'filled_detail_cells' => $filledDetailCells,
            'unique_products' => count($uniqueProducts),
            'vehicle_image_urls' => $vehicleImageUrls,
            'vehicle_image_availability_values' => $vehicleImageAvailability,
            'vehicle_image_non_standard_values' => $vehicleImageNonStandard,
            'product_image_urls' => $productImageUrls,
            'headers' => $headers,
            'category_tree' => $this->categoryTree($headers),
            'penka_leak_detected' => $this->penkaLeakDetected($headers),
        ];
    }

    /** @param array<int, array{group:string|null, category_title:string, title:string}> $headers */
    private function categoryTree(array $headers): array
    {
        $tree = [];

        foreach ($headers as $header) {
            $group = $this->cell($header['group'] ?? null);
            $title = $this->cell($header['category_title'] ?? $header['title'] ?? null);

            if ($title === '') {
                continue;
            }

            if ($group === '' || $group === $title) {
                $tree[$title] ??= [];
                continue;
            }

            $tree[$group] ??= [];
            $tree[$group][] = $title;
        }

        foreach ($tree as $group => $children) {
            $tree[$group] = array_values(array_unique($children));
        }

        return $tree;
    }

    /** @param array<int, array{group:string|null, category_title:string, title:string}> $headers */
    private function penkaLeakDetected(array $headers): bool
    {
        foreach (['Лонжерон', 'Торцевая заглушка', 'Ремкомплект пола', 'Усилитель / соединитель порогов'] as $title) {
            foreach ($headers as $header) {
                if (($header['category_title'] ?? null) === $title && ($header['group'] ?? null) === 'Пенка') {
                    return true;
                }
            }
        }

        return false;
    }

    private function isProductPresent(string $value): bool
    {
        $normalized = mb_strtolower(str_replace(',', '.', $value));

        if ($normalized === '' || $normalized === '-' || in_array($normalized, ['нет', 'no', 'false'], true) || preg_match('/^0(?:\.0+)?$/', $normalized) === 1) {
            return false;
        }

        return true;
    }

    private function isAvailability(string $value): bool
    {
        $normalized = mb_strtolower(str_replace(',', '.', $value));

        return in_array($normalized, ['да', 'yes', 'true'], true) || preg_match('/^1(?:\.0+)?$/', $normalized) === 1;
    }

    private function isUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    private function cell(mixed $value): string
    {
        return CatalogText::plain($value, 1000);
    }
}

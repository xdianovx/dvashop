<?php

namespace App\Services\Import;

use App\Services\SpreadsheetReader;
use App\Support\CatalogText;

class ImportFileInspector
{
    public function __construct(private readonly SpreadsheetReader $reader) {}

    /** @return array<string, mixed> */
    public function inspect(string $path): array
    {
        $headers = $this->reader->readMergedDetailHeaders($path);
        $sourceHeaders = $this->reader->readHeaderRows($path, 2);
        CatalogImportFormat::validateHeaders($sourceHeaders);
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

            foreach ($rows as $index => $raw) {
                $row = CatalogImportFormat::normalize($raw, $sourceHeaders);
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
                    $value = $this->cell($row['_details'][$columnIndex] ?? null);

                    if (CatalogImportCell::parse($row['_details'][$columnIndex] ?? null) === null) {
                        continue;
                    }

                    $filledDetailCells++;

                    if ($this->isUrl($value)) {
                        $productImageUrls++;
                    }

                    if ($make !== '' && $model !== '' && $generation !== '') {
                        $uniqueProducts[CatalogImportIdentity::fromSegments('catalog',
                            CatalogText::normKey($make, 'make'), CatalogText::normKey($model, 'model'),
                            CatalogText::normKey(trim($generation.' '.$years.' '.$body), 'generation'),
                            $header['structural_path'])] = true;
                    }
                }
            }

            $offset += $limit;
        } while ($rows !== []);

        return [
            'file' => basename($path),
            'sheet' => $this->reader->sheetName($path),
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
        ];
    }

    /** @param array<int, array{group:string|null, parent_title?:string|null, category_title:string, title:string, detail_title?:string, full_detail_title?:string}> $headers */
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

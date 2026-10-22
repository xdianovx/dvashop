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
        $skipped = [];
        $normalizedRows = [];
        $identity = app(CatalogImportIdentity::class);
        $identity->loadExisting();
        foreach ($this->reader->catalogRows($path) as $entry) {
            if ($entry['row'] === null) {
                $skipped[] = ['row' => $entry['number'], 'reason' => $entry['reason']];
                continue;
            }
            $row = $entry['row'];
            $normalizedRows[] = $row + ['_row_number' => $entry['number']];
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
                $resolved = $identity->vehicle($row);
                if ($resolved['error'] !== null) {
                    throw new \RuntimeException($resolved['error']);
                }
                $generations[implode(':', [$resolved['make'], $resolved['model'], $resolved['generation']])] = true;
            }

            $vehicleImageUrls += $row['_photo_urls'] !== [] ? 1 : 0;

            foreach ($headers as $columnIndex => $header) {
                if (CatalogImportCell::parse($row['_details'][$columnIndex] ?? null) === null) {
                    continue;
                }

                $filledDetailCells++;

            }
        }
        $calculation = app(CatalogImportExpectedProducts::class)->collect($normalizedRows, $headers, 'catalog');

        return [
            'file' => basename($path),
            'sheet' => $this->reader->sheetName($path),
            'data_rows' => $dataRows,
            'skipped_rows' => $skipped,
            'makes' => count($makes),
            'models' => count($models),
            'generations' => count($generations),
            'filled_detail_cells' => $filledDetailCells,
            'unique_products' => count($calculation['products']),
            'vehicle_image_urls' => $vehicleImageUrls,
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

    private function cell(mixed $value): string
    {
        return CatalogText::plain($value, 1000);
    }
}

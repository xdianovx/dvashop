<?php

namespace App\Services;

use App\Services\Import\CatalogImportHeaderParser;
use App\Support\CatalogText;
use InvalidArgumentException;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use SplFileObject;

class SpreadsheetReader
{
    public const CATALOG_SHEET_NAME = 'Каталог';

    public int $headerRows = 1;

    public function supports(string $path): bool
    {
        return in_array($this->extension($path), ['csv', 'xlsx'], true);
    }

    public function countRows(string $path, ?int $headerRows = null): int
    {
        $this->ensureSupported($path);

        $headerRows ??= $this->headerRows;
        $rows = 0;

        foreach ($this->iterateRows($path) as $rowIndex => $row) {
            if ($rowIndex <= $headerRows) {
                continue;
            }

            if ($this->isEmptyRow($row)) {
                continue;
            }

            $rows++;
        }

        return $rows;
    }

    public function readHeader(string $path): array
    {
        $this->ensureSupported($path);

        foreach ($this->iterateRows($path) as $rowIndex => $row) {
            if ($rowIndex === 1) {
                return $this->normalizeRow($row);
            }
        }

        return [];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function readHeaderRows(string $path, int $rows = 2): array
    {
        $this->ensureSupported($path);

        $headers = [];

        foreach ($this->iterateRows($path) as $rowIndex => $row) {
            if ($rowIndex > $rows) {
                break;
            }

            $headers[$rowIndex] = $this->normalizeRow($row);
        }

        return $headers;
    }

    /**
     * @return array<int, array{index:int, group:string|null, parent_title?:string|null, title:string, detail_title?:string, full_detail_title?:string, category_title:string}>
     */
    public function readMergedDetailHeaders(string $path, int $detailStartColumn = 6): array
    {
        $this->ensureSupported($path);

        return app(CatalogImportHeaderParser::class)->parse(
            path: $path,
            detailStartColumn: $detailStartColumn,
            headerRows: $this->readHeaderRows($path, 2),
            extension: $this->extension($path),
        );
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function readChunk(string $path, int $offset, int $limit, ?int $headerRows = null): array
    {
        $this->ensureSupported($path);

        if ($limit <= 0) {
            return [];
        }

        $headerRows ??= $this->headerRows;
        $rows = [];
        $dataIndex = 0;

        foreach ($this->iterateRows($path) as $rowIndex => $row) {
            if ($rowIndex <= $headerRows || $this->isEmptyRow($row)) {
                continue;
            }

            if ($dataIndex < $offset) {
                $dataIndex++;
                continue;
            }

            $rows[] = $this->normalizeRow($row);
            $dataIndex++;

            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @return iterable<int, array<int, mixed>> 1-based row index => row cells
     */
    private function iterateRows(string $path): iterable
    {
        return match ($this->extension($path)) {
            'csv' => $this->iterateCsvRows($path),
            'xlsx' => $this->iterateXlsxRows($path),
            default => throw new InvalidArgumentException('Unsupported spreadsheet type.'),
        };
    }

    /** @return iterable<int, array<int, mixed>> */
    private function iterateCsvRows(string $path): iterable
    {
        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        $file->setCsvControl($this->detectDelimiter($path));

        $rowIndex = 0;
        foreach ($file as $row) {
            if ($row === false || $row === [null]) {
                continue;
            }

            $rowIndex++;
            yield $rowIndex => $row;
        }
    }

    /** @return iterable<int, array<int, mixed>> */
    private function iterateXlsxRows(string $path): iterable
    {
        $reader = new XlsxReader();
        $reader->open($path);

        try {
            $fallbackRows = null;

            foreach ($reader->getSheetIterator() as $sheet) {
                $sheetName = method_exists($sheet, 'getName') ? $sheet->getName() : null;

                if ($sheetName === self::CATALOG_SHEET_NAME) {
                    $rowIndex = 0;
                    foreach ($sheet->getRowIterator() as $row) {
                        $rowIndex++;
                        yield $rowIndex => $row->toArray();
                    }

                    return;
                }

                if ($fallbackRows === null) {
                    $fallbackRows = [];
                    $rowIndex = 0;

                    foreach ($sheet->getRowIterator() as $row) {
                        $rowIndex++;
                        $fallbackRows[$rowIndex] = $row->toArray();
                    }
                }
            }

            foreach (($fallbackRows ?? []) as $index => $row) {
                yield $index => $row;
            }
        } finally {
            $reader->close();
        }
    }

    private function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'rb');
        $line = $handle ? (string) fgets($handle) : '';

        if (is_resource($handle)) {
            fclose($handle);
        }

        return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
    }

    private function normalizeRow(array $row): array
    {
        if (isset($row[0]) && is_string($row[0])) {
            $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
        }

        return array_map(static function (mixed $value): mixed {
            if (is_string($value)) {
                return CatalogText::plain($value, 1000);
            }

            return $value;
        }, $row);
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function extension(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    private function ensureSupported(string $path): void
    {
        if (! $this->supports($path)) {
            throw new InvalidArgumentException('Supported import file types: csv, xlsx.');
        }
    }
}

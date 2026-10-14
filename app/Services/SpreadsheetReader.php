<?php

namespace App\Services;

use App\Services\Import\CatalogImportCell;
use App\Services\Import\CatalogImportFormat;
use App\Services\Import\CatalogImportHeaderParser;
use App\Support\CatalogText;
use InvalidArgumentException;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;
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

        $headerRows = $this->readHeaderRows($path, 2);

        return app(CatalogImportHeaderParser::class)->parse(
            path: $path,
            detailStartColumn: $detailStartColumn,
            headerRows: $headerRows,
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
        $headers = $this->readHeaderRows($path, 2);

        foreach ($this->iterateRows($path) as $rowIndex => $row) {
            if ($rowIndex <= $headerRows || $this->isEmptyRow($row)) {
                continue;
            }

            if ($dataIndex < $offset) {
                $dataIndex++;

                continue;
            }

            // readChunk is also a generic spreadsheet inspection API. Queued catalog
            // imports always use the fully validated prepareRows path below.
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
        $options = new XlsxOptions;
        $options->SHOULD_FORMAT_DATES = true;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;

        $reader = new XlsxReader($options);
        $reader->open($path);

        try {
            $sheets = iterator_to_array($reader->getSheetIterator());
            $selected = CatalogImportFormat::sheetName(array_map(fn ($sheet) => $sheet->getName(), $sheets));
            foreach ($sheets as $sheet) {
                if ($sheet->getName() !== $selected) {
                    continue;
                }
                foreach ($sheet->getRowIterator() as $index => $row) {
                    yield $index => $row->toArray();
                }
                break;
            }
        } finally {
            $reader->close();
        }
    }

    /** @return array{path:string, offsets:array<int,int>, snapshot_version:int, sheet:string} */
    public function prepareRows(string $path): array
    {
        $sheetName = $this->sheetName($path);
        $details = $this->readMergedDetailHeaders($path);
        $destination = $path.'.rows.jsonl';
        $stream = fopen($destination, 'wb');
        if ($stream === false) {
            throw new RuntimeException('Не удалось подготовить строки импорта.');
        }
        $headers = [];
        $offsets = [];
        try {
            foreach ($this->iterateRows($path) as $number => $row) {
                if ($number <= 2) {
                    $headers[$number] = $this->normalizeRow($row);
                    if ($number === 2) {

                        CatalogImportFormat::validateHeaders($headers);
                    }

                    continue;
                }
                if ($this->isEmptyRow($row)) {
                    continue;
                }
                try {
                    $raw = $this->normalizeRow($row);
                    $row = CatalogImportFormat::normalize($raw, $headers);
                    foreach ($raw as $index => $value) {
                        if (CatalogText::plain($value) !== '' && ! isset($details[$index])
                            && ! CatalogImportFormat::isMetadata(CatalogText::plain($headers[1][$index] ?? ''))
                            && ! CatalogImportFormat::isMetadata(CatalogText::plain($headers[2][$index] ?? ''))) {
                            throw new InvalidArgumentException('Заполненная колонка без заголовка: '.CatalogImportCell::column($index).$number);
                        }
                    }
                } catch (InvalidArgumentException $e) {
                    throw new InvalidArgumentException('Строка '.$number.': '.$e->getMessage(), previous: $e);
                }
                $row['_row_number'] = $number;
                $offsets[] = ftell($stream);
                $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
                if (fwrite($stream, $line) !== strlen($line)) {
                    throw new RuntimeException('Не удалось сохранить подготовленные строки импорта.');
                }
            }
        } finally {
            fclose($stream);
        }

        return ['path' => $destination, 'offsets' => $offsets, 'snapshot_version' => 1, 'sheet' => $sheetName];
    }

    public function readPreparedChunk(array $prepared, int $offset, int $limit): array
    {
        if (! isset($prepared['offsets'][$offset])) {
            return [];
        }
        $stream = fopen($prepared['path'], 'rb');
        if ($stream === false) {
            throw new RuntimeException('Подготовленный файл импорта отсутствует.');
        }
        try {
            if (fseek($stream, $prepared['offsets'][$offset]) !== 0) {
                throw new RuntimeException('Не удалось прочитать подготовленные строки импорта.');
            }
            $rows = [];
            while (count($rows) < $limit && ($line = fgets($stream)) !== false) {
                $rows[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
            if (count($rows) !== min($limit, count($prepared['offsets']) - $offset)) {
                throw new RuntimeException('Подготовленный файл импорта повреждён или обрезан.');
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    public function sheetName(string $path): string
    {
        if ($this->extension($path) !== 'xlsx') {
            return self::CATALOG_SHEET_NAME;
        }
        $reader = new XlsxReader;
        $reader->open($path);
        try {
            $names = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                $names[] = $sheet->getName();
            }

            return CatalogImportFormat::sheetName($names);
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

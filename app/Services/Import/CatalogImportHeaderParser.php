<?php

namespace App\Services\Import;

use App\Support\CatalogText;
use ZipArchive;

class CatalogImportHeaderParser
{
    public const CATALOG_SHEET_NAME = 'Каталог';

    /**
     * @param array<int, array<int, mixed>> $headerRows
     * @return array<int, array{index:int, group:string|null, parent_title:string|null, title:string, detail_title:string, full_detail_title:string, category_title:string}>
     */
    public function parse(string $path, int $detailStartColumn, array $headerRows, string $extension): array
    {
        return $extension === 'xlsx'
            ? $this->parseXlsx($path, $detailStartColumn, $headerRows)
            : $this->parseCsvFallback($detailStartColumn, $headerRows);
    }

    /**
     * @param array<int, array<int, mixed>> $headerRows
     * @return array<int, array{index:int, group:string|null, parent_title:string|null, title:string, detail_title:string, full_detail_title:string, category_title:string}>
     */
    private function parseXlsx(string $path, int $detailStartColumn, array $headerRows): array
    {
        $groups = $headerRows[1] ?? [];
        $titles = $headerRows[2] ?? [];
        $mergedGroups = $this->mergedGroupsForFirstRow($path, $groups);
        $maxMergedColumn = $mergedGroups === [] ? 0 : max(array_keys($mergedGroups));
        $maxColumn = max(array_key_last($groups) ?? 0, array_key_last($titles) ?? 0, $maxMergedColumn);
        $headers = [];

        for ($index = $detailStartColumn; $index <= $maxColumn; $index++) {
            $directGroup = $this->cellString($groups[$index] ?? null);
            $title = $this->cellString($titles[$index] ?? null);
            $mergedGroup = $mergedGroups[$index] ?? null;

            if ($mergedGroup !== null && $mergedGroup !== '') {
                $categoryTitle = $title !== '' ? $title : $mergedGroup;
                $headers[$index] = $this->detailHeader(
                    index: $index,
                    parentTitle: $mergedGroup,
                    detailTitle: $title !== '' ? $title : $categoryTitle,
                    categoryTitle: $categoryTitle,
                );

                continue;
            }

            if ($directGroup === '' && $title === '') {
                continue;
            }

            if ($directGroup !== '' && $title !== '') {
                $headers[$index] = $this->detailHeader(
                    index: $index,
                    parentTitle: $directGroup,
                    detailTitle: $title,
                    categoryTitle: $title,
                );

                continue;
            }

            $categoryTitle = $title !== '' ? $title : $directGroup;

            $headers[$index] = $this->detailHeader(
                index: $index,
                parentTitle: null,
                detailTitle: $categoryTitle,
                categoryTitle: $categoryTitle,
            );
        }

        return $headers;
    }

    /**
     * CSV does not have real merged ranges, so keep previous compatibility behaviour:
     * a non-empty group cell is carried until the next non-empty group cell.
     *
     * @param array<int, array<int, mixed>> $headerRows
     * @return array<int, array{index:int, group:string|null, parent_title:string|null, title:string, detail_title:string, full_detail_title:string, category_title:string}>
     */
    private function parseCsvFallback(int $detailStartColumn, array $headerRows): array
    {
        $groups = $headerRows[1] ?? [];
        $titles = $headerRows[2] ?? [];
        $maxColumn = max(array_key_last($groups) ?? 0, array_key_last($titles) ?? 0);
        $headers = [];
        $currentGroup = null;

        for ($index = $detailStartColumn; $index <= $maxColumn; $index++) {
            $groupCell = $this->cellString($groups[$index] ?? null);
            $title = $this->cellString($titles[$index] ?? null);

            if ($groupCell !== '') {
                $currentGroup = $groupCell;
            }

            if ($groupCell === '' && $title === '') {
                continue;
            }

            $categoryTitle = $title !== '' ? $title : ($groupCell ?: (string) $currentGroup);

            $headers[$index] = $this->detailHeader(
                index: $index,
                parentTitle: $currentGroup,
                detailTitle: $title !== '' ? $title : $categoryTitle,
                categoryTitle: $categoryTitle,
            );
        }

        return $headers;
    }

    /**
     * @param array<int, mixed> $firstRow
     * @return array<int, string>
     */
    private function mergedGroupsForFirstRow(string $path, array $firstRow): array
    {
        $worksheetPath = $this->worksheetPath($path);

        if ($worksheetPath === null) {
            return [];
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }

        try {
            $xml = $zip->getFromName($worksheetPath);
        } finally {
            $zip->close();
        }

        if (! is_string($xml) || $xml === '') {
            return [];
        }

        preg_match_all('/<mergeCell\b[^>]*\bref="([^"]+)"/i', $xml, $matches);

        $groups = [];

        foreach ($matches[1] ?? [] as $rangeRef) {
            $range = $this->rangeCoordinates($rangeRef);

            if ($range === null) {
                continue;
            }

            [$startColumn, $startRow, $endColumn, $endRow] = $range;

            if ($startRow > 1 || $endRow < 1) {
                continue;
            }

            $groupTitle = $this->cellString($firstRow[$startColumn] ?? null);

            if ($groupTitle === '') {
                continue;
            }

            for ($column = $startColumn; $column <= $endColumn; $column++) {
                $groups[$column] = $groupTitle;
            }
        }

        return $groups;
    }

    private function worksheetPath(string $path): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }

        try {
            $workbookXml = $zip->getFromName('xl/workbook.xml');
            $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        } finally {
            $zip->close();
        }

        if (! is_string($workbookXml) || ! is_string($relsXml)) {
            return 'xl/worksheets/sheet1.xml';
        }

        $rels = $this->workbookRelationships($relsXml);
        $sheets = $this->workbookSheets($workbookXml);

        if ($sheets === []) {
            return 'xl/worksheets/sheet1.xml';
        }

        $selected = $sheets[0];

        foreach ($sheets as $sheet) {
            if (($sheet['name'] ?? null) === self::CATALOG_SHEET_NAME) {
                $selected = $sheet;
                break;
            }
        }

        $target = $rels[$selected['relationship_id'] ?? ''] ?? null;

        if (! is_string($target) || $target === '') {
            return 'xl/worksheets/sheet1.xml';
        }

        $target = str_replace('\\', '/', ltrim($target, '/'));

        return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
    }

    /** @return array<string, string> */
    private function workbookRelationships(string $relsXml): array
    {
        $relationships = [];

        preg_match_all('/<Relationship\b([^>]*)\/?>/i', $relsXml, $matches);

        foreach ($matches[1] ?? [] as $attributes) {
            $id = $this->xmlAttribute($attributes, 'Id');
            $target = $this->xmlAttribute($attributes, 'Target');

            if ($id !== null && $target !== null) {
                $relationships[$id] = $target;
            }
        }

        return $relationships;
    }

    /** @return array<int, array{name:string, relationship_id:string}> */
    private function workbookSheets(string $workbookXml): array
    {
        $sheets = [];

        preg_match_all('/<sheet\b([^>]*)\/?>/i', $workbookXml, $matches);

        foreach ($matches[1] ?? [] as $attributes) {
            $name = $this->xmlAttribute($attributes, 'name');
            $relationshipId = $this->xmlAttribute($attributes, 'r:id');

            if ($name !== null && $relationshipId !== null) {
                $sheets[] = [
                    'name' => html_entity_decode($name, ENT_QUOTES | ENT_XML1, 'UTF-8'),
                    'relationship_id' => $relationshipId,
                ];
            }
        }

        return $sheets;
    }

    private function xmlAttribute(string $attributes, string $name): ?string
    {
        $quotedName = preg_quote($name, '/');

        if (preg_match('/\b'.$quotedName.'="([^"]*)"/i', $attributes, $match) === 1) {
            return html_entity_decode($match[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        return null;
    }

    /** @return array{int, int, int, int}|null */
    private function rangeCoordinates(string $rangeRef): ?array
    {
        [$start, $end] = array_pad(explode(':', $rangeRef, 2), 2, null);
        $end ??= $start;

        $startCoordinate = $this->coordinate($start);
        $endCoordinate = $this->coordinate($end);

        if ($startCoordinate === null || $endCoordinate === null) {
            return null;
        }

        return [
            min($startCoordinate[0], $endCoordinate[0]),
            min($startCoordinate[1], $endCoordinate[1]),
            max($startCoordinate[0], $endCoordinate[0]),
            max($startCoordinate[1], $endCoordinate[1]),
        ];
    }

    /** @return array{int, int}|null zero-based column, one-based row */
    private function coordinate(string $cellRef): ?array
    {
        if (preg_match('/^([A-Z]+)(\d+)$/i', strtoupper($cellRef), $match) !== 1) {
            return null;
        }

        $column = 0;
        foreach (str_split($match[1]) as $letter) {
            $column = ($column * 26) + (ord($letter) - 64);
        }

        return [$column - 1, (int) $match[2]];
    }


    /**
     * @return array{index:int, group:string|null, parent_title:string|null, title:string, detail_title:string, full_detail_title:string, category_title:string}
     */
    private function detailHeader(int $index, ?string $parentTitle, string $detailTitle, string $categoryTitle): array
    {
        $parentTitle = $this->cellString($parentTitle);
        $detailTitle = $this->cellString($detailTitle);
        $categoryTitle = $this->cellString($categoryTitle !== '' ? $categoryTitle : $detailTitle);
        $parentTitle = $parentTitle !== '' && $parentTitle !== $categoryTitle ? $parentTitle : null;

        return [
            'index' => $index,
            'group' => $parentTitle,
            'parent_title' => $parentTitle,
            'title' => $detailTitle,
            'detail_title' => $detailTitle,
            'full_detail_title' => $this->fullDetailTitle($parentTitle, $detailTitle !== '' ? $detailTitle : $categoryTitle),
            'category_title' => $categoryTitle,
        ];
    }

    private function fullDetailTitle(?string $parentTitle, string $detailTitle): string
    {
        $parentTitle = $this->cellString($parentTitle);
        $detailTitle = $this->cellString($detailTitle);

        if ($parentTitle === '' || $parentTitle === $detailTitle) {
            return $detailTitle;
        }

        return CatalogText::plain($parentTitle.' '.$this->lowerFirst($detailTitle), 250);
    }

    private function lowerFirst(string $value): string
    {
        $value = $this->cellString($value);

        if ($value === '') {
            return '';
        }

        return mb_strtolower(mb_substr($value, 0, 1)).mb_substr($value, 1);
    }

    private function cellString(mixed $value): string
    {
        return CatalogText::plain($value, 250);
    }
}

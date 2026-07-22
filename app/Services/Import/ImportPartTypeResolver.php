<?php

namespace App\Services\Import;

use App\Enums\ImportLogLevel;
use App\Models\ImportLog;
use App\Models\ImportRun;
use App\Models\PartType;
use App\Services\Catalog\PartTypeCategoryResolver;
use App\Services\Catalog\PartTypeDefinitionRegistry;
use App\Services\Catalog\PartTypeTreeService;
use App\Services\ImportLogger;
use App\Support\CatalogText;
use InvalidArgumentException;

class ImportPartTypeResolver
{
    /** @var array<string, PartType> */
    private array $partTypes = [];

    /** @var array<string, bool> */
    private array $fallbackWarnings = [];

    /** @var array<string, array{full_slug:string,parent_full_slug:?string,title:string,position:int,default_image_key:?string}>|null */
    private ?array $definitions = null;

    /** @var array<string, array<int, string>>|null */
    private ?array $knownPathsByTitle = null;

    public function __construct(
        private readonly PartTypeTreeService $tree,
        private readonly PartTypeDefinitionRegistry $registry,
        private readonly PartTypeCategoryResolver $categories,
        private readonly ImportLogger $logger,
    ) {}

    /**
     * @param array{parent_title?:string|null,group?:string|null,detail_title?:string|null,title?:string|null,full_detail_title?:string|null,category_title?:string|null} $detailHeader
     */
    public function resolve(ImportRun $run, array $detailHeader, ?int $columnIndex = null): ImportPartTypeResolution
    {
        $segments = $this->pathSegments($detailHeader);

        if ($segments === []) {
            throw new InvalidArgumentException('Не удалось определить тип детали по заголовку Excel.');
        }

        $parent = null;
        $finalCategoryResolution = null;
        $finalCreated = false;
        $finalRestored = false;
        $pathSlugs = [];

        foreach ($segments as $index => $segment) {
            $pathSlugs[] = $this->tree->slugForTitle($segment);
            $fullSlug = implode('/', $pathSlugs);
            $definition = $this->definitions()[$fullSlug] ?? null;

            [$partType, $wasCreated, $wasRestored] = $this->ensurePartType(
                fullSlug: $fullSlug,
                title: $definition['title'] ?? $segment,
                parent: $parent,
                position: $definition['position'] ?? 0,
                defaultImageKey: $definition['default_image_key'] ?? null,
            );

            if ($wasCreated) {
                $this->logger->info($run, 'Создан новый тип детали из заголовка импорта', [
                    'part_type_id' => $partType->getKey(),
                    'part_type_full_slug' => $partType->full_slug,
                    'part_type_full_title' => $partType->full_title,
                    'column' => $columnIndex === null ? null : $this->columnName($columnIndex),
                    'column_index' => $columnIndex,
                ]);
            } elseif ($wasRestored) {
                $this->logger->info($run, 'Восстановлен ранее удалённый тип детали', [
                    'part_type_id' => $partType->getKey(),
                    'part_type_full_slug' => $partType->full_slug,
                    'part_type_full_title' => $partType->full_title,
                    'column' => $columnIndex === null ? null : $this->columnName($columnIndex),
                    'column_index' => $columnIndex,
                ]);
            }

            $categoryResolution = $this->categories->resolve($partType);

            if ((int) $partType->product_category_id !== (int) $categoryResolution->category->getKey()) {
                $partType->forceFill([
                    'product_category_id' => $categoryResolution->category->getKey(),
                ])->saveQuietly();
            }

            $partType->setRelation('productCategory', $categoryResolution->category);

            if ($categoryResolution->usedFallback) {
                $this->warnFallbackOnce($run, $partType, $categoryResolution->category->full_title, $columnIndex);
            }

            if ($index === array_key_last($segments)) {
                $finalCategoryResolution = $categoryResolution;
                $finalCreated = $wasCreated;
                $finalRestored = $wasRestored;
            }

            $parent = $partType;
        }

        if (! $parent instanceof PartType || $finalCategoryResolution === null) {
            throw new InvalidArgumentException('Не удалось определить тип детали по заголовку Excel.');
        }

        return new ImportPartTypeResolution(
            partType: $parent,
            productCategory: $finalCategoryResolution->category,
            usedFallback: $finalCategoryResolution->usedFallback,
            wasCreated: $finalCreated,
            wasRestored: $finalRestored,
        );
    }

    /**
     * @return array{0:PartType,1:bool,2:bool}
     */
    private function ensurePartType(
        string $fullSlug,
        string $title,
        ?PartType $parent,
        int $position,
        ?string $defaultImageKey,
    ): array {
        if (isset($this->partTypes[$fullSlug])) {
            return [$this->partTypes[$fullSlug], false, false];
        }

        $partType = PartType::withTrashed()->where('full_slug', $fullSlug)->first();
        $wasCreated = ! $partType instanceof PartType;
        $wasRestored = $partType instanceof PartType && $partType->trashed();

        if (! $partType instanceof PartType) {
            $partType = new PartType;
            $partType->forceFill([
                'parent_id' => $parent?->getKey(),
                'title' => CatalogText::plain($title, 255),
                'position' => $position,
                'is_active' => true,
                'default_image_key' => $defaultImageKey,
            ]);
            $this->tree->save($partType);
        } else {
            if ($partType->trashed()) {
                $partType->restoreQuietly();
            }

            $updates = [];

            if (! $partType->is_active) {
                $updates['is_active'] = true;
            }

            if (($partType->default_image_key === null || $partType->default_image_key === '') && $defaultImageKey !== null) {
                $updates['default_image_key'] = $defaultImageKey;
            }

            if ($updates !== []) {
                $partType->forceFill($updates)->saveQuietly();
            }
        }

        return [$this->partTypes[$fullSlug] = $partType->refresh(), $wasCreated, $wasRestored];
    }

    /**
     * @param array{parent_title?:string|null,group?:string|null,detail_title?:string|null,title?:string|null,full_detail_title?:string|null,category_title?:string|null} $detailHeader
     * @return array<int, string>
     */
    private function pathSegments(array $detailHeader): array
    {
        $parentTitle = $this->text($detailHeader['parent_title'] ?? $detailHeader['group'] ?? null);
        $detailTitle = $this->text(
            $detailHeader['detail_title']
                ?? $detailHeader['title']
                ?? $detailHeader['category_title']
                ?? null
        );

        if ($parentTitle !== '') {
            $parentSegments = $this->splitPath($parentTitle);
            $detailSegments = $this->splitPath($detailTitle);

            if ($detailSegments === [] || $this->samePath($parentSegments, $detailSegments)) {
                return $parentSegments;
            }

            while ($parentSegments !== [] && $detailSegments !== [] && $this->sameSegment(end($parentSegments), $detailSegments[0])) {
                array_shift($detailSegments);
            }

            return array_values(array_filter([...$parentSegments, ...$detailSegments]));
        }

        $candidate = $detailTitle !== ''
            ? $detailTitle
            : $this->text($detailHeader['category_title'] ?? $detailHeader['full_detail_title'] ?? null);
        $segments = $this->splitPath($candidate);

        if (count($segments) > 1) {
            return $segments;
        }

        $fullDetailTitle = $this->normalizedLookupTitle($detailHeader['full_detail_title'] ?? null);

        if ($fullDetailTitle !== '' && isset($this->knownPathsByTitle()[$fullDetailTitle])) {
            return $this->knownPathsByTitle()[$fullDetailTitle];
        }

        return $segments;
    }

    /** @return array<int, string> */
    private function splitPath(string $value): array
    {
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $segment): string => $this->text($segment),
            preg_split('#\s*/\s*#u', $value) ?: [],
        ), static fn (string $segment): bool => $segment !== ''));
    }

    /** @param array<int, string> $left @param array<int, string> $right */
    private function samePath(array $left, array $right): bool
    {
        if (count($left) !== count($right)) {
            return false;
        }

        foreach ($left as $index => $segment) {
            if (! $this->sameSegment($segment, $right[$index])) {
                return false;
            }
        }

        return true;
    }

    private function sameSegment(string $left, string $right): bool
    {
        return $this->tree->slugForTitle($left) === $this->tree->slugForTitle($right);
    }

    /**
     * @return array<string, array{full_slug:string,parent_full_slug:?string,title:string,position:int,default_image_key:?string}>
     */
    private function definitions(): array
    {
        return $this->definitions ??= $this->registry->flattened($this->tree);
    }

    /** @return array<string, array<int, string>> */
    private function knownPathsByTitle(): array
    {
        if ($this->knownPathsByTitle !== null) {
            return $this->knownPathsByTitle;
        }

        $paths = [];

        foreach ($this->definitions() as $definition) {
            $segments = [];
            $current = $definition;

            while (true) {
                array_unshift($segments, $current['title']);
                $parentSlug = $current['parent_full_slug'];

                if ($parentSlug === null || ! isset($this->definitions()[$parentSlug])) {
                    break;
                }

                $current = $this->definitions()[$parentSlug];
            }

            $productTitle = implode(' ', array_map(
                fn (string $segment, int $index): string => $index === 0 ? $segment : $this->lowerFirst($segment),
                $segments,
                array_keys($segments),
            ));

            $paths[$this->normalizedLookupTitle($productTitle)] = $segments;
            $paths[$this->normalizedLookupTitle(implode(' / ', $segments))] = $segments;
        }

        return $this->knownPathsByTitle = $paths;
    }

    private function warnFallbackOnce(ImportRun $run, PartType $partType, string $storeCategory, ?int $columnIndex): void
    {
        $key = $run->getKey().':'.$partType->getKey();

        if (isset($this->fallbackWarnings[$key])) {
            return;
        }

        $alreadyLogged = ImportLog::query()
            ->where('import_run_id', $run->getKey())
            ->where('level', ImportLogLevel::Warning->value)
            ->where('message', 'Для нового типа детали использована резервная категория магазина')
            ->where('context->part_type_id', $partType->getKey())
            ->exists();

        $this->fallbackWarnings[$key] = true;

        if ($alreadyLogged) {
            return;
        }

        $this->logger->warning($run, 'Для нового типа детали использована резервная категория магазина', [
            'part_type_id' => $partType->getKey(),
            'part_type' => $partType->full_title,
            'part_type_full_slug' => $partType->full_slug,
            'store_category' => $storeCategory,
            'column' => $columnIndex === null ? null : $this->columnName($columnIndex),
            'column_index' => $columnIndex,
        ]);
    }

    private function normalizedLookupTitle(mixed $value): string
    {
        return mb_strtolower($this->text($value));
    }

    private function lowerFirst(string $value): string
    {
        $value = $this->text($value);

        if ($value === '') {
            return '';
        }

        return mb_strtolower(mb_substr($value, 0, 1)).mb_substr($value, 1);
    }

    private function text(mixed $value): string
    {
        return CatalogText::plain($value, 255);
    }

    private function columnName(int $zeroBasedIndex): string
    {
        $number = $zeroBasedIndex + 1;
        $name = '';

        while ($number > 0) {
            $remainder = ($number - 1) % 26;
            $name = chr(65 + $remainder).$name;
            $number = intdiv($number - 1, 26);
        }

        return $name;
    }
}

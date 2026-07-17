<?php

namespace Database\Seeders;

use App\Models\PartType;
use App\Services\Catalog\PartTypeCategoryResolver;
use App\Services\Catalog\PartTypeDefinitionRegistry;
use App\Services\Catalog\PartTypeTreeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PartTypeSeeder extends Seeder
{
    public function run(
        PartTypeTreeService $tree,
        PartTypeCategoryResolver $resolver,
        PartTypeDefinitionRegistry $registry,
    ): void {
        DB::transaction(function () use ($tree, $resolver, $registry): void {
            $partTypes = [];

            foreach ($registry->flattened($tree) as $definition) {
                $parent = $definition['parent_full_slug'] !== null
                    ? ($partTypes[$definition['parent_full_slug']] ?? null)
                    : null;

                $partTypes[$definition['full_slug']] = $this->persist(
                    definition: $definition,
                    parent: $parent,
                    tree: $tree,
                    resolver: $resolver,
                );
            }
        });
    }

    /**
     * @param array{full_slug: string, parent_full_slug: ?string, title: string, position: int, default_image_key: ?string} $definition
     */
    private function persist(
        array $definition,
        ?PartType $parent,
        PartTypeTreeService $tree,
        PartTypeCategoryResolver $resolver,
    ): PartType {
        $partType = PartType::withTrashed()->where('full_slug', $definition['full_slug'])->first() ?? new PartType;
        $structuralUpdates = [
            'parent_id' => $parent?->getKey(),
            'title' => $definition['title'],
            'position' => $definition['position'],
            'is_active' => true,
        ];

        $partType->forceFill($structuralUpdates);

        if (($partType->default_image_key === null || $partType->default_image_key === '') && $definition['default_image_key'] !== null) {
            $partType->default_image_key = $definition['default_image_key'];
        }

        if (! $partType->exists || $partType->isDirty(['parent_id', 'title', 'position'])) {
            $tree->save($partType);
        } elseif ($partType->isDirty(['is_active', 'default_image_key'])) {
            $partType->saveQuietly();
        }

        if ($partType->trashed()) {
            $partType->restoreQuietly();
        }

        if ($partType->product_category_id === null) {
            $partType->forceFill([
                'product_category_id' => $resolver->resolve($partType)->category->getKey(),
            ])->saveQuietly();
        }

        return $partType;
    }
}

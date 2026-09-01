<?php

namespace Database\Seeders;

use App\Enums\HomepageCategoryCardCode;
use App\Enums\HomepageMetricCode;
use App\Enums\HomepageSectionCode;
use App\Enums\NavigationLinkType;
use App\Models\HomepageCategoryCard;
use App\Models\HomepageMetric;
use App\Models\HomepageSection;
use App\Models\PartType;
use App\Models\ProductCategory;
use Database\Seeders\Concerns\FillsMissingSeederAttributes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class HomepageContentSeeder extends Seeder
{
    use FillsMissingSeederAttributes;

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach ($this->sections() as $code => $attributes) {
                $record = HomepageSection::query()->firstOrNew(['code' => $code]);
                $this->fillMissing($record, $attributes)->save();
            }

            foreach ($this->categoryCards() as $code => $definition) {
                $this->seedCategoryCard($code, $definition);
            }

            foreach ($this->metrics() as $code => $attributes) {
                $record = HomepageMetric::query()->firstOrNew(['code' => $code]);
                $this->fillMissing($record, $attributes)->save();
            }
        });
    }

    /** @return array<string, array<string, mixed>> */
    private function sections(): array
    {
        return [
            HomepageSectionCode::Stories->value => ['title' => null, 'is_active' => true, 'position' => 10],
            HomepageSectionCode::VehicleSearch->value => ['title' => 'Быстрый поиск запчастей', 'is_active' => true, 'position' => 20],
            HomepageSectionCode::CategoryCards->value => ['title' => null, 'is_active' => true, 'position' => 30],
            HomepageSectionCode::Reviews->value => ['title' => 'Отзывы клиентов', 'is_active' => true, 'position' => 40],
            HomepageSectionCode::AboutMetrics->value => ['title' => 'О компании', 'is_active' => true, 'position' => 50],
        ];
    }

    /** @return array<string, array{title:string,target:?string,position:int}> */
    private function categoryCards(): array
    {
        return [
            HomepageCategoryCardCode::Sills->value => [
                'title' => 'Кузовные пороги',
                'target' => 'part_type:Порог',
                'position' => 10,
            ],
            HomepageCategoryCardCode::Commercial->value => [
                'title' => 'Коммерческий транспорт',
                'target' => 'catalog',
                'position' => 20,
            ],
            HomepageCategoryCardCode::BodyRepair->value => [
                'title' => 'Ремонт кузова',
                'target' => 'product_category:Ремонтные элементы кузова',
                'position' => 30,
            ],
            HomepageCategoryCardCode::FrontArches->value => [
                'title' => 'Передние арки',
                'target' => 'part_type:Арка / Передняя',
                'position' => 40,
            ],
            HomepageCategoryCardCode::RearArches->value => [
                'title' => 'Задние арки',
                'target' => 'part_type:Арка / Задняя',
                'position' => 50,
            ],
        ];
    }

    /** @param array{title:string,target:?string,position:int} $definition */
    private function seedCategoryCard(string $code, array $definition): void
    {
        $record = HomepageCategoryCard::query()->firstOrNew(['code' => $code]);
        $wasNew = ! $record->exists;
        $upgradeUntouchedCommercial = $this->isUntouchedLegacyCommercial($record, $definition);
        $destinationWasMissing = $record->exists && $this->hasNoCategoryDestination($record);
        $destination = $this->categoryDestination($definition['target']);
        $destinationWasResolved = $this->hasResolvedCategoryDestination($destination);

        $this->fillMissing($record, [
            'title' => $definition['title'],
            'position' => $definition['position'],
        ]);

        if ($code === HomepageCategoryCardCode::Commercial->value
            && $destinationWasMissing
            && ! $upgradeUntouchedCommercial) {
            $safeChanges = array_intersect_key($record->getDirty(), array_flip(['title', 'position']));

            if ($safeChanges !== []) {
                DB::table($record->getTable())
                    ->where($record->getKeyName(), $record->getKey())
                    ->update([...$safeChanges, 'updated_at' => now()]);
            }

            return;
        }

        if ($wasNew) {
            $record->fill($destination);
        } elseif ($destinationWasMissing && $destinationWasResolved) {
            unset($destination['is_active']);
            $this->fillMissing($record, $destination);

            if ($upgradeUntouchedCommercial) {
                $record->is_active = true;
            }
        }

        if (! $wasNew && $destinationWasMissing && ! $destinationWasResolved) {
            $safeChanges = array_intersect_key($record->getDirty(), array_flip(['title', 'position']));

            if ($safeChanges !== []) {
                DB::table($record->getTable())
                    ->where($record->getKeyName(), $record->getKey())
                    ->update([...$safeChanges, 'updated_at' => now()]);
            }

            return;
        }

        if ($wasNew || $record->isDirty()) {
            $record->save();
        }
    }

    /** @return array<string, mixed> */
    private function categoryDestination(?string $target): array
    {
        $empty = [
            'link_type' => null,
            'route_name' => null,
            'product_category_id' => null,
            'part_type_id' => null,
            'url' => null,
            'open_in_new_tab' => false,
            'is_active' => false,
        ];

        if ($target === null) {
            return $empty;
        }

        if ($target === 'catalog') {
            return Route::has('catalog.index')
                ? [...$empty, 'link_type' => NavigationLinkType::Route, 'route_name' => 'catalog.index', 'is_active' => true]
                : $empty;
        }

        if (str_starts_with($target, 'part_type:')) {
            $fullTitle = substr($target, strlen('part_type:'));
            $record = PartType::query()
                ->where('full_title', $fullTitle)
                ->where('is_active', true)
                ->first();

            return $record instanceof PartType
                ? [...$empty, 'part_type_id' => (int) $record->getKey(), 'is_active' => true]
                : $empty;
        }

        if (str_starts_with($target, 'product_category:')) {
            $title = substr($target, strlen('product_category:'));
            $record = ProductCategory::query()
                ->where('title', $title)
                ->where('is_active', true)
                ->where('full_slug', 'kuzovnye-detali/remontnye-elementy-kuzova')
                ->first();

            return $record instanceof ProductCategory
                ? [...$empty, 'product_category_id' => (int) $record->getKey(), 'is_active' => true]
                : $empty;
        }

        return $empty;
    }

    private function hasNoCategoryDestination(HomepageCategoryCard $record): bool
    {
        return $record->link_type === null
            && blank($record->route_name)
            && blank($record->url)
            && $record->product_category_id === null
            && $record->part_type_id === null;
    }

    /** @param array{title:string,target:?string,position:int} $definition */
    private function isUntouchedLegacyCommercial(HomepageCategoryCard $record, array $definition): bool
    {
        return $record->exists
            && $record->code === HomepageCategoryCardCode::Commercial
            && $record->title === $definition['title']
            && $record->position === $definition['position']
            && $record->is_active === false
            && $record->open_in_new_tab === false
            && $this->hasNoCategoryDestination($record);
    }

    /** @param array<string, mixed> $destination */
    private function hasResolvedCategoryDestination(array $destination): bool
    {
        return $destination['link_type'] !== null
            || filled($destination['route_name'])
            || $destination['product_category_id'] !== null
            || $destination['part_type_id'] !== null;
    }

    /** @return array<string, array<string, mixed>> */
    private function metrics(): array
    {
        return [
            HomepageMetricCode::SinceYear->value => ['prefix' => 'с', 'value' => '2014', 'suffix' => 'г.', 'text' => 'наша экспертиза для вашей уверенности', 'is_active' => true, 'position' => 10],
            HomepageMetricCode::VehicleDatabase->value => ['prefix' => null, 'value' => '3000', 'suffix' => 'авто', 'text' => 'самая большая база ремонтных деталей', 'is_active' => true, 'position' => 20],
            HomepageMetricCode::ItemsSold->value => ['prefix' => null, 'value' => '1', 'suffix' => 'млн шт.', 'text' => 'проданных арок и порогов за все время', 'is_active' => true, 'position' => 30],
            HomepageMetricCode::OriginalFit->value => ['prefix' => null, 'value' => '100', 'suffix' => '%', 'text' => 'повторяет оригинальные детали', 'is_active' => true, 'position' => 40],
            HomepageMetricCode::PriceAdvantage->value => ['prefix' => 'в', 'value' => '5', 'suffix' => 'раз', 'text' => 'дешевле штампованных деталей с разборки', 'is_active' => true, 'position' => 50],
        ];
    }
}

<?php

namespace App\Services\SiteContent;

use App\Enums\AdminPermission;
use App\Enums\HomepageCategoryDestination;
use App\Enums\HomepageSectionCode;
use App\Enums\NavigationLinkType;
use App\Enums\StaticPageCode;
use App\Enums\StaticPageItemCode;
use App\Enums\StaticPageSectionCode;
use App\Models\DeliveryMethodSetting;
use App\Models\FaqCategory;
use App\Models\FaqItem;
use App\Models\HomepageCategoryCard;
use App\Models\HomepageMetric;
use App\Models\HomepageSection;
use App\Models\HomepageStoryGroup;
use App\Models\HomepageStoryItem;
use App\Models\PartType;
use App\Models\PaymentMethodSetting;
use App\Models\ProductCategory;
use App\Models\StaticPage;
use App\Models\StaticPageItem;
use App\Models\StaticPageSection;
use App\Models\User;
use App\Services\Homepage\HomepageContentAdminService;
use App\Services\Orders\DeliveryMethodSettingsAdminService;
use App\Services\Orders\PaymentMethodSettingsAdminService;
use App\Services\StaticContent\FaqAdminService;
use App\Services\StaticContent\StaticPageContentAdminService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SitePageContentAdminService
{
    public function __construct(
        private readonly HomepageContentAdminService $homepage,
        private readonly StaticPageContentAdminService $staticPages,
        private readonly FaqAdminService $faq,
        private readonly DeliveryMethodSettingsAdminService $deliveryMethods,
        private readonly PaymentMethodSettingsAdminService $paymentMethods,
    ) {}

    /** @return array<string, string> */
    public static function categoryCardDestinationOptions(): array
    {
        return HomepageCategoryDestination::options();
    }

    /** @return array<int, string> */
    public function productCategoryOptions(?int $currentId = null): array
    {
        return ProductCategory::withTrashed()
            ->with('parent')
            ->where(function ($query) use ($currentId): void {
                $query->where(fn ($active) => $active->where('is_active', true)->whereNull('deleted_at'));
                if ($currentId !== null) {
                    $query->orWhere($query->getModel()->getQualifiedKeyName(), $currentId);
                }
            })
            ->orderBy('full_slug')
            ->get()
            ->mapWithKeys(fn (ProductCategory $category): array => [
                (int) $category->getKey() => $this->catalogOptionLabel($category->full_title, $category->is_active, $category->trashed()),
            ])
            ->all();
    }

    /** @return array<int, string> */
    public function partTypeOptions(?int $currentId = null): array
    {
        return PartType::withTrashed()
            ->where(function ($query) use ($currentId): void {
                $query->where(fn ($active) => $active->where('is_active', true)->whereNull('deleted_at'));
                if ($currentId !== null) {
                    $query->orWhere($query->getModel()->getQualifiedKeyName(), $currentId);
                }
            })
            ->orderBy('full_slug')
            ->get()
            ->mapWithKeys(fn (PartType $partType): array => [
                (int) $partType->getKey() => $this->catalogOptionLabel($partType->full_title, $partType->is_active, $partType->trashed()),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    public function homepageState(): array
    {
        $sections = HomepageSection::query()->ordered()->get()
            ->keyBy(fn (HomepageSection $section): string => $section->code->value);

        return [
            'stories_section' => $this->homepageSectionState($sections, 'stories', false),
            'stories' => HomepageStoryGroup::query()->ordered()->with(['items' => fn ($query) => $query->ordered()])->get()
                ->map(fn (HomepageStoryGroup $group): array => [
                    'id' => $group->getKey(),
                    '_label' => $group->title,
                    'title' => $group->title,
                    'cover_image_path' => $group->cover_image_path,
                    'is_active' => $group->is_active,
                    'items' => $group->items->map(fn (HomepageStoryItem $item): array => [
                        'id' => $item->getKey(),
                        '_label' => $item->alt_text ?: $item->media_type->label(),
                        'media_type' => $item->media_type->value,
                        'media_path' => $item->media_path,
                        'alt_text' => $item->alt_text,
                        'cta_label' => $item->cta_label,
                        'cta_url' => $item->cta_url,
                        'open_in_new_tab' => $item->open_in_new_tab,
                        'duration_seconds' => $item->duration_seconds,
                        'is_active' => $item->is_active,
                    ])->all(),
                ])
                ->all(),
            'search_section' => $this->homepageSectionState($sections, 'vehicle_search'),
            'category_section' => $this->homepageSectionState($sections, 'category_cards', false),
            'category_cards' => HomepageCategoryCard::query()->ordered()->get()
                ->map(fn (HomepageCategoryCard $record): array => [
                    'id' => $record->getKey(),
                    '_label' => $record->code->value,
                    'title' => $record->title,
                    'destination_type' => $this->categoryCardDestinationType($record)?->value,
                    'product_category_id' => $record->product_category_id,
                    'part_type_id' => $record->part_type_id,
                    'is_active' => $record->is_active,
                ])
                ->all(),
            'reviews_section' => $this->homepageSectionState($sections, 'reviews'),
            'about_section' => $this->homepageSectionState($sections, 'about_metrics'),
            'metrics' => HomepageMetric::query()->ordered()->get()
                ->map(fn (HomepageMetric $record): array => [
                    'id' => $record->getKey(),
                    '_label' => $record->code->value,
                    'prefix' => $record->prefix,
                    'value' => $record->value,
                    'suffix' => $record->suffix,
                    'text' => $record->text,
                ])
                ->all(),
        ];
    }

    /** @param array<string, mixed> $data */
    public function saveHomepage(User $actor, array $data): void
    {
        $this->authorizePermissions($actor, [AdminPermission::ManageHomepageContent]);
        $this->rejectUnexpected($data, [
            'stories_section', 'stories', 'search_section', 'category_section', 'category_cards',
            'reviews_section', 'about_section', 'metrics',
        ], 'data');

        DB::transaction(function () use ($actor, $data): void {
            $sections = HomepageSection::query()->ordered()->lockForUpdate()->get()
                ->keyBy(fn (HomepageSection $section): string => $section->code->value);

            $this->saveHomepageSection($actor, $data, $sections, 'stories_section', 'stories', false);
            $this->homepage->saveStories($actor, $data['stories'] ?? null);
            $this->saveHomepageSection($actor, $data, $sections, 'search_section', 'vehicle_search', true);
            $this->saveHomepageSection($actor, $data, $sections, 'category_section', 'category_cards', false);
            $this->saveHomepageSection($actor, $data, $sections, 'reviews_section', 'reviews', true);
            $this->saveHomepageSection($actor, $data, $sections, 'about_section', 'about_metrics', true);

            $cards = HomepageCategoryCard::query()->ordered()->lockForUpdate()->get();
            $cardRows = $this->fixedRows(
                $data['category_cards'] ?? null,
                $cards,
                ['id', 'title', 'destination_type', 'product_category_id', 'part_type_id', 'is_active'],
                'category_cards',
            );

            foreach ($cardRows as $index => [$record, $row]) {
                $payload = [
                    'title' => $this->requiredValue($row, 'title', "category_cards.{$index}"),
                    'is_active' => $this->requiredValue($row, 'is_active', "category_cards.{$index}"),
                    ...$this->categoryCardPayload($row, "category_cards.{$index}"),
                ];

                $this->withValidationPath(
                    "category_cards.{$index}",
                    fn () => $this->homepage->updateCategoryCard($actor, $record, $payload),
                );
            }

            $this->homepage->reorderCategoryCards($actor, array_map(
                fn (array $pair): int => (int) $pair[0]->getKey(),
                $cardRows,
            ));

            $metrics = HomepageMetric::query()->ordered()->lockForUpdate()->get();
            $metricRows = $this->fixedRows(
                $data['metrics'] ?? null,
                $metrics,
                ['id', 'prefix', 'value', 'suffix', 'text'],
                'metrics',
            );

            foreach ($metricRows as $index => [$record, $row]) {
                $this->withValidationPath(
                    "metrics.{$index}",
                    fn () => $this->homepage->updateMetric($actor, $record, [
                        'prefix' => $row['prefix'] ?? null,
                        'value' => $this->requiredValue($row, 'value', "metrics.{$index}"),
                        'suffix' => $row['suffix'] ?? null,
                        'text' => $this->requiredValue($row, 'text', "metrics.{$index}"),
                    ]),
                );
            }
        });
    }

    /** @return array<string, mixed> */
    public function aboutState(): array
    {
        $page = $this->staticPageWithContent(StaticPageCode::About);
        $sections = $page->sections->keyBy(fn (StaticPageSection $section): string => $section->code->value);

        $hero = $this->requiredSection($sections, StaticPageSectionCode::AboutHero);
        $metrics = $this->requiredSection($sections, StaticPageSectionCode::AboutMetrics);
        $technologies = $this->requiredSection($sections, StaticPageSectionCode::AboutTechnologies);
        $goal = $this->requiredSection($sections, StaticPageSectionCode::AboutGoal);

        return [
            'hero' => [
                'id' => $hero->getKey(),
                'label' => $hero->label,
                'title' => $hero->title,
                'body' => $hero->body,
            ],
            'metrics' => $metrics->items->map(fn (StaticPageItem $item): array => [
                'id' => $item->getKey(),
                '_label' => $item->code->label(),
                'title' => $item->title,
                'text' => $item->text,
            ])->all(),
            'technologies' => [
                'id' => $technologies->getKey(),
                'title' => $technologies->title,
                'subtitle' => $technologies->subtitle,
                'items' => $technologies->items->map(fn (StaticPageItem $item): array => [
                    'id' => $item->getKey(),
                    '_label' => $item->code->label(),
                    'text' => $item->text,
                ])->all(),
            ],
            'goal' => [
                'id' => $goal->getKey(),
                'label' => $goal->label,
                'body' => $goal->body,
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    public function saveAbout(User $actor, array $data): void
    {
        $this->authorizePermissions($actor, [AdminPermission::ManageStaticContent]);
        $this->rejectUnexpected($data, ['hero', 'metrics', 'technologies', 'goal'], 'data');

        DB::transaction(function () use ($actor, $data): void {
            [$sections, $items] = $this->lockedStaticContent(StaticPageCode::About);

            $hero = $this->fixedRecord(
                $data['hero'] ?? null,
                $this->requiredSection($sections, StaticPageSectionCode::AboutHero),
                ['id', 'label', 'title', 'body'],
                'hero',
            );
            $this->withValidationPath('hero', fn () => $this->staticPages->updateSection($actor, $hero[0], [
                'label' => $hero[1]['label'] ?? null,
                'title' => $hero[1]['title'] ?? null,
                'body' => $hero[1]['body'] ?? null,
            ]));

            $metricRows = $this->fixedRowsForCodes(
                $data['metrics'] ?? null,
                $items,
                [StaticPageItemCode::AboutMetricParts, StaticPageItemCode::AboutMetricModels],
                ['id', 'title', 'text'],
                'metrics',
            );
            foreach ($metricRows as $index => [$record, $row]) {
                $this->withValidationPath("metrics.{$index}", fn () => $this->staticPages->updateItem($actor, $record, [
                    'title' => $row['title'] ?? null,
                    'text' => $row['text'] ?? null,
                ]));
            }

            $technologyData = $this->arrayValue($data['technologies'] ?? null, 'technologies');
            $this->rejectUnexpected($technologyData, ['id', 'title', 'subtitle', 'items'], 'technologies');
            $technologySection = $this->requiredSection($sections, StaticPageSectionCode::AboutTechnologies);
            $this->assertRecordId($technologyData, $technologySection, 'technologies');
            $this->withValidationPath('technologies', fn () => $this->staticPages->updateSection($actor, $technologySection, [
                'title' => $technologyData['title'] ?? null,
                'subtitle' => $technologyData['subtitle'] ?? null,
            ]));

            $technologyRows = $this->fixedRowsForCodes(
                $technologyData['items'] ?? null,
                $items,
                [
                    StaticPageItemCode::AboutTechnologySteel,
                    StaticPageItemCode::AboutTechnologyScan,
                    StaticPageItemCode::AboutTechnologyCnc,
                ],
                ['id', 'text'],
                'technologies.items',
            );
            foreach ($technologyRows as $index => [$record, $row]) {
                $this->withValidationPath("technologies.items.{$index}", fn () => $this->staticPages->updateItem($actor, $record, [
                    'text' => $row['text'] ?? null,
                ]));
            }

            $goal = $this->fixedRecord(
                $data['goal'] ?? null,
                $this->requiredSection($sections, StaticPageSectionCode::AboutGoal),
                ['id', 'label', 'body'],
                'goal',
            );
            $this->withValidationPath('goal', fn () => $this->staticPages->updateSection($actor, $goal[0], [
                'label' => $goal[1]['label'] ?? null,
                'body' => $goal[1]['body'] ?? null,
            ]));
        });
    }

    /** @return array<string, mixed> */
    public function howState(): array
    {
        $page = $this->staticPageWithContent(StaticPageCode::How);
        $section = $this->requiredSection(
            $page->sections->keyBy(fn (StaticPageSection $record): string => $record->code->value),
            StaticPageSectionCode::HowSteps,
        );

        return [
            'steps' => $section->items->values()->map(fn (StaticPageItem $item, int $index): array => [
                'id' => $item->getKey(),
                '_label' => 'Шаг '.($index + 1),
                'title' => $item->title,
                'text' => $item->text,
            ])->all(),
        ];
    }

    /** @param array<string, mixed> $data */
    public function saveHow(User $actor, array $data): void
    {
        $this->authorizePermissions($actor, [AdminPermission::ManageStaticContent]);
        $this->rejectUnexpected($data, ['steps'], 'data');

        DB::transaction(function () use ($actor, $data): void {
            [, $items] = $this->lockedStaticContent(StaticPageCode::How);
            $rows = $this->fixedRowsForCodes(
                $data['steps'] ?? null,
                $items,
                [
                    StaticPageItemCode::HowStepChoose,
                    StaticPageItemCode::HowStepConfirm,
                    StaticPageItemCode::HowStepPrepare,
                    StaticPageItemCode::HowStepHandover,
                    StaticPageItemCode::HowStepReceive,
                    StaticPageItemCode::HowStepPay,
                ],
                ['id', 'title', 'text'],
                'steps',
            );

            foreach ($rows as $index => [$record, $row]) {
                $this->withValidationPath("steps.{$index}", fn () => $this->staticPages->updateItem($actor, $record, [
                    'title' => $row['title'] ?? null,
                    'text' => $row['text'] ?? null,
                ]));
            }
        });
    }

    /** @return array<string, mixed> */
    public function paymentState(): array
    {
        return [
            'payment_methods' => PaymentMethodSetting::query()->ordered()->get()
                ->map(fn (PaymentMethodSetting $record): array => [
                    'id' => $record->getKey(),
                    '_label' => $record->code->label(),
                    'title' => $record->title,
                    'description' => $record->description,
                    'page_title' => $record->page_title,
                    'page_description' => $record->page_description,
                    'is_active' => $record->is_active,
                ])->all(),
            'delivery_methods' => DeliveryMethodSetting::query()->ordered()->get()
                ->map(fn (DeliveryMethodSetting $record): array => [
                    'id' => $record->getKey(),
                    '_label' => $record->code->label(),
                    'title' => $record->title,
                    'description' => $record->description,
                    'page_title' => $record->page_title,
                    'page_description' => $record->page_description,
                    'base_price' => $record->base_price,
                    'price_mode' => $record->price_mode->value,
                    'is_active' => $record->is_active,
                ])->all(),
        ];
    }

    /** @param array<string, mixed> $data */
    public function savePayment(User $actor, array $data): void
    {
        $this->authorizePermissions($actor, [
            AdminPermission::ManagePaymentMethods,
            AdminPermission::ManageDeliveryMethods,
        ]);
        $this->rejectUnexpected($data, ['payment_methods', 'delivery_methods'], 'data');

        DB::transaction(function () use ($actor, $data): void {
            $payments = PaymentMethodSetting::query()->ordered()->lockForUpdate()->get();
            $paymentRows = $this->fixedRows(
                $data['payment_methods'] ?? null,
                $payments,
                ['id', 'title', 'description', 'page_title', 'page_description', 'is_active'],
                'payment_methods',
            );
            foreach ($paymentRows as $index => [$record, $row]) {
                $this->withValidationPath("payment_methods.{$index}", fn () => $this->paymentMethods->update($actor, $record, [
                    'title' => $this->requiredValue($row, 'title', "payment_methods.{$index}"),
                    'description' => $row['description'] ?? null,
                    'page_title' => $row['page_title'] ?? null,
                    'page_description' => $row['page_description'] ?? null,
                    'is_active' => $this->requiredValue($row, 'is_active', "payment_methods.{$index}"),
                ]));
            }
            $this->paymentMethods->reorder($actor, array_map(
                fn (array $pair): int => (int) $pair[0]->getKey(),
                $paymentRows,
            ));

            $deliveries = DeliveryMethodSetting::query()->ordered()->lockForUpdate()->get();
            $deliveryRows = $this->fixedRows(
                $data['delivery_methods'] ?? null,
                $deliveries,
                ['id', 'title', 'description', 'page_title', 'page_description', 'base_price', 'price_mode', 'is_active'],
                'delivery_methods',
            );
            foreach ($deliveryRows as $index => [$record, $row]) {
                $this->withValidationPath("delivery_methods.{$index}", fn () => $this->deliveryMethods->update($actor, $record, [
                    'title' => $this->requiredValue($row, 'title', "delivery_methods.{$index}"),
                    'description' => $row['description'] ?? null,
                    'page_title' => $row['page_title'] ?? null,
                    'page_description' => $row['page_description'] ?? null,
                    'base_price' => $this->requiredValue($row, 'base_price', "delivery_methods.{$index}"),
                    'price_mode' => $this->requiredValue($row, 'price_mode', "delivery_methods.{$index}"),
                    'is_active' => $this->requiredValue($row, 'is_active', "delivery_methods.{$index}"),
                ]));
            }
            $this->deliveryMethods->reorder($actor, array_map(
                fn (array $pair): int => (int) $pair[0]->getKey(),
                $deliveryRows,
            ));
        });
    }

    /** @return array<string, mixed> */
    public function faqState(): array
    {
        $categories = FaqCategory::query()
            ->ordered()
            ->with(['items' => fn ($query) => $query->ordered()])
            ->get();

        return [
            'categories' => $categories->map(fn (FaqCategory $category): array => [
                'id' => $category->getKey(),
                'title' => $category->title,
                'is_active' => $category->is_active,
                'items' => $category->items->map(fn (FaqItem $item): array => [
                    'id' => $item->getKey(),
                    'question' => $item->question,
                    'answer' => $item->answer,
                    'is_active' => $item->is_active,
                    'is_featured' => $item->is_featured,
                ])->all(),
            ])->all(),
        ];
    }

    /** @param array<string, mixed> $data */
    public function saveFaq(User $actor, array $data): void
    {
        $this->authorizePermissions($actor, [AdminPermission::ManageStaticContent]);
        $this->rejectUnexpected($data, ['categories'], 'data');

        $categoryRows = $this->rowsValue($data['categories'] ?? null, 'categories');
        $seenCategoryIds = [];
        $seenItemIds = [];

        foreach ($categoryRows as $categoryIndex => $categoryRow) {
            $this->rejectUnexpected($categoryRow, ['id', 'title', 'is_active', 'items'], "categories.{$categoryIndex}");
            $categoryId = $this->nullableId($categoryRow['id'] ?? null, "categories.{$categoryIndex}.id");
            if ($categoryId !== null && in_array($categoryId, $seenCategoryIds, true)) {
                $this->validationError("categories.{$categoryIndex}.id", 'Категория FAQ указана в форме несколько раз.');
            }
            if ($categoryId !== null) {
                $seenCategoryIds[] = $categoryId;
            }

            foreach ($this->rowsValue($categoryRow['items'] ?? null, "categories.{$categoryIndex}.items") as $itemIndex => $itemRow) {
                $this->rejectUnexpected($itemRow, ['id', 'question', 'answer', 'is_active', 'is_featured'], "categories.{$categoryIndex}.items.{$itemIndex}");
                $itemId = $this->nullableId($itemRow['id'] ?? null, "categories.{$categoryIndex}.items.{$itemIndex}.id");
                if ($itemId !== null && in_array($itemId, $seenItemIds, true)) {
                    $this->validationError("categories.{$categoryIndex}.items.{$itemIndex}.id", 'Вопрос FAQ указан в форме несколько раз.');
                }
                if ($itemId !== null) {
                    $seenItemIds[] = $itemId;
                }
            }
        }

        DB::transaction(function () use ($actor, $categoryRows, $seenCategoryIds, $seenItemIds): void {
            $existingCategories = FaqCategory::query()->ordered()->lockForUpdate()->get()->keyBy('id');
            $existingItems = FaqItem::query()->ordered()->lockForUpdate()->get()->keyBy('id');

            foreach ($seenCategoryIds as $id) {
                if (! $existingCategories->has($id)) {
                    $this->validationError('categories', 'Одна из категорий FAQ не существует или уже удалена.');
                }
            }
            foreach ($seenItemIds as $id) {
                if (! $existingItems->has($id)) {
                    $this->validationError('categories', 'Один из вопросов FAQ не существует или уже удалён.');
                }
            }

            $keptCategoryIds = [];
            $keptItemIds = [];

            foreach ($categoryRows as $categoryIndex => $categoryRow) {
                $categoryId = $this->nullableId($categoryRow['id'] ?? null, "categories.{$categoryIndex}.id");
                $categoryPayload = [
                    'title' => $this->requiredValue($categoryRow, 'title', "categories.{$categoryIndex}"),
                    'is_active' => $this->requiredValue($categoryRow, 'is_active', "categories.{$categoryIndex}"),
                    'position' => $categoryIndex,
                ];

                if ($categoryId === null) {
                    $category = $this->withValidationPath(
                        "categories.{$categoryIndex}",
                        fn () => $this->faq->createCategory($actor, $categoryPayload),
                    );
                } else {
                    /** @var FaqCategory $category */
                    $category = $existingCategories->get($categoryId);
                    $category = $this->withValidationPath(
                        "categories.{$categoryIndex}",
                        fn () => $this->faq->updateCategory($actor, $category, $categoryPayload),
                    );
                }

                $keptCategoryIds[] = (int) $category->getKey();
                $itemRows = $this->rowsValue($categoryRow['items'] ?? null, "categories.{$categoryIndex}.items");

                foreach ($itemRows as $itemIndex => $itemRow) {
                    $itemId = $this->nullableId($itemRow['id'] ?? null, "categories.{$categoryIndex}.items.{$itemIndex}.id");
                    $itemPayload = [
                        'question' => $this->requiredValue($itemRow, 'question', "categories.{$categoryIndex}.items.{$itemIndex}"),
                        'answer' => $this->requiredValue($itemRow, 'answer', "categories.{$categoryIndex}.items.{$itemIndex}"),
                        'is_active' => $this->requiredValue($itemRow, 'is_active', "categories.{$categoryIndex}.items.{$itemIndex}"),
                        'is_featured' => $this->requiredValue($itemRow, 'is_featured', "categories.{$categoryIndex}.items.{$itemIndex}"),
                        'position' => $itemIndex,
                    ];

                    if ($itemId === null) {
                        $item = $this->withValidationPath(
                            "categories.{$categoryIndex}.items.{$itemIndex}",
                            fn () => $this->faq->createItem($actor, $category, $itemPayload),
                        );
                    } else {
                        /** @var FaqItem $item */
                        $item = $existingItems->get($itemId);
                        $item = $this->withValidationPath(
                            "categories.{$categoryIndex}.items.{$itemIndex}",
                            fn () => $this->faq->updateItem($actor, $item, [
                                'faq_category_id' => $category->getKey(),
                                ...$itemPayload,
                            ]),
                        );
                    }

                    $keptItemIds[] = (int) $item->getKey();
                }
            }

            foreach ($existingItems as $item) {
                if (! in_array((int) $item->getKey(), $keptItemIds, true)) {
                    $this->faq->deleteItem($actor, $item);
                }
            }

            foreach ($existingCategories as $category) {
                if (! in_array((int) $category->getKey(), $keptCategoryIds, true)) {
                    $this->faq->deleteCategory($actor, $category);
                }
            }
        });
    }

    /** @return array<string, mixed> */
    public function partnersState(): array
    {
        $page = $this->staticPageWithContent(StaticPageCode::Partners);
        $sections = $page->sections->keyBy(fn (StaticPageSection $section): string => $section->code->value);
        $benefits = $this->requiredSection($sections, StaticPageSectionCode::PartnersBenefits);
        $cooperation = $this->requiredSection($sections, StaticPageSectionCode::PartnersCooperation);
        $about = $this->requiredSection($sections, StaticPageSectionCode::PartnersAbout);

        return [
            'page' => [
                'id' => $page->getKey(),
                'title' => $page->title,
                'subtitle' => $page->subtitle,
            ],
            'benefits' => $benefits->items->map(fn (StaticPageItem $item): array => [
                'id' => $item->getKey(),
                '_label' => $item->code->label(),
                'title' => $item->title,
            ])->all(),
            'cooperation' => [
                'id' => $cooperation->getKey(),
                'title' => $cooperation->title,
                'items' => $cooperation->items->map(fn (StaticPageItem $item): array => [
                    'id' => $item->getKey(),
                    '_label' => $item->code->label(),
                    'title' => $item->title,
                ])->all(),
            ],
            'about' => [
                'id' => $about->getKey(),
                'title' => $about->title,
                'items' => $about->items->map(fn (StaticPageItem $item): array => [
                    'id' => $item->getKey(),
                    '_label' => $item->code->label(),
                    'text' => $item->text,
                ])->all(),
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    public function savePartners(User $actor, array $data): void
    {
        $this->authorizePermissions($actor, [AdminPermission::ManageStaticContent]);
        $this->rejectUnexpected($data, ['page', 'benefits', 'cooperation', 'about'], 'data');

        DB::transaction(function () use ($actor, $data): void {
            [$sections, $items, $page] = $this->lockedStaticContent(StaticPageCode::Partners, includePage: true);

            $pageData = $this->arrayValue($data['page'] ?? null, 'page');
            $this->rejectUnexpected($pageData, ['id', 'title', 'subtitle'], 'page');
            $this->assertRecordId($pageData, $page, 'page');
            $this->withValidationPath('page', fn () => $this->staticPages->updatePage($actor, $page, [
                'title' => $pageData['title'] ?? null,
                'subtitle' => $pageData['subtitle'] ?? null,
            ]));

            $benefitRows = $this->fixedRowsForCodes(
                $data['benefits'] ?? null,
                $items,
                [
                    StaticPageItemCode::PartnersBenefitPrices,
                    StaticPageItemCode::PartnersBenefitManager,
                    StaticPageItemCode::PartnersBenefitRussia,
                    StaticPageItemCode::PartnersBenefitPriority,
                ],
                ['id', 'title'],
                'benefits',
            );
            foreach ($benefitRows as $index => [$record, $row]) {
                $this->withValidationPath("benefits.{$index}", fn () => $this->staticPages->updateItem($actor, $record, [
                    'title' => $row['title'] ?? null,
                ]));
            }

            $cooperationData = $this->arrayValue($data['cooperation'] ?? null, 'cooperation');
            $this->rejectUnexpected($cooperationData, ['id', 'title', 'items'], 'cooperation');
            $cooperationSection = $this->requiredSection($sections, StaticPageSectionCode::PartnersCooperation);
            $this->assertRecordId($cooperationData, $cooperationSection, 'cooperation');
            $this->withValidationPath('cooperation', fn () => $this->staticPages->updateSection($actor, $cooperationSection, [
                'title' => $cooperationData['title'] ?? null,
            ]));
            $cooperationRows = $this->fixedRowsForCodes(
                $cooperationData['items'] ?? null,
                $items,
                [
                    StaticPageItemCode::PartnersTypeRetail,
                    StaticPageItemCode::PartnersTypeService,
                    StaticPageItemCode::PartnersTypeOnline,
                    StaticPageItemCode::PartnersTypeDropshipping,
                ],
                ['id', 'title'],
                'cooperation.items',
            );
            foreach ($cooperationRows as $index => [$record, $row]) {
                $this->withValidationPath("cooperation.items.{$index}", fn () => $this->staticPages->updateItem($actor, $record, [
                    'title' => $row['title'] ?? null,
                ]));
            }

            $aboutData = $this->arrayValue($data['about'] ?? null, 'about');
            $this->rejectUnexpected($aboutData, ['id', 'title', 'items'], 'about');
            $aboutSection = $this->requiredSection($sections, StaticPageSectionCode::PartnersAbout);
            $this->assertRecordId($aboutData, $aboutSection, 'about');
            $this->withValidationPath('about', fn () => $this->staticPages->updateSection($actor, $aboutSection, [
                'title' => $aboutData['title'] ?? null,
            ]));
            $aboutRows = $this->fixedRowsForCodes(
                $aboutData['items'] ?? null,
                $items,
                [
                    StaticPageItemCode::PartnersAboutProduction,
                    StaticPageItemCode::PartnersAboutMeasurements,
                    StaticPageItemCode::PartnersAboutPayment,
                    StaticPageItemCode::PartnersAboutMaterials,
                    StaticPageItemCode::PartnersAboutReturns,
                ],
                ['id', 'text'],
                'about.items',
            );
            foreach ($aboutRows as $index => [$record, $row]) {
                $this->withValidationPath("about.items.{$index}", fn () => $this->staticPages->updateItem($actor, $record, [
                    'text' => $row['text'] ?? null,
                ]));
            }
        });
    }

    /** @param Collection<string, HomepageSection> $sections
     * @return array<string, mixed>
     */
    private function homepageSectionState(Collection $sections, string $rawCode, bool $withTitle = true): array
    {
        $code = HomepageSectionCode::from($rawCode);
        $section = $sections->get($rawCode);
        if (! $section instanceof HomepageSection) {
            $this->validationError('sections', "Не найдена системная секция «{$code->adminLabel()}».");
        }

        return array_filter([
            'id' => $section->getKey(),
            '_label' => $code->adminLabel(),
            'title' => $withTitle ? $section->title : null,
            'is_active' => $section->is_active,
        ], fn (mixed $value, string $key): bool => $key !== 'title' || $withTitle, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  Collection<string, HomepageSection>  $sections
     */
    private function saveHomepageSection(
        User $actor,
        array $data,
        Collection $sections,
        string $path,
        string $rawCode,
        bool $titleEditable,
    ): void {
        $code = HomepageSectionCode::from($rawCode);
        $section = $sections->get($rawCode);
        if (! $section instanceof HomepageSection) {
            $this->validationError($path, "Не найдена системная секция «{$code->adminLabel()}».");
        }

        [$record, $row] = $this->fixedRecord(
            $data[$path] ?? null,
            $section,
            $titleEditable ? ['id', 'title', 'is_active'] : ['id', 'is_active'],
            $path,
        );

        $payload = ['is_active' => $this->requiredValue($row, 'is_active', $path)];
        if ($titleEditable) {
            $payload['title'] = $row['title'] ?? null;
        }

        $this->withValidationPath($path, fn () => $this->homepage->updateSection($actor, $record, $payload));
    }

    private function categoryCardDestinationType(HomepageCategoryCard $record): ?HomepageCategoryDestination
    {
        return match (true) {
            $record->product_category_id !== null => HomepageCategoryDestination::ProductCategory,
            $record->part_type_id !== null => HomepageCategoryDestination::PartType,
            $record->link_type === NavigationLinkType::Route && $record->route_name === 'catalog.index' => HomepageCategoryDestination::Catalog,
            default => null,
        };
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function categoryCardPayload(array $row, string $path): array
    {
        $rawType = $row['destination_type'] ?? null;
        $productCategoryId = $this->nullableId($row['product_category_id'] ?? null, "{$path}.product_category_id");
        $partTypeId = $this->nullableId($row['part_type_id'] ?? null, "{$path}.part_type_id");

        if ($rawType === null || $rawType === '') {
            return $this->emptyCategoryCardDestination($productCategoryId, $partTypeId, $path);
        }

        $type = is_string($rawType) ? HomepageCategoryDestination::tryFrom($rawType) : null;
        if ($type === null) {
            $this->validationError("{$path}.destination_type", 'Выберите назначение карточки из списка.');
        }

        return match ($type) {
            HomepageCategoryDestination::Catalog => $this->catalogCategoryCardDestination($productCategoryId, $partTypeId, $path),
            HomepageCategoryDestination::ProductCategory => $this->productCategoryCardDestination($productCategoryId, $partTypeId, $path),
            HomepageCategoryDestination::PartType => $this->partTypeCategoryCardDestination($productCategoryId, $partTypeId, $path),
        };
    }

    /** @return array<string, mixed> */
    private function emptyCategoryCardDestination(?int $productCategoryId, ?int $partTypeId, string $path): array
    {
        if ($productCategoryId !== null || $partTypeId !== null) {
            $this->validationError("{$path}.destination_type", 'Без назначения каталожные связи должны быть пустыми.');
        }

        return [
            'link_type' => null,
            'route_name' => null,
            'product_category_id' => null,
            'part_type_id' => null,
            'url' => null,
            'open_in_new_tab' => false,
            'is_active' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function catalogCategoryCardDestination(?int $productCategoryId, ?int $partTypeId, string $path): array
    {
        if ($productCategoryId !== null || $partTypeId !== null) {
            $this->validationError("{$path}.destination_type", 'Для назначения «Весь каталог» конкретные связи должны быть пустыми.');
        }

        return [
            'link_type' => NavigationLinkType::Route->value,
            'route_name' => 'catalog.index',
            'product_category_id' => null,
            'part_type_id' => null,
            'url' => null,
            'open_in_new_tab' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function productCategoryCardDestination(?int $productCategoryId, ?int $partTypeId, string $path): array
    {
        if ($productCategoryId === null) {
            $this->validationError("{$path}.product_category_id", 'Выберите категорию магазина.');
        }
        if ($partTypeId !== null) {
            $this->validationError("{$path}.part_type_id", 'Нельзя одновременно выбрать категорию магазина и тип детали.');
        }

        return [
            'link_type' => null,
            'route_name' => null,
            'product_category_id' => $productCategoryId,
            'part_type_id' => null,
            'url' => null,
            'open_in_new_tab' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function partTypeCategoryCardDestination(?int $productCategoryId, ?int $partTypeId, string $path): array
    {
        if ($partTypeId === null) {
            $this->validationError("{$path}.part_type_id", 'Выберите тип детали.');
        }
        if ($productCategoryId !== null) {
            $this->validationError("{$path}.product_category_id", 'Нельзя одновременно выбрать категорию магазина и тип детали.');
        }

        return [
            'link_type' => null,
            'route_name' => null,
            'product_category_id' => null,
            'part_type_id' => $partTypeId,
            'url' => null,
            'open_in_new_tab' => false,
        ];
    }

    private function catalogOptionLabel(string $label, bool $active, bool $deleted): string
    {
        return match (true) {
            $deleted => $label.' (удалено)',
            ! $active => $label.' (неактивно)',
            default => $label,
        };
    }

    private function staticPageWithContent(StaticPageCode $code): StaticPage
    {
        return StaticPage::query()
            ->where('code', $code->value)
            ->with([
                'sections' => fn ($query) => $query->ordered(),
                'sections.items' => fn ($query) => $query->ordered(),
            ])
            ->firstOrFail();
    }

    /**
     * @return array{0: Collection<string, StaticPageSection>, 1: Collection<string, StaticPageItem>, 2?: StaticPage}
     */
    private function lockedStaticContent(StaticPageCode $code, bool $includePage = false): array
    {
        $page = StaticPage::query()->where('code', $code->value)->lockForUpdate()->firstOrFail();
        $sections = StaticPageSection::query()
            ->where('static_page_id', $page->getKey())
            ->ordered()
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (StaticPageSection $section): string => $section->code->value);
        $items = StaticPageItem::query()
            ->whereIn('static_page_section_id', $sections->pluck('id'))
            ->ordered()
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (StaticPageItem $item): string => $item->code->value);

        return $includePage ? [$sections, $items, $page] : [$sections, $items];
    }

    /** @param Collection<string, StaticPageSection> $sections */
    private function requiredSection(Collection $sections, StaticPageSectionCode $code): StaticPageSection
    {
        $section = $sections->get($code->value);
        if (! $section instanceof StaticPageSection) {
            $this->validationError('content', "Не найден обязательный блок «{$code->label()}».");
        }

        return $section;
    }

    /**
     * @param  EloquentCollection<int, Model>  $records
     * @param  list<string>  $allowedFields
     * @return list<array{0: Model, 1: array<string, mixed>}>
     */
    private function fixedRows(mixed $value, EloquentCollection $records, array $allowedFields, string $path): array
    {
        $rows = $this->rowsValue($value, $path);
        $recordsById = $records->keyBy(fn (Model $record): int => (int) $record->getKey());
        $seen = [];
        $result = [];

        foreach ($rows as $index => $row) {
            unset($row['_label']);

            $this->rejectUnexpected($row, $allowedFields, "{$path}.{$index}");
            $id = $this->requiredId($row['id'] ?? null, "{$path}.{$index}.id");
            if (in_array($id, $seen, true)) {
                $this->validationError("{$path}.{$index}.id", 'Запись указана в форме несколько раз.');
            }
            $record = $recordsById->get($id);
            if (! $record instanceof Model) {
                $this->validationError("{$path}.{$index}.id", 'Запись не существует или не относится к этой странице.');
            }
            $seen[] = $id;
            $result[] = [$record, $row];
        }

        $expected = $recordsById->keys()->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
        $actual = collect($seen)->sort()->values()->all();
        if ($expected !== $actual) {
            $this->validationError($path, 'Форма должна содержать полный фиксированный набор записей без добавлений и удалений.');
        }

        return $result;
    }

    /**
     * @param  Collection<string, StaticPageItem>  $items
     * @param  list<StaticPageItemCode>  $codes
     * @param  list<string>  $allowedFields
     * @return list<array{0: StaticPageItem, 1: array<string, mixed>}>
     */
    private function fixedRowsForCodes(mixed $value, Collection $items, array $codes, array $allowedFields, string $path): array
    {
        $records = new EloquentCollection;
        foreach ($codes as $code) {
            $record = $items->get($code->value);
            if (! $record instanceof StaticPageItem) {
                $this->validationError($path, "Не найден обязательный элемент «{$code->label()}».");
            }
            $records->push($record);
        }

        return $this->fixedRows($value, $records, $allowedFields, $path);
    }

    /**
     * @param  list<string>  $allowedFields
     * @return array{0: Model, 1: array<string, mixed>}
     */
    private function fixedRecord(mixed $value, Model $record, array $allowedFields, string $path): array
    {
        $row = $this->arrayValue($value, $path);
        unset($row['_label']);

        $this->rejectUnexpected($row, $allowedFields, $path);
        $this->assertRecordId($row, $record, $path);

        return [$record, $row];
    }

    /** @param array<string, mixed> $row */
    private function assertRecordId(array $row, Model $record, string $path): void
    {
        $id = $this->requiredId($row['id'] ?? null, "{$path}.id");
        if ($id !== (int) $record->getKey()) {
            $this->validationError("{$path}.id", 'Запись не относится к выбранной странице.');
        }
    }

    /** @return list<array<string, mixed>> */
    private function rowsValue(mixed $value, string $path): array
    {
        if (! is_array($value)) {
            $this->validationError($path, 'Ожидался список записей.');
        }

        return array_values(array_map(function (mixed $row) use ($path): array {
            if (! is_array($row)) {
                $this->validationError($path, 'Каждая запись списка должна быть объектом формы.');
            }

            return $row;
        }, $value));
    }

    /** @return array<string, mixed> */
    private function arrayValue(mixed $value, string $path): array
    {
        if (! is_array($value)) {
            $this->validationError($path, 'Ожидался блок данных формы.');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function requiredValue(array $row, string $field, string $path): mixed
    {
        if (! array_key_exists($field, $row)) {
            $this->validationError("{$path}.{$field}", 'Поле обязательно.');
        }

        return $row[$field];
    }

    private function requiredId(mixed $value, string $path): int
    {
        $id = $this->nullableId($value, $path);
        if ($id === null) {
            $this->validationError($path, 'Идентификатор записи обязателен.');
        }

        return $id;
    }

    private function nullableId(mixed $value, string $path): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (! is_int($value) || $value < 1) {
            $this->validationError($path, 'Идентификатор записи имеет недопустимый формат.');
        }

        return $value;
    }

    /** @param array<string, mixed> $data
     * @param  list<string>  $allowed
     */
    private function rejectUnexpected(array $data, array $allowed, string $path): void
    {
        $unexpected = array_values(array_diff(array_keys($data), $allowed));
        if ($unexpected === []) {
            return;
        }

        throw ValidationException::withMessages(collect($unexpected)
            ->mapWithKeys(fn (string $field): array => ["{$path}.{$field}" => "Поле «{$field}» нельзя изменять в этом редакторе."])
            ->all());
    }

    /** @param list<AdminPermission> $permissions */
    private function authorizePermissions(User $actor, array $permissions): void
    {
        foreach ($permissions as $permission) {
            if (! $actor->canPerformAdminAction($permission)) {
                throw new AuthorizationException('Недостаточно прав для изменения содержимого страниц сайта.');
            }
        }
    }

    private function withValidationPath(string $path, Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => ["{$path}.{$field}" => $messages])
                ->all());
        }
    }

    private function validationError(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

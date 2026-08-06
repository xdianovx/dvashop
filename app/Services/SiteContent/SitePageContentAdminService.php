<?php

namespace App\Services\SiteContent;

use App\Enums\AdminPermission;
use App\Enums\NavigationLinkType;
use App\Enums\StaticPageCode;
use App\Enums\StaticPageItemCode;
use App\Enums\StaticPageSectionCode;
use App\Models\DeliveryMethodSetting;
use App\Models\FaqCategory;
use App\Models\FaqItem;
use App\Models\HomepageCategoryCard;
use App\Models\HomepageMetric;
use App\Models\HomepageQuickLink;
use App\Models\HomepageSection;
use App\Models\PaymentMethodSetting;
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
    public const DESTINATION_NONE = 'none';

    public const DESTINATION_EXTERNAL = 'external';

    /** @var array<string, string> */
    private const ROUTE_DESTINATIONS = [
        'home' => 'Главная',
        'catalog.index' => 'Каталог',
        'about' => 'О нас',
        'how' => 'Как мы работаем',
        'payment' => 'Оплата и доставка',
        'faq' => 'Вопросы и ответы',
        'partners' => 'Партнёрам',
        'cart.show' => 'Корзина',
    ];

    public function __construct(
        private readonly HomepageContentAdminService $homepage,
        private readonly StaticPageContentAdminService $staticPages,
        private readonly FaqAdminService $faq,
        private readonly DeliveryMethodSettingsAdminService $deliveryMethods,
        private readonly PaymentMethodSettingsAdminService $paymentMethods,
    ) {}

    /** @return array<string, string> */
    public static function destinationOptions(): array
    {
        $routes = collect(self::ROUTE_DESTINATIONS)
            ->mapWithKeys(fn (string $label, string $route): array => ["route:{$route}" => $label])
            ->all();

        return [
            self::DESTINATION_NONE => 'Без перехода',
            ...$routes,
            self::DESTINATION_EXTERNAL => 'Внешняя ссылка',
        ];
    }

    /** @return array<string, mixed> */
    public function homepageState(): array
    {
        return [
            'sections' => HomepageSection::query()->ordered()->get()
                ->map(fn (HomepageSection $record): array => [
                    'id' => $record->getKey(),
                    '_label' => $this->homepageSectionLabel($record),
                    'title' => $record->title,
                    'is_active' => $record->is_active,
                ])
                ->all(),
            'quick_links' => HomepageQuickLink::query()->ordered()->get()
                ->map(fn (HomepageQuickLink $record): array => $this->destinationState($record))
                ->all(),
            'category_cards' => HomepageCategoryCard::query()->ordered()->get()
                ->map(fn (HomepageCategoryCard $record): array => $this->destinationState($record))
                ->all(),
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
        $this->rejectUnexpected($data, ['sections', 'quick_links', 'category_cards', 'metrics'], 'data');

        DB::transaction(function () use ($actor, $data): void {
            $sections = HomepageSection::query()->ordered()->lockForUpdate()->get();
            $sectionRows = $this->fixedRows(
                $data['sections'] ?? null,
                $sections,
                ['id', 'title', 'is_active'],
                'sections',
            );

            foreach ($sectionRows as $index => [$record, $row]) {
                $this->withValidationPath(
                    "sections.{$index}",
                    fn () => $this->homepage->updateSection($actor, $record, [
                        'title' => $row['title'] ?? null,
                        'is_active' => $this->requiredValue($row, 'is_active', "sections.{$index}"),
                    ]),
                );
            }

            $quickLinks = HomepageQuickLink::query()->ordered()->lockForUpdate()->get();
            $quickRows = $this->fixedRows(
                $data['quick_links'] ?? null,
                $quickLinks,
                ['id', 'title', 'destination', 'external_url', 'is_active'],
                'quick_links',
            );

            foreach ($quickRows as $index => [$record, $row]) {
                $payload = [
                    'title' => $this->requiredValue($row, 'title', "quick_links.{$index}"),
                    'is_active' => $this->requiredValue($row, 'is_active', "quick_links.{$index}"),
                    ...$this->destinationPayload($row, "quick_links.{$index}"),
                ];

                $this->withValidationPath(
                    "quick_links.{$index}",
                    fn () => $this->homepage->updateQuickLink($actor, $record, $payload),
                );
            }

            $this->homepage->reorderQuickLinks($actor, array_map(
                fn (array $pair): int => (int) $pair[0]->getKey(),
                $quickRows,
            ));

            $cards = HomepageCategoryCard::query()->ordered()->lockForUpdate()->get();
            $cardRows = $this->fixedRows(
                $data['category_cards'] ?? null,
                $cards,
                ['id', 'title', 'destination', 'external_url', 'is_active'],
                'category_cards',
            );

            foreach ($cardRows as $index => [$record, $row]) {
                $payload = [
                    'title' => $this->requiredValue($row, 'title', "category_cards.{$index}"),
                    'is_active' => $this->requiredValue($row, 'is_active', "category_cards.{$index}"),
                    ...$this->destinationPayload($row, "category_cards.{$index}"),
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
                    'is_active' => $record->is_active,
                ])->all(),
            'delivery_methods' => DeliveryMethodSetting::query()->ordered()->get()
                ->map(fn (DeliveryMethodSetting $record): array => [
                    'id' => $record->getKey(),
                    '_label' => $record->code->label(),
                    'title' => $record->title,
                    'description' => $record->description,
                    'base_price' => $record->base_price,
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
                ['id', 'title', 'description', 'is_active'],
                'payment_methods',
            );
            foreach ($paymentRows as $index => [$record, $row]) {
                $this->withValidationPath("payment_methods.{$index}", fn () => $this->paymentMethods->update($actor, $record, [
                    'title' => $this->requiredValue($row, 'title', "payment_methods.{$index}"),
                    'description' => $row['description'] ?? null,
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
                ['id', 'title', 'description', 'base_price', 'is_active'],
                'delivery_methods',
            );
            foreach ($deliveryRows as $index => [$record, $row]) {
                $this->withValidationPath("delivery_methods.{$index}", fn () => $this->deliveryMethods->update($actor, $record, [
                    'title' => $this->requiredValue($row, 'title', "delivery_methods.{$index}"),
                    'description' => $row['description'] ?? null,
                    'base_price' => $this->requiredValue($row, 'base_price', "delivery_methods.{$index}"),
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

    /** @return array<string, mixed> */
    private function destinationState(HomepageQuickLink|HomepageCategoryCard $record): array
    {
        $type = $record->link_type;
        $destination = match ($type) {
            NavigationLinkType::Route => 'route:'.$record->route_name,
            NavigationLinkType::Url => self::DESTINATION_EXTERNAL,
            default => self::DESTINATION_NONE,
        };

        return [
            'id' => $record->getKey(),
            '_label' => $record->code->value,
            'title' => $record->title,
            'destination' => $destination,
            'external_url' => $type === NavigationLinkType::Url ? $record->url : null,
            'is_active' => $record->is_active,
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function destinationPayload(array $row, string $path): array
    {
        $destination = $this->requiredValue($row, 'destination', $path);
        if (! is_string($destination)) {
            $this->validationError("{$path}.destination", 'Выберите назначение из списка.');
        }

        $externalUrl = $row['external_url'] ?? null;

        if ($destination === self::DESTINATION_NONE) {
            if (is_string($externalUrl) && trim($externalUrl) !== '') {
                $this->validationError("{$path}.external_url", 'URL можно указывать только для назначения «Внешняя ссылка».');
            }

            return ['link_type' => null];
        }

        if ($destination === self::DESTINATION_EXTERNAL) {
            if (! is_string($externalUrl)) {
                $this->validationError("{$path}.external_url", 'Укажите адрес внешней ссылки.');
            }

            $externalUrl = trim($externalUrl);
            if ($externalUrl === '') {
                $this->validationError("{$path}.external_url", 'Укажите адрес внешней ссылки.');
            }
            if (mb_strlen($externalUrl) > 2048) {
                $this->validationError("{$path}.external_url", 'Адрес внешней ссылки слишком длинный.');
            }
            if (strip_tags($externalUrl) !== $externalUrl
                || filter_var($externalUrl, FILTER_VALIDATE_URL) === false
                || ! in_array(mb_strtolower((string) parse_url($externalUrl, PHP_URL_SCHEME)), ['http', 'https'], true)
                || blank(parse_url($externalUrl, PHP_URL_HOST))) {
                $this->validationError("{$path}.external_url", 'URL должен быть абсолютным и использовать протокол http или https.');
            }

            return [
                'link_type' => NavigationLinkType::Url->value,
                'url' => $externalUrl,
            ];
        }

        if (! str_starts_with($destination, 'route:')) {
            $this->validationError("{$path}.destination", 'Выбрано неизвестное назначение.');
        }

        $route = substr($destination, 6);
        if (! array_key_exists($route, self::ROUTE_DESTINATIONS)) {
            $this->validationError("{$path}.destination", 'Выбран неизвестный раздел сайта.');
        }
        if (is_string($externalUrl) && trim($externalUrl) !== '') {
            $this->validationError("{$path}.external_url", 'URL можно указывать только для назначения «Внешняя ссылка».');
        }

        return [
            'link_type' => NavigationLinkType::Route->value,
            'route_name' => $route,
        ];
    }

    private function homepageSectionLabel(HomepageSection $section): string
    {
        return match ($section->code->value) {
            'quick_links' => 'Быстрые ссылки',
            'vehicle_search' => 'Быстрый поиск запчастей',
            'category_cards' => 'Витринные категории',
            'about_metrics' => 'Показатели компании',
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

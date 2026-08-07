<?php

use App\Enums\DeliveryMethod;
use App\Enums\HomepageCategoryCardCode;
use App\Enums\LegalDocumentCode;
use App\Enums\PaymentMethod;
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
use App\Models\LegalDocument;
use App\Models\PartType;
use App\Models\PaymentMethodSetting;
use App\Models\ProductCategory;
use App\Models\ShopSetting;
use App\Models\StaticPage;
use App\Models\StaticPageItem;
use App\Models\StaticPageSection;
use Database\Seeders\CheckoutMethodSettingsSeeder;
use Database\Seeders\FaqSeeder;
use Database\Seeders\HomepageContentSeeder;
use Database\Seeders\LegalDocumentsSeeder;
use Database\Seeders\ShopSettingsSeeder;
use Database\Seeders\StaticPageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('seeded editable content matches every current frontend business text without markup or duplicate payment cards', function (): void {
    $sill = PartType::factory()->create(['title' => 'Порог']);
    $arch = PartType::factory()->create(['title' => 'Арка']);
    PartType::factory()->childOf($arch)->create(['title' => 'Передняя']);
    PartType::factory()->childOf($arch)->create(['title' => 'Задняя']);
    $body = ProductCategory::factory()->create(['title' => 'Кузовные детали', 'slug' => 'kuzovnye-detali']);
    ProductCategory::factory()->forParent($body)->create([
        'title' => 'Ремонтные элементы кузова',
        'slug' => 'remontnye-elementy-kuzova',
    ]);

    $this->seed([
        ShopSettingsSeeder::class,
        HomepageContentSeeder::class,
        StaticPageContentSeeder::class,
        FaqSeeder::class,
        CheckoutMethodSettingsSeeder::class,
        LegalDocumentsSeeder::class,
    ]);

    expect(ShopSetting::query()->sole()->phone_href)->toBe('+78001005625')
        ->and(HomepageSection::query()->count())->toBe(4)
        ->and(HomepageQuickLink::query()->count())->toBe(7)
        ->and(HomepageCategoryCard::query()->count())->toBe(5)
        ->and(HomepageMetric::query()->count())->toBe(5)
        ->and(StaticPage::query()->count())->toBe(5)
        ->and(StaticPageSection::query()->count())->toBe(8)
        ->and(StaticPageItem::query()->count())->toBe(24)
        ->and(FaqCategory::query()->count())->toBe(6)
        ->and(FaqItem::query()->count())->toBe(18)
        ->and(PaymentMethodSetting::query()->count())->toBe(4)
        ->and(DeliveryMethodSetting::query()->count())->toBe(4)
        ->and(LegalDocument::query()->count())->toBe(4)
        ->and(HomepageCategoryCard::query()->where('code', HomepageCategoryCardCode::Sills)->value('part_type_id'))->toBe($sill->getKey());

    $about = StaticPage::query()->where('code', StaticPageCode::About->value)->firstOrFail();
    $aboutHero = StaticPageSection::query()->where('code', StaticPageSectionCode::AboutHero->value)->firstOrFail();
    $technology = StaticPageSection::query()->where('code', StaticPageSectionCode::AboutTechnologies->value)->firstOrFail();
    $goal = StaticPageSection::query()->where('code', StaticPageSectionCode::AboutGoal->value)->firstOrFail();
    $chooseStep = StaticPageItem::query()->where('code', StaticPageItemCode::HowStepChoose->value)->firstOrFail();

    expect($about->title)->toBe('О нас')
        ->and($aboutHero->label)->toBe('О компании')
        ->and($aboutHero->title)->toBe('Наша экспертиза — ваше преимущество!')
        ->and($aboutHero->body)->toBe('С 2014 года мы специализируемся на производстве высококачественных автомобильных кузовных деталей: ремонтных порогов, арок, ремкомплектов дверей, багажника и пола')
        ->and(StaticPageItem::query()->where('code', StaticPageItemCode::AboutMetricParts->value)->value('title'))->toBe('150 000+ деталей')
        ->and(StaticPageItem::query()->where('code', StaticPageItemCode::AboutMetricModels->value)->value('title'))->toBe('3000 моделей автомобилей')
        ->and($technology->title)->toBe('Технологии точности')
        ->and($goal->body)->toBe('предлагать надежные и точные решения, которые экономят ваше время и деньги, сохраняя высокое качество ремонта.')
        ->and($chooseStep->text)->toBe('Оставьте заявку, самостоятельно подобрав товар в каталоге и оформив заказ через корзину, либо позвоните по бесплатному номеру:');

    expect(StaticPageItem::query()
        ->whereIn('code', [
            StaticPageItemCode::HowStepChoose->value,
            StaticPageItemCode::HowStepConfirm->value,
            StaticPageItemCode::HowStepPrepare->value,
            StaticPageItemCode::HowStepHandover->value,
            StaticPageItemCode::HowStepReceive->value,
            StaticPageItemCode::HowStepPay->value,
        ])->count())->toBe(6)
        ->and(StaticPageItem::query()->where('code', StaticPageItemCode::PartnersBenefitPrices->value)->value('title'))->toBe('Специальные цены на детали')
        ->and(StaticPageItem::query()->where('code', StaticPageItemCode::PartnersTypeDropshipping->value)->value('title'))->toBe('Дропшиппинг')
        ->and(StaticPageItem::query()->where('code', StaticPageItemCode::PartnersAboutReturns->value)->value('text'))->toBe('Удобный обмен и лёгкий возврат по заказам')
        ->and(StaticPageSection::query()->whereHas('page', fn ($query) => $query->where('code', StaticPageCode::Payment->value))->count())->toBe(0)
        ->and(StaticPage::query()->whereNotNull('primary_action_label')->exists())->toBeFalse()
        ->and(StaticPage::query()->whereNotNull('secondary_action_label')->exists())->toBeFalse()
        ->and(StaticPageItem::query()->whereIn('code', [
            StaticPageItemCode::AboutTechnologySteel->value,
            StaticPageItemCode::AboutTechnologyScan->value,
            StaticPageItemCode::AboutTechnologyCnc->value,
        ])->whereNotNull('label')->exists())->toBeFalse()
        ->and(StaticPageItem::query()->whereIn('code', [
            StaticPageItemCode::HowStepChoose->value,
            StaticPageItemCode::HowStepConfirm->value,
            StaticPageItemCode::HowStepPrepare->value,
            StaticPageItemCode::HowStepHandover->value,
            StaticPageItemCode::HowStepReceive->value,
            StaticPageItemCode::HowStepPay->value,
            StaticPageItemCode::PartnersTypeRetail->value,
            StaticPageItemCode::PartnersTypeService->value,
            StaticPageItemCode::PartnersTypeOnline->value,
        ])->where(fn ($query) => $query->where('title', 'like', "%\n%")
            ->orWhere('text', 'like', "%\n%"))->exists())->toBeFalse();

    $transport = DeliveryMethodSetting::query()->where('code', DeliveryMethod::TransportCompany)->firstOrFail();
    $pickup = DeliveryMethodSetting::query()->where('code', DeliveryMethod::Pickup)->firstOrFail();
    $card = PaymentMethodSetting::query()->where('code', PaymentMethod::Card)->firstOrFail();
    $sbp = PaymentMethodSetting::query()->where('code', PaymentMethod::Sbp)->firstOrFail();
    $invoice = PaymentMethodSetting::query()->where('code', PaymentMethod::Invoice)->firstOrFail();
    $cash = PaymentMethodSetting::query()->where('code', PaymentMethod::CashOnDelivery)->firstOrFail();

    expect($transport->title)->toBe('Пункт выдачи СДЕК')
        ->and($transport->description)->toBe('Наш менеджер подберёт ближайший пункт выдачи')
        ->and($pickup->title)->toBe('Самовывоз')
        ->and($pickup->description)->toBe('Если вы из Санкт-Петербурга')
        ->and($card->title)->toBe('Банковская карта')
        ->and($card->description)->toBe('онлайн после подтверждения')
        ->and($sbp->title)->toBe('СБП')
        ->and($sbp->description)->toBe('Перевод по QR или ссылке')
        ->and($invoice->title)->toBe('Счёт для юрлиц')
        ->and($invoice->description)->toBe('С НДС')
        ->and($cash->title)->toBe('При получении')
        ->and($cash->description)->toBe('курьеру / на складе');

    $paymentPageCards = [
        [
            'title' => $cash->page_title,
            'description' => $cash->page_description,
            'expected_title' => 'Наличный расчет или оплата картой',
            'expected_description' => 'При получении товара на нашем складе, в пункте выдачи транспортной компании в вашем городе или при доставке товара по указанному вами адресу',
        ],
        [
            'title' => $invoice->page_title,
            'description' => $invoice->page_description,
            'expected_title' => 'Безналичный расчёт для юридических лиц',
            'expected_description' => 'Осуществляется юридическими лицами путём перечисления денежных средств на расчётный счёт нашей компании на основании выставленного счёта',
        ],
        [
            'title' => $transport->page_title,
            'description' => $transport->page_description,
            'expected_title' => 'Доставка транспортной компанией',
            'expected_description' => 'При получении товара на нашем складе, в пункте выдачи транспортной компании в Вашем городе или при доставке товара по указанному вами адресу',
        ],
    ];

    foreach ($paymentPageCards as $cardContent) {
        expect($cardContent['title'])->toBe($cardContent['expected_title'])
            ->and($cardContent['description'])->toBe($cardContent['expected_description']);
    }

    $paymentPageText = collect($paymentPageCards)
        ->flatMap(fn (array $cardContent): array => [$cardContent['expected_title'], $cardContent['expected_description']])
        ->all();

    expect(StaticPageItem::query()
        ->where(fn ($query) => $query
            ->whereIn('title', $paymentPageText)
            ->orWhereIn('text', $paymentPageText))
        ->exists())->toBeFalse();

    $plainText = collect([
        ...ShopSetting::query()->get()->flatMap->getAttributes()->filter()->all(),
        ...HomepageSection::query()->get()->flatMap->getAttributes()->filter()->all(),
        ...HomepageQuickLink::query()->get()->flatMap->getAttributes()->filter()->all(),
        ...HomepageCategoryCard::query()->get()->flatMap->getAttributes()->filter()->all(),
        ...HomepageMetric::query()->get()->flatMap->getAttributes()->filter()->all(),
        ...StaticPage::query()->get()->flatMap->getAttributes()->filter()->all(),
        ...StaticPageSection::query()->get()->flatMap->getAttributes()->filter()->all(),
        ...StaticPageItem::query()->get()->flatMap->getAttributes()->filter()->all(),
        ...FaqCategory::query()->get()->flatMap->getAttributes()->filter()->all(),
        ...FaqItem::query()->get()->flatMap->getAttributes()->filter()->all(),
        ...PaymentMethodSetting::query()->get()->flatMap->getAttributes()->filter()->all(),
        ...DeliveryMethodSetting::query()->get()->flatMap->getAttributes()->filter()->all(),
        ...LegalDocument::query()->get()->flatMap->getAttributes()->filter()->all(),
    ])->filter(fn (mixed $value): bool => is_string($value))->implode("\n");

    expect($plainText)->not->toMatch('/<\/?(?:script|style|iframe|strong|br)\b/i')
        ->not->toMatch('/<[^>]+>/')
        ->not->toContain('+7 (777) 777-77-77')
        ->not->toContain('+7 (906) 244-41-51')
        ->not->toContain('+7 (939) 555-49-25')
        ->not->toContain('info@example.ru');

    expect(LegalDocument::query()->get()
        ->map(fn (LegalDocument $document): string => $document->code->value)
        ->all())->toEqualCanonicalizing(array_column(LegalDocumentCode::cases(), 'value'));
});

<?php

namespace App\Services\Storefront;

use App\Enums\StaticPageCode;
use App\Enums\StaticPageItemCode;
use App\Enums\StaticPageSectionCode;
use App\ViewData\Storefront\GlobalStorefrontData;
use App\ViewData\Storefront\PartnersPageViewData;

final readonly class PartnersPageViewDataProvider
{
    public function __construct(
        private GlobalStorefrontData $global,
        private StaticPageContentReader $pages,
        private StorefrontSeoFactory $seo,
        private StorefrontTextPresenter $text,
    ) {}

    public function load(): PartnersPageViewData
    {
        $snapshot = $this->pages->read(StaticPageCode::Partners);
        $this->pages->warnForMissingSections(StaticPageCode::Partners, $snapshot, [
            StaticPageSectionCode::PartnersBenefits->value,
            StaticPageSectionCode::PartnersCooperation->value,
            StaticPageSectionCode::PartnersAbout->value,
        ]);

        $benefitSection = $snapshot?->sections[StaticPageSectionCode::PartnersBenefits->value] ?? null;
        $benefitCodes = [
            StaticPageItemCode::PartnersBenefitPrices->value,
            StaticPageItemCode::PartnersBenefitManager->value,
            StaticPageItemCode::PartnersBenefitRussia->value,
            StaticPageItemCode::PartnersBenefitPriority->value,
        ];
        $benefits = [];

        foreach ($benefitCodes as $code) {
            $item = $benefitSection['items'][$code] ?? null;

            if ($item === null) {
                $this->pages->warnForMissingItems(StaticPageCode::Partners, $snapshot, StaticPageSectionCode::PartnersBenefits->value, [$code]);

                continue;
            }

            $title = $this->text->plain($item['title']);

            if ($title !== null) {
                $benefits[] = $title;
            }
        }

        $cooperationSection = $snapshot?->sections[StaticPageSectionCode::PartnersCooperation->value] ?? null;
        $typeDefinitions = [
            StaticPageItemCode::PartnersTypeRetail->value => ['icon' => '/img/partners/coop-opt.svg', 'modifier' => 'opt', 'break' => 'и розничные'],
            StaticPageItemCode::PartnersTypeService->value => ['icon' => '/img/partners/coop-sto.svg', 'modifier' => 'sto', 'break' => 'кузовные сервисы'],
            StaticPageItemCode::PartnersTypeOnline->value => ['icon' => '/img/partners/coop-online.svg', 'modifier' => 'online', 'break' => 'запчастей'],
            StaticPageItemCode::PartnersTypeDropshipping->value => ['icon' => '/img/partners/coop-dropship.svg', 'modifier' => 'dropship', 'break' => null],
        ];
        $partnerTypes = [];

        foreach ($typeDefinitions as $code => $definition) {
            $item = $cooperationSection['items'][$code] ?? null;

            if ($item === null) {
                $this->pages->warnForMissingItems(StaticPageCode::Partners, $snapshot, StaticPageSectionCode::PartnersCooperation->value, [$code]);

                continue;
            }

            $titleLines = $this->text->lines($item['title'], $definition['break']);

            if ($titleLines === []) {
                continue;
            }

            $partnerTypes[] = [
                'code' => $code,
                'icon' => $definition['icon'],
                'modifier' => $definition['modifier'],
                'title_lines' => $titleLines,
            ];
        }

        $aboutSection = $snapshot?->sections[StaticPageSectionCode::PartnersAbout->value] ?? null;
        $factDefinitions = [
            StaticPageItemCode::PartnersAboutProduction->value => ['Собственное производство.', '1 день'],
            StaticPageItemCode::PartnersAboutMeasurements->value => ['3000'],
            StaticPageItemCode::PartnersAboutPayment->value => ['Оплата при получении.'],
            StaticPageItemCode::PartnersAboutMaterials->value => ['от 0,8 до 1.5 мм'],
            StaticPageItemCode::PartnersAboutReturns->value => ['обмен', 'возврат'],
        ];
        $facts = [];

        foreach ($factDefinitions as $code => $phrases) {
            $item = $aboutSection['items'][$code] ?? null;

            if ($item === null) {
                $this->pages->warnForMissingItems(StaticPageCode::Partners, $snapshot, StaticPageSectionCode::PartnersAbout->value, [$code]);

                continue;
            }

            $segments = $this->text->segments($item['text'], $phrases);

            if ($segments !== []) {
                $facts[] = ['code' => $code, 'segments' => $segments];
            }
        }

        $title = $this->text->plain($snapshot?->title) ?? 'Преимущества работы с AVTOPOROGI.RU';
        $subtitle = $this->text->plain($snapshot?->subtitle);
        $cooperationTitle = $this->text->plain($cooperationSection['title'] ?? null);
        $aboutTitle = $this->text->plain($aboutSection['title'] ?? null);

        return new PartnersPageViewData(
            titleLines: $this->text->lines($title, 'с AVTOPOROGI.RU'),
            subtitleSegments: $this->text->segments($subtitle, ['специальные условия']),
            benefits: $benefits,
            cooperationTitleLines: $this->text->lines($cooperationTitle, 'к сотрудничеству'),
            partnerTypes: $partnerTypes,
            aboutTitle: $aboutTitle,
            facts: $facts,
            seo: $this->seo->page(
                pageTitle: $title,
                description: $subtitle,
                canonical: route('partners'),
                storeName: $this->global->storeName,
            ),
        );
    }
}

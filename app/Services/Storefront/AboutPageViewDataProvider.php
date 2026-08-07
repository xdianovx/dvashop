<?php

namespace App\Services\Storefront;

use App\Enums\StaticPageCode;
use App\Enums\StaticPageItemCode;
use App\Enums\StaticPageSectionCode;
use App\ViewData\Storefront\AboutPageViewData;
use App\ViewData\Storefront\GlobalStorefrontData;

final readonly class AboutPageViewDataProvider
{
    public function __construct(
        private GlobalStorefrontData $global,
        private StaticPageContentReader $pages,
        private StorefrontSeoFactory $seo,
        private StorefrontTextPresenter $text,
    ) {}

    public function load(): AboutPageViewData
    {
        $snapshot = $this->pages->read(StaticPageCode::About);
        $this->pages->warnForMissingSections(StaticPageCode::About, $snapshot, [
            StaticPageSectionCode::AboutHero->value,
            StaticPageSectionCode::AboutMetrics->value,
            StaticPageSectionCode::AboutTechnologies->value,
            StaticPageSectionCode::AboutGoal->value,
        ]);

        $heroSection = $snapshot?->sections[StaticPageSectionCode::AboutHero->value] ?? null;
        $hero = null;

        if ($heroSection !== null) {
            $body = $this->text->plain($heroSection['body']);
            $prefix = 'С 2014 года';
            $leadPrefix = $body !== null && str_starts_with($body, $prefix) ? $prefix : null;
            $leadText = $leadPrefix === null ? $body : ltrim(mb_substr($body, mb_strlen($prefix)));

            $hero = [
                'badge' => $this->text->plain($heroSection['label']),
                'title_lines' => $this->text->lines($heroSection['title'], 'ваше'),
                'lead_prefix' => $leadPrefix,
                'lead_text' => $leadText,
            ];
        }

        $metricSection = $snapshot?->sections[StaticPageSectionCode::AboutMetrics->value] ?? null;
        $metricDefinitions = [
            StaticPageItemCode::AboutMetricParts->value => '/img/about-page/metric-1.svg',
            StaticPageItemCode::AboutMetricModels->value => '/img/about-page/metric-2.svg',
        ];
        $metrics = [];

        foreach ($metricDefinitions as $code => $icon) {
            $item = $metricSection['items'][$code] ?? null;

            if ($item === null) {
                $this->pages->warnForMissingItems(StaticPageCode::About, $snapshot, StaticPageSectionCode::AboutMetrics->value, [$code]);

                continue;
            }

            $title = $this->text->plain($item['title']);

            if ($title === null) {
                continue;
            }

            $metrics[] = [
                'title' => $title,
                'text' => $this->text->plain($item['text']),
                'icon' => $icon,
            ];
        }

        $technologySection = $snapshot?->sections[StaticPageSectionCode::AboutTechnologies->value] ?? null;
        $technologyDefinitions = [
            StaticPageItemCode::AboutTechnologySteel->value => ['number' => '01', 'strong' => ['0,8 - 1,5 мм,']],
            StaticPageItemCode::AboutTechnologyScan->value => ['number' => '02', 'strong' => ['3D-сканирование']],
            StaticPageItemCode::AboutTechnologyCnc->value => ['number' => '03', 'strong' => ['Современное ЧПУ-оборудование']],
        ];
        $technologyItems = [];

        foreach ($technologyDefinitions as $code => $definition) {
            $item = $technologySection['items'][$code] ?? null;

            if ($item === null) {
                $this->pages->warnForMissingItems(StaticPageCode::About, $snapshot, StaticPageSectionCode::AboutTechnologies->value, [$code]);

                continue;
            }

            $segments = $this->text->segments($item['text'], $definition['strong']);

            if ($segments === []) {
                continue;
            }

            $technologyItems[] = [
                'number' => $definition['number'],
                'segments' => $segments,
            ];
        }

        $technologies = $technologySection === null ? null : [
            'title' => $this->text->plain($technologySection['title']),
            'subtitle' => $this->text->plain($technologySection['subtitle']),
            'items' => $technologyItems,
        ];

        $goalSection = $snapshot?->sections[StaticPageSectionCode::AboutGoal->value] ?? null;
        $goal = $goalSection === null ? null : [
            'label' => $this->text->plain($goalSection['label']),
            'body' => $this->text->plain($goalSection['body']),
        ];
        $title = $this->text->plain($snapshot?->title) ?? 'О нас';

        return new AboutPageViewData(
            title: $title,
            hero: $hero,
            metrics: $metrics,
            technologies: $technologies,
            goal: $goal,
            seo: $this->seo->page(
                pageTitle: $title,
                description: $heroSection['body'] ?? $snapshot?->subtitle,
                canonical: route('about'),
                storeName: $this->global->storeName,
            ),
        );
    }
}

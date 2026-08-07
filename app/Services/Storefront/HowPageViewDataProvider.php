<?php

namespace App\Services\Storefront;

use App\Enums\StaticPageCode;
use App\Enums\StaticPageItemCode;
use App\Enums\StaticPageSectionCode;
use App\ViewData\Storefront\GlobalStorefrontData;
use App\ViewData\Storefront\HowPageViewData;

final readonly class HowPageViewDataProvider
{
    public function __construct(
        private GlobalStorefrontData $global,
        private StaticPageContentReader $pages,
        private StorefrontSeoFactory $seo,
        private StorefrontTextPresenter $text,
    ) {}

    public function load(): HowPageViewData
    {
        $snapshot = $this->pages->read(StaticPageCode::How);
        $sectionCode = StaticPageSectionCode::HowSteps->value;
        $this->pages->warnForMissingSections(StaticPageCode::How, $snapshot, [$sectionCode]);
        $items = $snapshot?->sections[$sectionCode]['items'] ?? [];

        $definitions = [
            StaticPageItemCode::HowStepChoose->value => ['number' => '1', 'icon' => '/img/how/step-1.svg', 'break' => 'и оставляете', 'strong' => [], 'break_after' => null],
            StaticPageItemCode::HowStepConfirm->value => ['number' => '2', 'icon' => '/img/how/step-2.svg', 'break' => 'и уточняем', 'strong' => [], 'break_after' => null],
            StaticPageItemCode::HowStepPrepare->value => ['number' => '3', 'icon' => '/img/how/step-3.svg', 'break' => 'заказ к отправке', 'strong' => [], 'break_after' => null],
            StaticPageItemCode::HowStepHandover->value => ['number' => '4', 'icon' => '/img/how/step-4.svg', 'break' => 'в службу доставки', 'strong' => ['СДЭК.'], 'break_after' => 'СДЭК.'],
            StaticPageItemCode::HowStepReceive->value => ['number' => '5', 'icon' => '/img/how/step-5.svg', 'break' => 'Ваш заказ', 'strong' => [], 'break_after' => null],
            StaticPageItemCode::HowStepPay->value => ['number' => '6', 'icon' => '/img/how/step-6.svg', 'break' => 'при получении', 'strong' => [], 'break_after' => null],
        ];
        $steps = [];

        foreach ($definitions as $code => $definition) {
            $item = $items[$code] ?? null;

            if ($item === null) {
                $this->pages->warnForMissingItems(StaticPageCode::How, $snapshot, $sectionCode, [$code]);

                continue;
            }

            $titleLines = $this->text->lines($item['title'], $definition['break']);
            $plainText = $this->text->plain($item['text']);

            if ($titleLines === [] || $plainText === null) {
                continue;
            }

            $segments = array_map(function (array $segment) use ($definition): array {
                $breakAfter = $definition['break_after'];

                return [
                    ...$segment,
                    'break_after' => is_string($breakAfter)
                        && mb_strtolower($segment['text']) === mb_strtolower($breakAfter),
                ];
            }, $this->text->segments($plainText, $definition['strong']));

            $steps[] = [
                'code' => $code,
                'number' => $definition['number'],
                'icon' => $definition['icon'],
                'title_lines' => $titleLines,
                'text' => $plainText,
                'segments' => $segments,
                'show_phone' => $code === StaticPageItemCode::HowStepChoose->value,
            ];
        }

        $title = $this->text->plain($snapshot?->title) ?? 'Как мы работаем';

        return new HowPageViewData(
            title: $title,
            steps: $steps,
            seo: $this->seo->page(
                pageTitle: $title,
                description: $steps[0]['text'] ?? null,
                canonical: route('how'),
                storeName: $this->global->storeName,
            ),
        );
    }
}

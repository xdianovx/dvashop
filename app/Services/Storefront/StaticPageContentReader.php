<?php

namespace App\Services\Storefront;

use App\Enums\StaticPageCode;
use App\Models\StaticPage;
use App\Models\StaticPageItem;
use App\Models\StaticPageSection;
use Illuminate\Support\Facades\Log;

final class StaticPageContentReader
{
    public function read(StaticPageCode $code): ?StaticPageSnapshot
    {
        $page = StaticPage::query()
            ->active()
            ->where('code', $code->value)
            ->first(['id', 'title', 'subtitle']);

        if (! $page instanceof StaticPage) {
            $this->warn($code->value, 'page');

            return null;
        }

        $sections = StaticPageSection::query()
            ->active()
            ->where('static_page_id', $page->getKey())
            ->ordered()
            ->get(['id', 'code', 'label', 'title', 'subtitle', 'body']);

        $items = $sections->isEmpty()
            ? collect()
            : StaticPageItem::query()
                ->active()
                ->whereIn('static_page_section_id', $sections->modelKeys())
                ->ordered()
                ->get(['static_page_section_id', 'code', 'label', 'title', 'text'])
                ->groupBy('static_page_section_id');

        $mapped = [];

        foreach ($sections as $section) {
            $sectionItems = [];

            foreach ($items->get($section->getKey(), collect()) as $item) {
                $sectionItems[$item->code->value] = [
                    'label' => $item->label,
                    'title' => $item->title,
                    'text' => $item->text,
                ];
            }

            $mapped[$section->code->value] = [
                'label' => $section->label,
                'title' => $section->title,
                'subtitle' => $section->subtitle,
                'body' => $section->body,
                'items' => $sectionItems,
            ];
        }

        return new StaticPageSnapshot(
            title: $page->title,
            subtitle: $page->subtitle,
            sections: $mapped,
        );
    }

    /** @param list<string> $requiredSections */
    public function warnForMissingSections(StaticPageCode $page, ?StaticPageSnapshot $snapshot, array $requiredSections): void
    {
        foreach ($requiredSections as $section) {
            if ($snapshot === null || ! array_key_exists($section, $snapshot->sections)) {
                $this->warn($page->value, $section);
            }
        }
    }

    /** @param list<string> $requiredItems */
    public function warnForMissingItems(StaticPageCode $page, ?StaticPageSnapshot $snapshot, string $section, array $requiredItems): void
    {
        $items = $snapshot?->sections[$section]['items'] ?? [];

        foreach ($requiredItems as $item) {
            if (! array_key_exists($item, $items)) {
                $this->warn($page->value, $item);
            }
        }
    }

    private function warn(string $page, string $code): void
    {
        Log::warning('Required storefront content record is missing or inactive.', [
            'page' => $page,
            'code' => $code,
        ]);
    }
}

<?php

namespace App\Services\Storefront;

use App\Services\Seo\SeoData;
use Illuminate\Support\Str;

final class StorefrontSeoFactory
{
    public function page(
        string $pageTitle,
        ?string $description,
        string $canonical,
        string $storeName,
    ): SeoData {
        $pageTitle = $this->plain($pageTitle) ?? 'Информация';
        $storeName = $this->plain($storeName) ?? 'AVTOPOROGI.ru';
        $description = $this->plain($description);

        return new SeoData(
            title: $pageTitle.' — '.$storeName,
            description: $description === null ? null : Str::limit($description, 160, ''),
            canonical: $canonical,
        );
    }

    public function home(string $storeName): SeoData
    {
        $storeName = $this->plain($storeName) ?? 'AVTOPOROGI.ru';

        return new SeoData(
            title: $storeName.' — кузовные пороги, арки и автотовары',
            description: 'Кузовные пороги, арки и ремонтные детали с подбором по автомобилю.',
            canonical: route('home'),
        );
    }

    public function descriptionFrom(?string ...$values): ?string
    {
        foreach ($values as $value) {
            $plain = $this->plain($value);

            if ($plain !== null) {
                return $plain;
            }
        }

        return null;
    }

    private function plain(?string $value): ?string
    {
        $value = Str::squish(strip_tags((string) $value));

        return $value === '' ? null : $value;
    }
}

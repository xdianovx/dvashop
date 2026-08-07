<?php

namespace App\Providers;

use App\Services\Storefront\AboutPageViewDataProvider;
use App\Services\Storefront\FaqPageViewDataProvider;
use App\Services\Storefront\GlobalStorefrontDataProvider;
use App\Services\Storefront\HomepageViewDataProvider;
use App\Services\Storefront\HowPageViewDataProvider;
use App\Services\Storefront\PartnersPageViewDataProvider;
use App\Services\Storefront\PaymentPageViewDataProvider;
use App\ViewData\Storefront\AboutPageViewData;
use App\ViewData\Storefront\FaqPageViewData;
use App\ViewData\Storefront\GlobalStorefrontData;
use App\ViewData\Storefront\HomepageViewData;
use App\ViewData\Storefront\HowPageViewData;
use App\ViewData\Storefront\PartnersPageViewData;
use App\ViewData\Storefront\PaymentPageViewData;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(
            GlobalStorefrontData::class,
            fn (Application $app): GlobalStorefrontData => $app->make(GlobalStorefrontDataProvider::class)->load(),
        );
        $this->app->bind(
            HomepageViewData::class,
            fn (Application $app): HomepageViewData => $app->make(HomepageViewDataProvider::class)->load(),
        );
        $this->app->bind(
            AboutPageViewData::class,
            fn (Application $app): AboutPageViewData => $app->make(AboutPageViewDataProvider::class)->load(),
        );
        $this->app->bind(
            HowPageViewData::class,
            fn (Application $app): HowPageViewData => $app->make(HowPageViewDataProvider::class)->load(),
        );
        $this->app->bind(
            PaymentPageViewData::class,
            fn (Application $app): PaymentPageViewData => $app->make(PaymentPageViewDataProvider::class)->load(),
        );
        $this->app->bind(
            FaqPageViewData::class,
            fn (Application $app): FaqPageViewData => $app->make(FaqPageViewDataProvider::class)->load(),
        );
        $this->app->bind(
            PartnersPageViewData::class,
            fn (Application $app): PartnersPageViewData => $app->make(PartnersPageViewDataProvider::class)->load(),
        );
    }

    public function boot(): void
    {
        //
    }
}

<?php

use App\Models\FaqCategory;
use App\Models\StaticPage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('public information pages fail soft without optional seeded content', function (string $routeName): void {
    expect(StaticPage::query()->count())->toBe(0)
        ->and(FaqCategory::query()->count())->toBe(0);

    $this->get(route($routeName))->assertOk();
})->with(['home', 'about', 'how', 'payment', 'faq', 'partners']);

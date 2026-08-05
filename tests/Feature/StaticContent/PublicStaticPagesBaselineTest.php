<?php

use App\Models\FaqCategory;
use App\Models\StaticPage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('public static pages remain hardcoded and respond without a9 seed data', function (string $uri): void {
    expect(StaticPage::query()->count())->toBe(0)
        ->and(FaqCategory::query()->count())->toBe(0);

    $this->get($uri)->assertOk();
})->with(['/about', '/how', '/payment', '/faq', '/partners']);

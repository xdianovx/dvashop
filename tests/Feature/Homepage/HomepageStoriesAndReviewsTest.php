<?php

use App\Enums\HomepageSectionCode;
use App\Models\HomepageSection;
use App\Models\HomepageStoryGroup;
use App\Models\HomepageStoryItem;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\SiteContent\SitePageContentAdminService;
use Database\Seeders\HomepageContentSeeder;
use Database\Seeders\ShopSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    $this->seed([ShopSettingsSeeder::class, HomepageContentSeeder::class]);
});

test('active stories render existing media safe cta and accessible modal controls', function (): void {
    Storage::disk('public')->put('uploads/homepage/stories/cover.jpg', 'cover');
    Storage::disk('public')->put('uploads/homepage/stories/image.jpg', 'image');
    Storage::disk('public')->put('uploads/homepage/stories/video.mp4', 'video');

    $group = HomepageStoryGroup::factory()->create([
        'title' => 'Производство',
        'cover_image_path' => 'uploads/homepage/stories/cover.jpg',
    ]);
    HomepageStoryItem::factory()->for($group, 'group')->create([
        'media_path' => 'uploads/homepage/stories/image.jpg',
        'cta_label' => 'В каталог',
        'cta_url' => '/catalog',
        'open_in_new_tab' => false,
        'position' => 10,
    ]);
    HomepageStoryItem::factory()->for($group, 'group')->create([
        'media_type' => 'video',
        'media_path' => 'uploads/homepage/stories/video.mp4',
        'duration_seconds' => null,
        'cta_label' => 'Подробнее',
        'cta_url' => 'https://example.com/story',
        'open_in_new_tab' => true,
        'position' => 20,
    ]);

    $response = $this->get(route('home'))->assertOk()
        ->assertSee('hero-circles-section', false)
        ->assertSee('data-story-modal', false)
        ->assertSee('role="dialog"', false)
        ->assertSee('aria-modal="true"', false)
        ->assertSee('data-story-progress', false)
        ->assertSee('data-story-pause', false)
        ->assertSee('data-story-media-fallback', false)
        ->assertSee('Медиа недоступно')
        ->assertSee('<video', false)
        ->assertSee('playsinline muted preload="metadata"', false)
        ->assertSee('href="/catalog"', false)
        ->assertSee('target="_blank" rel="noopener noreferrer"', false);

    expect($response->getContent())->toContain('Производство');
});

test('review lab uses exact trusted contract once and disappears completely when inactive', function (): void {
    HomepageSection::query()->where('code', HomepageSectionCode::Reviews)->update(['title' => 'Проверенные отзывы']);

    $active = $this->get(route('home'))->assertOk()
        ->assertSee('Проверенные отзывы')
        ->assertSee('<review-lab data-widgetid="69984c4658896b169079008c"></review-lab>', false)
        ->assertSee('<script src="https://app.reviewlab.ru/widget/index-es2015.js" defer></script>', false);
    expect(substr_count($active->getContent(), 'https://app.reviewlab.ru/widget/index-es2015.js'))->toBe(1);

    HomepageSection::query()->where('code', HomepageSectionCode::Reviews)->update(['is_active' => false]);
    $inactive = $this->get(route('home'))->assertOk();
    expect($inactive->getContent())->not->toContain('<review-lab')
        ->not->toContain('69984c4658896b169079008c')
        ->not->toContain('https://app.reviewlab.ru/widget/index-es2015.js');
});

test('review provider widget id and raw code cannot be changed through homepage payload', function (): void {
    $service = app(SitePageContentAdminService::class);
    $admin = User::factory()->admin()->create();
    $state = $service->homepageState();
    $state['reviews_section']['widget_id'] = 'forged';

    try {
        $service->saveHomepage($admin, $state);
        $this->fail('Ожидалась ошибка whitelist для Review Lab.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('reviews_section.widget_id');
    }

    $state = $service->homepageState();
    $state['reviews_html'] = '<script>alert(1)</script>';
    expect(fn () => $service->saveHomepage($admin, $state))->toThrow(ValidationException::class);
});

test('story javascript reuses swiper and shared modal accessibility without a new dependency', function (): void {
    $script = file_get_contents(resource_path('js/app.js'));
    $package = file_get_contents(base_path('package.json'));

    expect($script)->toContain('function createModalController(')
        ->toContain('function initStories()')
        ->toContain("initStorefrontFeature('stories', initStories)")
        ->toContain('new Swiper(swiperElement')
        ->toContain("event.key === 'ArrowLeft'")
        ->toContain("event.key === 'ArrowRight'")
        ->toContain("document.addEventListener('visibilitychange'")
        ->toContain("media.addEventListener('error'")
        ->toContain('video.muted = true')
        ->toContain('video.currentTime / video.duration')
        ->toContain('handlePlayRejection')
        ->toContain('scheduleFailedMediaAdvance')
        ->toContain('window.requestAnimationFrame(runFrame)')
        ->and($package)->toContain('"swiper"')
        ->not->toContain('zuck');
});

test('story cta uses documented default only when a safe url exists', function (): void {
    Storage::disk('public')->put('uploads/homepage/stories/cta-cover.jpg', 'cover');
    Storage::disk('public')->put('uploads/homepage/stories/cta-image.jpg', 'image');
    $group = HomepageStoryGroup::factory()->create(['cover_image_path' => 'uploads/homepage/stories/cta-cover.jpg']);
    HomepageStoryItem::factory()->for($group, 'group')->create([
        'media_path' => 'uploads/homepage/stories/cta-image.jpg',
        'cta_label' => null,
        'cta_url' => '/catalog',
        'position' => 10,
    ]);
    HomepageStoryItem::factory()->for($group, 'group')->create([
        'media_path' => 'uploads/homepage/stories/cta-image.jpg',
        'cta_label' => 'Не показывать',
        'cta_url' => null,
        'position' => 20,
    ]);

    $response = $this->get(route('home'))->assertOk()
        ->assertSee('Посмотреть')
        ->assertDontSee('Не показывать');
    expect(substr_count($response->getContent(), 'story-modal__cta'))->toBe(1);
});

test('stories remain homepage only and never leak into a real product page', function (): void {
    Storage::disk('public')->put('uploads/homepage/stories/home-only-cover.jpg', 'cover');
    Storage::disk('public')->put('uploads/homepage/stories/home-only-image.jpg', 'image');
    $group = HomepageStoryGroup::factory()->create(['cover_image_path' => 'uploads/homepage/stories/home-only-cover.jpg']);
    HomepageStoryItem::factory()->for($group, 'group')->create(['media_path' => 'uploads/homepage/stories/home-only-image.jpg']);
    $variant = ProductVariant::factory()->create();

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('data-story-modal', false)
        ->assertSee('hero-circles-section', false);
    $this->get(route('products.show', $variant->product->slug))
        ->assertOk()
        ->assertDontSee('data-story-modal', false)
        ->assertDontSee('hero-circles-section', false);
});

test('active story group without an active item is hidden from the homepage', function (): void {
    Storage::disk('public')->put('uploads/homepage/stories/empty-cover.jpg', 'cover');
    Storage::disk('public')->put('uploads/homepage/stories/inactive-image.jpg', 'image');
    $group = HomepageStoryGroup::factory()->create(['cover_image_path' => 'uploads/homepage/stories/empty-cover.jpg']);
    HomepageStoryItem::factory()->for($group, 'group')->create([
        'media_path' => 'uploads/homepage/stories/inactive-image.jpg',
        'is_active' => false,
    ]);

    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('data-story-modal', false)
        ->assertDontSee('hero-circles-section', false);
});

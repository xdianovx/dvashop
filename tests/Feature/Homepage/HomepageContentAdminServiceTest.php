<?php

use App\Enums\HomepageCategoryCardCode;
use App\Enums\HomepageMetricCode;
use App\Enums\HomepageSectionCode;
use App\Models\HomepageCategoryCard;
use App\Models\HomepageMetric;
use App\Models\HomepageSection;
use App\Models\HomepageStoryGroup;
use App\Models\HomepageStoryItem;
use App\Models\PartType;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Homepage\HomepageContentAdminService;
use Database\Seeders\HomepageContentSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    $this->sillPartType = PartType::factory()->create(['title' => 'Порог']);
    $this->bodyCategory = ProductCategory::factory()->create(['title' => 'Ремонтные элементы кузова']);
    $this->seed(HomepageContentSeeder::class);
    $this->homepageService = app(HomepageContentAdminService::class);
    $this->homepageAdmin = User::factory()->admin()->create();
});

function storyImage(string $name): string
{
    $path = "uploads/homepage/stories/{$name}.jpg";
    Storage::disk('public')->put($path, base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABB//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPxB//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPxB//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxB//9k='));

    return $path;
}

function storyVideo(string $name): string
{
    $path = "uploads/homepage/stories/{$name}.mp4";
    Storage::disk('public')->put($path, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom");

    return $path;
}

function storyWebm(string $name): string
{
    $path = "uploads/homepage/stories/{$name}.webm";
    Storage::disk('public')->put($path, base64_decode('GkXfo59ChoEBQveBAULygQRC84EIQoKEd2VibUKHgQJChYECGFOAZwEAAAAAAAIGEU2bdLpNu4tTq4QVSalmU6yBoU27i1OrhBZUrmtTrIHYTbuMU6uEElTDZ1OsggEiTbuMU6uEHFO7a1OsggHw7AEAAAAAAABZAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAVSalmsirXsYMPQkBNgI1MYXZmNTguNzYuMTAwV0GNTGF2ZjU4Ljc2LjEwMESJiEBEAAAAAAAAFlSua8WuAQAAAAAAADzXgQFzxYiAnsWIzVAAdZyBACK1nIN1bmSGhVZfVlA5g4EBI+ODhAJiWgDgAQAAAAAAAAmwgQK6gQKagQISVMNnQJ5zcwEAAAAAAAAnY8CAZ8gBAAAAAAAAGkWjh0VOQ09ERVJEh41MYXZmNTguNzYuMTAwc3MBAAAAAAAAY2PAi2PFiICexYjNUAB1Z8gBAAAAAAAAJkWjh0VOQ09ERVJEh5lMYXZjNTguMTM0LjEwMCBsaWJ2cHgtdnA5Z8iiRaOIRFVSQVRJT05Eh5QwMDowMDowMC4wNDAwMDAwMDAAAB9DtnWl54EAo6CBAACAgkmDQgAAEAAWADgkHBhKAAAgIAARv///ka8AABxTu2uRu4+zgQC3iveBAfGCAcbwgQM='));

    return $path;
}

test('stories save creates updates reorders and removes dynamic groups and items', function (): void {
    $coverOne = storyImage('cover-one');
    $coverTwo = storyImage('cover-two');
    $image = storyImage('image');
    $video = storyVideo('video');

    $this->homepageService->saveStories($this->homepageAdmin, [
        [
            'title' => 'Новинки', 'cover_image_path' => $coverOne, 'is_active' => true,
            'items' => [[
                'media_type' => 'image', 'media_path' => $image, 'alt_text' => 'Новая деталь',
                'cta_label' => null, 'cta_url' => '/catalog', 'open_in_new_tab' => false,
                'duration_seconds' => 12, 'is_active' => true,
            ]],
        ],
        [
            'title' => 'Видео', 'cover_image_path' => $coverTwo, 'is_active' => true,
            'items' => [[
                'media_type' => 'video', 'media_path' => $video, 'alt_text' => null,
                'cta_label' => 'Смотреть', 'cta_url' => 'https://example.com/story', 'open_in_new_tab' => true,
                'duration_seconds' => 30, 'is_active' => true,
            ]],
        ],
    ]);

    $groups = HomepageStoryGroup::query()->ordered()->with('items')->get();
    expect($groups)->toHaveCount(2)
        ->and($groups[0]->title)->toBe('Новинки')
        ->and($groups[0]->items[0]->cta_label)->toBe('Посмотреть')
        ->and($groups[0]->items[0]->duration_seconds)->toBe(12)
        ->and($groups[1]->items[0]->duration_seconds)->toBeNull();

    $this->homepageService->saveStories($this->homepageAdmin, [[
        'id' => $groups[1]->getKey(), 'title' => 'Видео первым', 'cover_image_path' => $coverTwo, 'is_active' => true,
        'items' => [[
            'id' => $groups[1]->items[0]->getKey(), 'media_type' => 'video', 'media_path' => $video,
            'alt_text' => null, 'cta_label' => null, 'cta_url' => null, 'open_in_new_tab' => true,
            'duration_seconds' => null, 'is_active' => true,
        ]],
    ]]);

    expect(HomepageStoryGroup::query()->sole()->title)->toBe('Видео первым')
        ->and(HomepageStoryGroup::query()->sole()->position)->toBe(0)
        ->and(HomepageStoryItem::query()->sole()->cta_label)->toBeNull()
        ->and(HomepageStoryItem::query()->sole()->open_in_new_tab)->toBeFalse();
});

test('stories reject unsafe urls forged records missing active covers and invalid media atomically', function (): void {
    $cover = storyImage('cover');
    $image = storyImage('item');
    $base = [[
        'title' => 'Кружок', 'cover_image_path' => $cover, 'is_active' => true,
        'items' => [[
            'media_type' => 'image', 'media_path' => $image, 'alt_text' => null,
            'cta_label' => null, 'cta_url' => null, 'open_in_new_tab' => false,
            'duration_seconds' => 10, 'is_active' => true,
        ]],
    ]];
    $this->homepageService->saveStories($this->homepageAdmin, $base);
    $before = HomepageStoryGroup::query()->with('items')->get()->toArray();

    foreach (['javascript:alert(1)', 'data:text/html,x', '//example.com', 'file:///tmp/x'] as $url) {
        $payload = $base;
        $payload[0]['title'] = 'Не сохранять';
        $payload[0]['items'][0]['cta_url'] = $url;
        expect(fn () => $this->homepageService->saveStories($this->homepageAdmin, $payload))
            ->toThrow(ValidationException::class);
        expect(HomepageStoryGroup::query()->with('items')->get()->toArray())->toBe($before);
    }

    $orphanCover = storyImage('orphan-cover');
    $orphanItem = storyImage('orphan-item');
    $orphanPayload = $base;
    $orphanPayload[0]['cover_image_path'] = $orphanCover;
    $orphanPayload[0]['items'][0]['media_path'] = $orphanItem;
    $orphanPayload[0]['items'][0]['cta_url'] = 'javascript:alert(1)';
    expect(fn () => $this->homepageService->saveStories($this->homepageAdmin, $orphanPayload))->toThrow(ValidationException::class);
    Storage::disk('public')->assertMissing([$orphanCover, $orphanItem]);

    $missingCover = $base;
    $missingCover[0]['cover_image_path'] = null;
    expect(fn () => $this->homepageService->saveStories($this->homepageAdmin, $missingCover))->toThrow(ValidationException::class);

    $forged = $base;
    $forged[0]['id'] = 999999;
    expect(fn () => $this->homepageService->saveStories($this->homepageAdmin, $forged))->toThrow(ValidationException::class);

    $wrongFile = $base;
    $wrongFile[0]['items'][0]['media_path'] = 'uploads/homepage/stories/missing.jpg';
    expect(fn () => $this->homepageService->saveStories($this->homepageAdmin, $wrongFile))->toThrow(ValidationException::class);
});

test('homepage service preserves fixed sections categories metrics and authorization', function (): void {
    $section = HomepageSection::query()->where('code', HomepageSectionCode::VehicleSearch)->firstOrFail();
    $card = HomepageCategoryCard::query()->where('code', HomepageCategoryCardCode::Sills)->firstOrFail();
    $metric = HomepageMetric::query()->where('code', HomepageMetricCode::SinceYear)->firstOrFail();

    $updatedSection = $this->homepageService->updateSection($this->homepageAdmin, $section, ['title' => '  Новый заголовок  ']);
    $updatedCard = $this->homepageService->updateCategoryCard($this->homepageAdmin, $card, [
        'link_type' => null, 'route_name' => null, 'product_category_id' => $this->bodyCategory->getKey(),
        'part_type_id' => null, 'url' => null, 'open_in_new_tab' => false,
    ]);
    $updatedMetric = $this->homepageService->updateMetric($this->homepageAdmin, $metric, [
        'prefix' => '  ', 'value' => '  2015  ', 'suffix' => ' год ', 'text' => '  Проверенная экспертиза  ',
    ]);

    expect($updatedSection->title)->toBe('Новый заголовок')
        ->and($updatedCard->product_category_id)->toBe($this->bodyCategory->getKey())
        ->and($updatedMetric->prefix)->toBeNull()
        ->and($updatedMetric->value)->toBe('2015');

    $manager = User::factory()->manager()->create();
    expect(fn () => $this->homepageService->saveStories($manager, []))->toThrow(AuthorizationException::class);
});

test('fixed homepage sections reject direct structural updates and keep exact positions', function (): void {
    $section = HomepageSection::query()->where('code', HomepageSectionCode::VehicleSearch)->firstOrFail();

    foreach ([['position' => 999], ['code' => HomepageSectionCode::Reviews->value]] as $payload) {
        expect(fn () => $this->homepageService->updateSection($this->homepageAdmin, $section, $payload))
            ->toThrow(ValidationException::class);
    }

    expect(HomepageSection::query()->ordered()->pluck('position')->all())->toBe([10, 20, 30, 40, 50])
        ->and(HomepageSection::query()->ordered()->pluck('code')->map->value->all())->toBe([
            'stories', 'vehicle_search', 'category_cards', 'reviews', 'about_metrics',
        ]);
});

test('story image and video replacements clean old media only after a successful save', function (): void {
    $cover = storyImage('lifecycle-cover');
    $oldImage = storyImage('old-image');
    $newImage = storyImage('new-image');
    $oldVideo = storyVideo('old-video');
    $newVideo = storyVideo('new-video');

    $this->homepageService->saveStories($this->homepageAdmin, [[
        'title' => 'Медиа', 'cover_image_path' => $cover, 'is_active' => true,
        'items' => [
            ['media_type' => 'image', 'media_path' => $oldImage, 'is_active' => true],
            ['media_type' => 'video', 'media_path' => $oldVideo, 'is_active' => true],
        ],
    ]]);
    $group = HomepageStoryGroup::query()->with('items')->sole();

    $this->homepageService->saveStories($this->homepageAdmin, [[
        'id' => $group->getKey(), 'title' => 'Медиа', 'cover_image_path' => $cover, 'is_active' => true,
        'items' => [
            ['id' => $group->items[0]->getKey(), 'media_type' => 'image', 'media_path' => $newImage, 'is_active' => true],
            ['id' => $group->items[1]->getKey(), 'media_type' => 'video', 'media_path' => $newVideo, 'is_active' => true],
        ],
    ]]);

    Storage::disk('public')->assertMissing([$oldImage, $oldVideo]);
    Storage::disk('public')->assertExists([$cover, $newImage, $newVideo]);
});

test('failed image and video replacement preserves persisted media and cleans rejected uploads', function (): void {
    $cover = storyImage('rollback-cover');
    $oldImage = storyImage('rollback-old-image');
    $oldVideo = storyVideo('rollback-old-video');
    $newImage = storyImage('rollback-new-image');
    $newVideo = storyVideo('rollback-new-video');

    $this->homepageService->saveStories($this->homepageAdmin, [[
        'title' => 'Rollback', 'cover_image_path' => $cover, 'is_active' => true,
        'items' => [
            ['media_type' => 'image', 'media_path' => $oldImage, 'is_active' => true],
            ['media_type' => 'video', 'media_path' => $oldVideo, 'is_active' => true],
        ],
    ]]);
    $group = HomepageStoryGroup::query()->with('items')->sole();

    expect(fn () => $this->homepageService->saveStories($this->homepageAdmin, [[
        'id' => $group->getKey(), 'title' => 'Не сохранится', 'cover_image_path' => $cover, 'is_active' => true,
        'items' => [
            ['id' => $group->items[0]->getKey(), 'media_type' => 'image', 'media_path' => $newImage, 'is_active' => true],
            ['id' => $group->items[1]->getKey(), 'media_type' => 'video', 'media_path' => $newVideo, 'cta_url' => 'javascript:alert(1)', 'is_active' => true],
        ],
    ]]))->toThrow(ValidationException::class);

    expect($group->refresh()->title)->toBe('Rollback');
    Storage::disk('public')->assertExists([$cover, $oldImage, $oldVideo]);
    Storage::disk('public')->assertMissing([$newImage, $newVideo]);
});

test('story and group deletion clean all removed managed media', function (): void {
    $firstCover = storyImage('delete-first-cover');
    $secondCover = storyImage('delete-second-cover');
    $keptImage = storyImage('delete-kept-image');
    $deletedImage = storyImage('delete-item-image');
    $deletedVideo = storyVideo('delete-group-video');

    $this->homepageService->saveStories($this->homepageAdmin, [
        [
            'title' => 'Останется', 'cover_image_path' => $firstCover, 'is_active' => true,
            'items' => [
                ['media_type' => 'image', 'media_path' => $keptImage, 'is_active' => true],
                ['media_type' => 'image', 'media_path' => $deletedImage, 'is_active' => true],
            ],
        ],
        [
            'title' => 'Удалится', 'cover_image_path' => $secondCover, 'is_active' => true,
            'items' => [['media_type' => 'video', 'media_path' => $deletedVideo, 'is_active' => true]],
        ],
    ]);
    $groups = HomepageStoryGroup::query()->ordered()->with('items')->get();

    $this->homepageService->saveStories($this->homepageAdmin, [[
        'id' => $groups[0]->getKey(), 'title' => 'Останется', 'cover_image_path' => $firstCover, 'is_active' => true,
        'items' => [[
            'id' => $groups[0]->items[0]->getKey(), 'media_type' => 'image', 'media_path' => $keptImage, 'is_active' => true,
        ]],
    ]]);

    Storage::disk('public')->assertExists([$firstCover, $keptImage]);
    Storage::disk('public')->assertMissing([$deletedImage, $secondCover, $deletedVideo]);
});

test('story ids cannot be forged duplicated or moved between groups', function (): void {
    $coverOne = storyImage('ids-cover-one');
    $coverTwo = storyImage('ids-cover-two');
    $imageOne = storyImage('ids-image-one');
    $imageTwo = storyImage('ids-image-two');
    $this->homepageService->saveStories($this->homepageAdmin, [
        ['title' => 'Первый', 'cover_image_path' => $coverOne, 'is_active' => true, 'items' => [['media_type' => 'image', 'media_path' => $imageOne, 'is_active' => true]]],
        ['title' => 'Второй', 'cover_image_path' => $coverTwo, 'is_active' => true, 'items' => [['media_type' => 'image', 'media_path' => $imageTwo, 'is_active' => true]]],
    ]);
    $groups = HomepageStoryGroup::query()->ordered()->with('items')->get();
    $base = $groups->map(fn (HomepageStoryGroup $group): array => [
        'id' => $group->getKey(), 'title' => $group->title, 'cover_image_path' => $group->cover_image_path, 'is_active' => true,
        'items' => $group->items->map(fn (HomepageStoryItem $item): array => [
            'id' => $item->getKey(), 'media_type' => $item->media_type->value, 'media_path' => $item->media_path, 'is_active' => true,
        ])->all(),
    ])->all();
    $before = HomepageStoryGroup::query()->with('items')->get()->toArray();

    $cases = [];
    $cases['foreign story'] = tap($base, function (array &$rows) use ($groups): void {
        $rows[0]['items'][0]['id'] = $groups[1]->items[0]->getKey();
    });
    $cases['duplicate group'] = [$base[0], $base[0]];
    $cases['duplicate story'] = tap($base, function (array &$rows): void {
        $rows[0]['items'][] = $rows[0]['items'][0];
    });
    $cases['forged story'] = tap($base, function (array &$rows): void {
        $rows[0]['items'][0]['id'] = 999999;
    });

    foreach ($cases as $label => $payload) {
        expect(fn () => $this->homepageService->saveStories($this->homepageAdmin, $payload), $label)
            ->toThrow(ValidationException::class);
        expect(HomepageStoryGroup::query()->with('items')->get()->toArray(), $label)->toBe($before);
    }
});

test('server media validation rejects image video mismatches independently of the form', function (): void {
    foreach ([
        ['image', 'video'],
        ['video', 'image'],
    ] as $index => [$type, $actualType]) {
        $cover = storyImage("mime-cover-{$index}");
        $path = $actualType === 'video'
            ? storyVideo("mime-video-{$index}")
            : storyImage("mime-image-{$index}");

        expect(fn () => $this->homepageService->saveStories($this->homepageAdmin, [[
            'title' => 'MIME', 'cover_image_path' => $cover, 'is_active' => true,
            'items' => [['media_type' => $type, 'media_path' => $path, 'is_active' => true]],
        ]]))->toThrow(ValidationException::class);
    }
});

test('server media validation accepts managed jpg mp4 and webm files', function (): void {
    $cover = storyImage('accepted-cover');
    $image = storyImage('accepted-image');
    $mp4 = storyVideo('accepted-mp4');
    $webm = storyWebm('accepted-webm');

    $this->homepageService->saveStories($this->homepageAdmin, [[
        'title' => 'Форматы', 'cover_image_path' => $cover, 'is_active' => true,
        'items' => [
            ['media_type' => 'image', 'media_path' => $image, 'is_active' => true],
            ['media_type' => 'video', 'media_path' => $mp4, 'is_active' => true],
            ['media_type' => 'video', 'media_path' => $webm, 'is_active' => true],
        ],
    ]]);

    expect(HomepageStoryItem::query()->ordered()->pluck('media_path')->all())->toBe([$image, $mp4, $webm]);
});

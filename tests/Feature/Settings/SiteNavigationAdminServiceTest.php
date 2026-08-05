<?php

use App\Enums\NavigationLinkType;
use App\Enums\NavigationZone;
use App\Models\SiteNavigationItem;
use App\Models\User;
use App\Services\Settings\SiteNavigationAdminService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function navigationRouteData(string $code = 'about-link'): array
{
    return [
        'code' => $code,
        'zone' => NavigationZone::HeaderTop->value,
        'title' => 'О нас',
        'link_type' => NavigationLinkType::Route->value,
        'route_name' => 'about',
        'url' => null,
        'open_in_new_tab' => false,
        'is_active' => true,
        'position' => 10,
    ];
}

test('navigation service creates valid route and absolute url links', function (): void {
    $service = app(SiteNavigationAdminService::class);
    $admin = User::factory()->admin()->create();
    $route = $service->create($admin, navigationRouteData('  ABOUT_LINK  '));
    $url = $service->create($admin, [
        ...navigationRouteData('telegram-link'),
        'zone' => NavigationZone::FooterAbout->value,
        'title' => 'Telegram',
        'link_type' => NavigationLinkType::Url->value,
        'route_name' => null,
        'url' => 'https://t.me/magazporogi',
        'open_in_new_tab' => true,
    ]);

    expect($route->code)->toBe('about_link')
        ->and($route->zone)->toBe(NavigationZone::HeaderTop)
        ->and($route->link_type)->toBe(NavigationLinkType::Route)
        ->and($url->url)->toBe('https://t.me/magazporogi')
        ->and($url->open_in_new_tab)->toBeTrue();
});

test('navigation service rejects forged destinations without partial writes', function (array $changes): void {
    $service = app(SiteNavigationAdminService::class);
    $admin = User::factory()->admin()->create();
    $before = SiteNavigationItem::query()->count();

    expect(fn () => $service->create($admin, [...navigationRouteData('forged-link'), ...$changes]))
        ->toThrow(ValidationException::class);

    expect(SiteNavigationItem::query()->count())->toBe($before);
})->with([
    'unknown route' => [['route_name' => 'missing.route']],
    'route with url' => [['url' => 'https://example.com']],
    'url without url' => [['link_type' => 'url', 'route_name' => null, 'url' => null]],
    'url with route' => [['link_type' => 'url', 'route_name' => 'about', 'url' => 'https://example.com']],
    'javascript' => [['link_type' => 'url', 'route_name' => null, 'url' => 'javascript:alert(1)']],
    'data' => [['link_type' => 'url', 'route_name' => null, 'url' => 'data:text/plain,test']],
    'file' => [['link_type' => 'url', 'route_name' => null, 'url' => 'file:///tmp/test']],
    'protocol relative' => [['link_type' => 'url', 'route_name' => null, 'url' => '//example.com']],
    'invalid zone' => [['zone' => 'sidebar']],
    'invalid link type' => [['link_type' => 'html']],
    'negative position' => [['position' => -1]],
    'html title' => [['title' => '<b>О нас</b>']],
    'forged field' => [['css_class' => 'hidden']],
]);

test('navigation duplicate and stable codes are validation errors without http 500', function (): void {
    $service = app(SiteNavigationAdminService::class);
    $admin = User::factory()->admin()->create();
    $item = $service->create($admin, navigationRouteData('stable-code'));

    expect(fn () => $service->create($admin, navigationRouteData('stable-code')))
        ->toThrow(ValidationException::class, 'уже существует')
        ->and(fn () => $service->update($admin, $item, ['code' => 'changed-code']))
        ->toThrow(ValidationException::class, 'нельзя изменять');

    expect($item->refresh()->code)->toBe('stable-code')
        ->and(SiteNavigationItem::query()->count())->toBe(1);
});

test('navigation mutations and reorder are transactional and zone bounded', function (): void {
    $service = app(SiteNavigationAdminService::class);
    $admin = User::factory()->admin()->create();
    $first = $service->create($admin, [...navigationRouteData('first'), 'position' => 10]);
    $second = $service->create($admin, [...navigationRouteData('second'), 'position' => 20]);
    $footer = $service->create($admin, [
        ...navigationRouteData('footer'),
        'zone' => NavigationZone::FooterAbout->value,
    ]);

    $service->reorder($admin, NavigationZone::HeaderTop, [$second->getKey(), $first->getKey()]);

    expect($second->refresh()->position)->toBe(0)
        ->and($first->refresh()->position)->toBe(1);

    $positions = SiteNavigationItem::query()->pluck('position', 'id')->all();

    expect(fn () => $service->reorder($admin, NavigationZone::HeaderTop, [
        $first->getKey(),
        $footer->getKey(),
    ]))->toThrow(ValidationException::class, 'одной выбранной зоны');

    expect(SiteNavigationItem::query()->pluck('position', 'id')->all())->toBe($positions);

    $service->setActive($admin, $first, false);
    expect($first->refresh()->is_active)->toBeFalse();

    $service->update($admin, $first, ['title' => 'Первый обновлённый']);
    expect($first->refresh()->title)->toBe('Первый обновлённый');

    $service->delete($admin, $footer);
    expect(SiteNavigationItem::query()->whereKey($footer)->exists())->toBeFalse();
});

test('navigation role matrix makes manager view only and blocks direct service mutations', function (): void {
    $service = app(SiteNavigationAdminService::class);
    $admin = User::factory()->admin()->create();
    $item = $service->create($admin, navigationRouteData());
    $actors = [
        'super_admin' => User::factory()->superAdmin()->create(),
        'admin' => User::factory()->admin()->create(),
        'manager' => User::factory()->manager()->create(),
        'customer' => User::factory()->create(),
        'inactive' => User::factory()->admin()->inactive()->create(),
        'blocked' => User::factory()->admin()->blocked()->create(),
    ];

    foreach ($actors as $role => $actor) {
        $mayView = in_array($role, ['super_admin', 'admin', 'manager'], true);
        $mayManage = in_array($role, ['super_admin', 'admin'], true);

        expect($actor->can('view', $item), "{$role}:view")->toBe($mayView)
            ->and($actor->can('update', $item), "{$role}:update")->toBe($mayManage)
            ->and($actor->can('delete', $item), "{$role}:delete")->toBe($mayManage)
            ->and($actor->can('reorder', SiteNavigationItem::class), "{$role}:reorder")->toBe($mayManage)
            ->and($actor->can('forceDelete', $item), "{$role}:forceDelete")->toBeFalse()
            ->and($actor->can('replicate', $item), "{$role}:replicate")->toBeFalse();
    }

    $manager = $actors['manager'];

    expect(fn () => $service->create($manager, navigationRouteData('forbidden-create')))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $service->update($manager, $item, ['title' => 'Запрещено']))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $service->setActive($manager, $item, false))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $service->reorder($manager, NavigationZone::HeaderTop, [$item->getKey()]))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $service->delete($manager, $item))
        ->toThrow(AuthorizationException::class);

    expect($item->refresh()->title)->toBe('О нас')
        ->and($item->is_active)->toBeTrue();
});

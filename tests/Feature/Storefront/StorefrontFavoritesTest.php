<?php

namespace Tests\Feature\Storefront;

use App\Enums\DeliveryMethod;
use App\Enums\DeliveryPriceMode;
use App\Enums\PaymentMethod;
use App\Enums\ProductStatus;
use App\Events\OrderCreated;
use App\Models\Cart;
use App\Models\DeliveryMethodSetting;
use App\Models\FavoriteItem;
use App\Models\FavoriteList;
use App\Models\PaymentMethodSetting;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Services\CartManager;
use App\Services\CheckoutService;
use App\Services\FavoritesManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

final class StorefrontFavoritesTest extends TestCase
{
    use RefreshDatabase;

    public function test_safe_storefront_gets_without_favorites_cookie_do_not_create_a_list(): void
    {
        $product = $this->publicProduct(['title' => 'Read only favorite product']);

        $this->get(route('home'))->assertOk();
        $this->get(route('catalog.index'))->assertOk();
        $this->get(route('products.show', $product->slug))->assertOk();

        $this->assertDatabaseCount('favorite_lists', 0);
        $this->assertDatabaseCount('favorite_items', 0);
    }

    public function test_first_add_creates_an_independent_secure_cookie_list_and_item(): void
    {
        $product = $this->publicProduct();

        $response = $this->from(route('catalog.index'))->post(route('favorites.items.store'), [
            'product_id' => $product->getKey(),
        ]);

        $response->assertRedirect(route('catalog.index'))
            ->assertCookie(FavoritesManager::COOKIE_NAME);

        $list = FavoriteList::query()->firstOrFail();
        $cookie = collect($response->headers->getCookies())
            ->first(fn (Cookie $cookie): bool => $cookie->getName() === FavoritesManager::COOKIE_NAME);

        $this->assertDatabaseHas('favorite_items', [
            'favorite_list_id' => $list->getKey(),
            'product_id' => $product->getKey(),
        ]);
        $this->assertNotSame(CartManager::COOKIE_NAME, FavoritesManager::COOKIE_NAME);
        $this->assertTrue($cookie instanceof Cookie && $cookie->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $cookie?->getSameSite()));
        $this->assertTrue($list->expires_at->isAfter(now()->addDays(FavoritesManager::COOKIE_TTL_DAYS - 1)));
    }

    public function test_duplicate_add_is_idempotent_and_variants_remain_product_level(): void
    {
        $product = $this->publicProduct();
        ProductVariant::factory()->forProduct($product)->create();
        $list = FavoriteList::query()->create(['expires_at' => now()->addYear()]);

        $this->withCookie(FavoritesManager::COOKIE_NAME, $list->token)
            ->post(route('favorites.items.store'), ['product_id' => $product->getKey()])
            ->assertRedirect();
        $this->withCookie(FavoritesManager::COOKIE_NAME, $list->token)
            ->post(route('favorites.items.store'), ['product_id' => $product->getKey()])
            ->assertRedirect();

        $this->assertDatabaseCount('favorite_items', 1);
        $this->assertDatabaseHas('favorite_items', ['product_id' => $product->getKey()]);
    }

    public function test_two_products_report_count_two_and_other_token_cannot_see_them(): void
    {
        $first = $this->publicProduct(['title' => 'First favorite']);
        $second = $this->publicProduct(['title' => 'Second favorite']);
        $owner = FavoriteList::query()->create(['expires_at' => now()->addYear()]);
        $other = FavoriteList::query()->create(['expires_at' => now()->addYear()]);
        FavoriteItem::query()->create(['favorite_list_id' => $owner->getKey(), 'product_id' => $first->getKey()]);

        $this->withCookie(FavoritesManager::COOKIE_NAME, $owner->token)
            ->post(route('favorites.items.store'), ['product_id' => $second->getKey()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('is_favorite', true);

        $this->withCookie(FavoritesManager::COOKIE_NAME, $other->token)
            ->get(route('favorites.show'))
            ->assertOk()
            ->assertDontSee('First favorite')
            ->assertDontSee('Second favorite');
    }

    public function test_ajax_add_and_remove_follow_the_public_json_contract(): void
    {
        $product = $this->publicProduct();

        $add = $this->post(route('favorites.items.store'), ['product_id' => $product->getKey()], ['Accept' => 'application/json']);
        $add->assertOk()->assertJson([
            'message' => 'Товар добавлен в избранное.',
            'count' => 1,
            'product_id' => $product->getKey(),
            'is_favorite' => true,
        ]);
        $list = FavoriteList::query()->firstOrFail();

        $this->withCookie(FavoritesManager::COOKIE_NAME, $list->token)
            ->delete(route('favorites.items.destroy', $product->getKey()), [], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson([
                'message' => 'Товар удалён из избранного.',
                'count' => 0,
                'product_id' => $product->getKey(),
                'is_favorite' => false,
            ]);
        $this->assertDatabaseCount('favorite_items', 0);
    }

    public function test_removing_an_absent_item_is_safe_and_does_not_create_a_list(): void
    {
        $this->deleteJson(route('favorites.items.destroy', 999999))
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('is_favorite', false);

        $this->assertDatabaseCount('favorite_lists', 0);
    }

    public function test_unavailable_or_missing_product_cannot_be_added(): void
    {
        $archived = $this->publicProduct(['status' => ProductStatus::Archived]);
        $inactiveCategory = ProductCategory::factory()->create();
        $inactiveCategoryProduct = Product::factory()->forCategory($inactiveCategory)->withDefaultVariant()->create();
        $inactiveCategory->update(['is_active' => false]);

        $this->postJson(route('favorites.items.store'), ['product_id' => $archived->getKey()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_id');
        $this->postJson(route('favorites.items.store'), ['product_id' => $inactiveCategoryProduct->getKey()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_id');
        $this->postJson(route('favorites.items.store'), ['product_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_id');

        $this->assertDatabaseCount('favorite_lists', 0);
        $this->assertDatabaseCount('favorite_items', 0);
    }

    public function test_favorites_page_hides_products_that_became_archived_or_soft_deleted(): void
    {
        $visible = $this->publicProduct(['title' => 'Visible favorite']);
        $archived = $this->publicProduct(['title' => 'Archived favorite']);
        $deleted = $this->publicProduct(['title' => 'Deleted favorite']);
        $list = $this->favoriteListWith([$visible, $archived, $deleted]);
        $archived->update(['status' => ProductStatus::Archived]);
        $deleted->delete();

        $this->withCookie(FavoritesManager::COOKIE_NAME, $list->token)
            ->get(route('favorites.show'))
            ->assertOk()
            ->assertSee('Visible favorite')
            ->assertDontSee('Archived favorite')
            ->assertDontSee('Deleted favorite')
            ->assertSee('aria-label="Избранное, товаров: 1"', false);
    }

    public function test_hard_deleted_product_cascades_its_favorite_item(): void
    {
        $product = $this->publicProduct();
        $this->favoriteListWith([$product]);

        $product->forceDeleteQuietly();

        $this->assertDatabaseCount('favorite_items', 0);
    }

    public function test_empty_and_populated_favorites_pages_use_standard_product_cards(): void
    {
        $this->get(route('favorites.show'))
            ->assertOk()
            ->assertSee('В избранном пока ничего нет')
            ->assertSee('Перейти в каталог');

        $product = $this->publicProduct(['title' => 'Favorite standard card']);
        $list = $this->favoriteListWith([$product]);

        $this->withCookie(FavoritesManager::COOKIE_NAME, $list->token)
            ->get(route('favorites.show'))
            ->assertOk()
            ->assertSee('Favorite standard card')
            ->assertSee('product-card', false)
            ->assertSee('favorite-toggle--active', false)
            ->assertSee('aria-pressed="true"', false);
    }

    public function test_header_product_card_and_product_page_render_server_favorite_state(): void
    {
        $product = $this->publicProduct(['title' => 'Favorite rendered state']);
        $list = $this->favoriteListWith([$product]);

        $catalog = $this->withCookie(FavoritesManager::COOKIE_NAME, $list->token)
            ->get(route('catalog.index', ['q' => 'Favorite rendered state']));
        $catalog->assertOk()
            ->assertSee('aria-label="Избранное, товаров: 1"', false)
            ->assertSee('data-favorites-count', false)
            ->assertSee('data-favorite-product-id="'.$product->getKey().'"', false)
            ->assertSee('aria-pressed="true"', false)
            ->assertSee('favorite-toggle--active', false);

        $this->withCookie(FavoritesManager::COOKIE_NAME, $list->token)
            ->get(route('products.show', $product->slug))
            ->assertOk()
            ->assertSee('part-gallery__fav', false)
            ->assertSee('data-favorite-product-id="'.$product->getKey().'"', false)
            ->assertSee('aria-pressed="true"', false);
    }

    public function test_inactive_card_heart_is_server_rendered_without_nested_interactive_content(): void
    {
        $product = $this->publicProduct(['title' => 'Inactive favorite heart']);

        $response = $this->get(route('catalog.index', ['q' => 'Inactive favorite heart']))->assertOk();
        $response->assertSee('data-favorite-product-id="'.$product->getKey().'"', false)
            ->assertSee('aria-pressed="false"', false)
            ->assertSee('aria-label="Добавить в избранное"', false);

        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);

        $this->assertSame(0, $xpath->query('//article[contains(concat(" ", normalize-space(@class), " "), " product-card ")]//a//button[@data-favorite-toggle]')->count());
    }

    public function test_favorites_javascript_contract_is_progressive_accessible_and_synchronizes_all_controls(): void
    {
        $javascript = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("document.querySelectorAll('[data-favorite-form]')", $javascript);
        $this->assertStringContainsString('event.preventDefault()', $javascript);
        $this->assertStringContainsString("credentials: 'same-origin'", $javascript);
        $this->assertStringContainsString("Accept: 'application/json'", $javascript);
        $this->assertStringContainsString('setFavoritePending(productId, true)', $javascript);
        $this->assertStringContainsString('favoriteForms(productId).forEach', $javascript);
        $this->assertStringContainsString("button.setAttribute('aria-pressed', String(isFavorite))", $javascript);
        $this->assertStringContainsString('updateFavoritesBadge(payload.count, true)', $javascript);
        $this->assertStringContainsString('showStorefrontToast(', $javascript);
        $this->assertStringNotContainsString('alert(', $javascript);
    }

    public function test_ordinary_post_fallback_adds_and_removes_with_redirects(): void
    {
        $product = $this->publicProduct();

        $this->from(route('products.show', $product->slug))
            ->post(route('favorites.items.store'), ['product_id' => $product->getKey()])
            ->assertRedirect(route('products.show', $product->slug));
        $list = FavoriteList::query()->firstOrFail();

        $this->withCookie(FavoritesManager::COOKIE_NAME, $list->token)
            ->from(route('favorites.show'))
            ->delete(route('favorites.items.destroy', $product->getKey()))
            ->assertRedirect(route('favorites.show'));
        $this->assertDatabaseCount('favorite_items', 0);
    }

    public function test_favorites_write_routes_remain_in_the_csrf_protected_web_group(): void
    {
        $store = Route::getRoutes()->getByName('favorites.items.store');
        $destroy = Route::getRoutes()->getByName('favorites.items.destroy');

        $this->assertContains('web', $store->gatherMiddleware());
        $this->assertContains('web', $destroy->gatherMiddleware());
    }

    public function test_database_unique_constraint_rejects_duplicate_list_product_pair(): void
    {
        $product = $this->publicProduct();
        $list = $this->favoriteListWith([$product]);

        $this->expectException(QueryException::class);
        DB::table('favorite_items')->insert([
            'favorite_list_id' => $list->getKey(),
            'product_id' => $product->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_expired_list_is_not_visible_and_first_new_add_uses_a_new_token(): void
    {
        $first = $this->publicProduct(['title' => 'Expired favorite']);
        $second = $this->publicProduct(['title' => 'Fresh favorite']);
        $expired = FavoriteList::query()->create(['expires_at' => now()->subDay()]);
        FavoriteItem::query()->create(['favorite_list_id' => $expired->getKey(), 'product_id' => $first->getKey()]);

        $this->withCookie(FavoritesManager::COOKIE_NAME, $expired->token)
            ->get(route('favorites.show'))
            ->assertOk()
            ->assertDontSee('Expired favorite');
        $this->withCookie(FavoritesManager::COOKIE_NAME, $expired->token)
            ->post(route('favorites.items.store'), ['product_id' => $second->getKey()])
            ->assertRedirect();

        $this->assertDatabaseCount('favorite_lists', 2);
        $this->assertNotSame($expired->token, FavoriteList::query()->latest('id')->firstOrFail()->token);
    }

    public function test_cart_clear_does_not_change_favorites_token_or_items(): void
    {
        $product = $this->publicProduct();
        $variant = $product->variants()->firstOrFail();
        $list = $this->favoriteListWith([$product]);
        $cart = Cart::factory()->create();
        $request = $this->requestWithTokens('/cart', 'DELETE', $cart, $list);
        app(CartManager::class)->addItem($request, $variant->getKey());

        app(CartManager::class)->clear($request);

        $this->assertNotSame($cart->token, $list->token);
        $this->assertSame($list->token, $request->cookie(FavoritesManager::COOKIE_NAME));
        $this->assertDatabaseHas('favorite_items', [
            'favorite_list_id' => $list->getKey(),
            'product_id' => $product->getKey(),
        ]);
        $this->assertSame(1, app(FavoritesManager::class)->count($request));
    }

    public function test_successful_checkout_and_new_cart_do_not_change_favorites(): void
    {
        Event::fake([OrderCreated::class]);
        DeliveryMethodSetting::factory()->create([
            'code' => DeliveryMethod::TransportCompany,
            'title' => 'Транспортная компания',
            'base_price' => 700,
            'price_mode' => DeliveryPriceMode::Fixed,
            'is_active' => true,
        ]);
        PaymentMethodSetting::factory()->create([
            'code' => PaymentMethod::Sbp,
            'title' => 'СБП',
            'is_active' => true,
        ]);
        $product = $this->publicProduct();
        $variant = $product->variants()->firstOrFail();
        $variant->update(['stock_quantity' => null]);
        $list = $this->favoriteListWith([$product]);
        $cart = Cart::factory()->create();
        $request = $this->requestWithTokens('/checkout', 'POST', $cart, $list);
        app(CartManager::class)->addItem($request, $variant->getKey());

        app(CheckoutService::class)->createOrderFromCart($request, [
            'customer_name' => 'Иван Петров',
            'customer_phone' => '+7 999 123-45-67',
            'customer_email' => null,
            'customer_city' => 'Москва',
            'customer_address' => 'Ленинградское шоссе, 1',
            'customer_comment' => null,
            'delivery_method' => DeliveryMethod::TransportCompany->value,
            'payment_method' => PaymentMethod::Sbp->value,
            'agree_terms' => true,
        ]);

        $this->assertSame($list->token, $request->cookie(FavoritesManager::COOKIE_NAME));
        $this->assertDatabaseHas('favorite_items', [
            'favorite_list_id' => $list->getKey(),
            'product_id' => $product->getKey(),
        ]);
        $this->assertSame(1, app(FavoritesManager::class)->count($request));
    }

    public function test_catalog_favorite_state_uses_one_bounded_query_for_many_cards(): void
    {
        $first = $this->publicProduct(['title' => 'Favorite query card 01', 'position' => 1]);
        $list = $this->favoriteListWith([$first]);
        $url = route('catalog.index', ['q' => 'Favorite query card']);
        $one = $this->queriesFor($url, $list->token);

        foreach (range(2, 20) as $index) {
            $product = $this->publicProduct([
                'title' => sprintf('Favorite query card %02d', $index),
                'position' => $index,
            ]);
            FavoriteItem::query()->create(['favorite_list_id' => $list->getKey(), 'product_id' => $product->getKey()]);
        }

        $many = $this->queriesFor($url, $list->token);
        $favoriteQueries = collect($many)->filter(fn (array $query): bool => str_contains($query['query'], 'favorite_items'));

        $this->assertLessThanOrEqual(count($one) + 2, count($many));
        $this->assertCount(1, $favoriteQueries);
    }

    public function test_related_product_favorite_state_does_not_query_per_card(): void
    {
        $main = $this->publicProduct(['title' => 'Favorite related main']);
        $related = collect(range(1, 4))->map(fn (int $index): Product => $this->publicProduct([
            'product_category_id' => $main->product_category_id,
            'title' => sprintf('Favorite related %02d', $index),
            'position' => $index,
        ]));
        $list = $this->favoriteListWith($related->all());

        $queries = $this->queriesFor(route('products.show', $main->slug), $list->token);
        $favoriteQueries = collect($queries)->filter(fn (array $query): bool => str_contains($query['query'], 'favorite_items'));

        $this->assertCount(1, $favoriteQueries);
    }

    public function test_favorites_page_query_count_stays_bounded_as_items_grow(): void
    {
        $first = $this->publicProduct(['title' => 'Favorite page query 01', 'position' => 1]);
        $list = $this->favoriteListWith([$first]);
        $one = $this->queriesFor(route('favorites.show'), $list->token);

        foreach (range(2, 20) as $index) {
            $product = $this->publicProduct([
                'title' => sprintf('Favorite page query %02d', $index),
                'position' => $index,
            ]);
            FavoriteItem::query()->create(['favorite_list_id' => $list->getKey(), 'product_id' => $product->getKey()]);
        }

        $many = $this->queriesFor(route('favorites.show'), $list->token);
        $favoriteQueries = collect($many)->filter(fn (array $query): bool => str_contains($query['query'], 'favorite_items'));

        $this->assertLessThanOrEqual(count($one) + 2, count($many));
        $this->assertCount(1, $favoriteQueries);
    }

    public function test_migration_supports_isolated_down_and_up(): void
    {
        $migration = require database_path('migrations/2026_08_13_000100_create_favorite_lists_and_items.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('favorite_items'));
        $this->assertFalse(Schema::hasTable('favorite_lists'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('favorite_lists'));
        $this->assertTrue(Schema::hasTable('favorite_items'));
    }

    private function publicProduct(array $attributes = []): Product
    {
        return Product::factory()->withDefaultVariant()->create($attributes);
    }

    /** @param list<Product> $products */
    private function favoriteListWith(array $products): FavoriteList
    {
        $list = FavoriteList::query()->create(['expires_at' => now()->addYear()]);

        foreach ($products as $product) {
            FavoriteItem::query()->create([
                'favorite_list_id' => $list->getKey(),
                'product_id' => $product->getKey(),
            ]);
        }

        return $list;
    }

    private function requestWithTokens(string $uri, string $method, Cart $cart, FavoriteList $list): Request
    {
        return Request::create($uri, $method, [], [
            CartManager::COOKIE_NAME => $cart->token,
            FavoritesManager::COOKIE_NAME => $list->token,
        ]);
    }

    /** @return array<int, array{query:string,bindings:array,time:float}> */
    private function queriesFor(string $url, string $token): array
    {
        DB::disableQueryLog();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->withCookie(FavoritesManager::COOKIE_NAME, $token)->get($url)->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return $queries;
    }
}

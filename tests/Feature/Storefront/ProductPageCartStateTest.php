<?php

use App\Enums\StockStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Services\CartManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('product page starts with add only and never creates or refreshes a cart', function (string $token): void {
    $variant = ProductVariant::factory()->default()->create(['stock_quantity' => null]);
    $response = $this->withCookie(CartManager::COOKIE_NAME, $token)->get(route('products.show', $variant->product->slug))
        ->assertOk()->assertCookieMissing(CartManager::COOKIE_NAME)
        ->assertSee('Добавить в корзину')->assertDontSee('Количество:')
        ->assertDontSee('Получить консультацию')->assertDontSee('data-inquiry-modal', false)
        ->assertSee('name="quantity" value="1"', false);
    expect($response->viewData('variantCartStates'))->toBe([])
        ->and(Cart::count())->toBe(0);
    expect($response->getContent())->toMatch('/data-product-cart-counter\s+hidden/')
        ->and($response->getContent())->not->toMatch('/data-add-to-cart\s+hidden/');
})->with(['no token' => '', 'unknown token' => 'unknown']);

test('product page initial cart state is per variant and scoped to the current active cart', function (): void {
    $a = ProductVariant::factory()->default()->create(['stock_quantity' => null]);
    $b = ProductVariant::factory()->forProduct($a->product)->create(['stock_quantity' => null]);
    $foreign = ProductVariant::factory()->create();
    $cart = Cart::factory()->create();
    $item = CartItem::factory()->for($cart)->create(['product_id' => $a->product_id, 'product_variant_id' => $a->id, 'quantity' => 3]);
    CartItem::factory()->for($cart)->create(['product_variant_id' => $foreign->id]);
    CartItem::factory()->create(['product_variant_id' => $b->id, 'quantity' => 7]);

    $this->withCookie(CartManager::COOKIE_NAME, $cart->token);
    $response = $this->get(route('products.show', $a->product->slug))->assertOk()->assertCookieMissing(CartManager::COOKIE_NAME);
    expect(array_keys($response->viewData('variantCartStates')))->toBe([$a->id])
        ->and($response->viewData('variantCartStates')[$a->id])->toMatchArray(['cart_item_id' => $item->id, 'quantity' => 3]);
    expect($response->getContent())->toContain('href="'.route('cart.show').'" class="btn part-buy__cart" data-product-cart-link')
        ->toMatch('/data-add-to-cart\s+hidden/')
        ->toMatch('/data-product-cart-quantity[^>]*>3<\//')
        ->not->toMatch('/data-product-cart-counter\s+hidden/');
    $other = $this->get(route('products.show', ['productSlug' => $a->product->slug, 'variant' => $b->id]))->assertOk();
    expect($other->getContent())->toMatch('/data-product-cart-counter\s+hidden/')
        ->not->toMatch('/data-add-to-cart\s+hidden/');
    $cart->update(['expires_at' => now()->subDay()]);
    expect($this->get(route('products.show', $a->product->slug))->assertOk()->viewData('variantCartStates'))->toBe([]);
});

test('variant cart lookup is bounded and read only for a large variant set', function (): void {
    $variants = ProductVariant::factory()->count(30)->create();
    $cart = Cart::factory()->create();
    CartItem::factory()->for($cart)->create(['product_variant_id' => $variants->first()->id, 'quantity' => 2]);
    $request = Request::create('/', 'GET', [], [CartManager::COOKIE_NAME => $cart->token]);
    foreach ([[$variants->first()->id], $variants->modelKeys()] as $ids) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $states = app(CartManager::class)->variantItemStatesForRequest($request, $ids);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        expect($states[$variants->first()->id]['quantity'])->toBe(2)
            ->and(count($queries))->toBe(2)
            ->and(Cookie::queued(CartManager::COOKIE_NAME))->toBeNull();
    }
});

test('product cart add increment decrement delete use authoritative server totals and URLs', function (): void {
    $variant = ProductVariant::factory()->default()->create(['price' => 1200, 'stock_quantity' => 2, 'stock_status' => StockStatus::InStock]);
    $cart = Cart::factory()->create();
    $this->withCredentials()->withCookie(CartManager::COOKIE_NAME, $cart->token);
    $added = $this->postJson(route('cart.items.store'), ['product_variant_id' => $variant->id, 'quantity' => 1])
        ->assertCreated()->assertJsonPath('item.quantity', 1)->assertJsonPath('cart.items_count', 1);
    $id = $added->json('item.id');
    $added->assertJsonPath('item.product_variant_id', $variant->id)
        ->assertJsonPath('item.update_url', route('cart.items.update', $id))
        ->assertJsonPath('item.remove_url', route('cart.items.destroy', $id));
    $this->patchJson(route('cart.items.update', $id), ['quantity' => 2])->assertOk()
        ->assertJsonPath('item.quantity', 2)->assertJsonPath('cart.total', 2400);
    $this->patchJson(route('cart.items.update', $id), ['quantity' => 3])->assertUnprocessable();
    $this->patchJson(route('cart.items.update', $id), ['quantity' => 1])->assertOk()
        ->assertJsonPath('item.quantity', 1)->assertJsonPath('cart.total', 1200);
    $this->deleteJson(route('cart.items.destroy', $id))->assertOk()
        ->assertJsonPath('cart.items_count', 0)->assertJsonPath('cart.total', 0);
    expect(CartItem::find($id))->toBeNull();
});

test('unavailable cart variants can be reduced or removed but never increased', function (string $reason): void {
    $variant = ProductVariant::factory()->default()->create(['price' => 100, 'stock_quantity' => 5]);
    $cart = Cart::factory()->create();
    $item = app(CartManager::class)->addItem(Request::create('/', 'GET', [], [CartManager::COOKIE_NAME => $cart->token]), $variant->id, 3);
    match ($reason) {
        'inactive' => $variant->update(['is_active' => false]),
        'price' => $variant->update(['price' => 0]),
        'stock' => $variant->update(['stock_status' => StockStatus::OutOfStock, 'stock_quantity' => 0]),
    };
    $this->withCredentials()->withCookie(CartManager::COOKIE_NAME, $cart->token);
    $this->patchJson(route('cart.items.update', $item), ['quantity' => 4])->assertUnprocessable();
    $this->patchJson(route('cart.items.update', $item), ['quantity' => 2])->assertOk()->assertJsonPath('item.quantity', 2);
    $this->deleteJson(route('cart.items.destroy', $item))->assertOk()->assertJsonPath('cart.items_count', 0);
})->with(['inactive', 'price', 'stock']);

test('existing unsellable variant renders removable quantity with disabled plus', function (): void {
    $variant = ProductVariant::factory()->default()->create(['stock_status' => StockStatus::OutOfStock, 'stock_quantity' => 0]);
    $cart = Cart::factory()->create();
    CartItem::factory()->for($cart)->create(['product_variant_id' => $variant->id, 'product_id' => $variant->product_id, 'quantity' => 2]);
    $html = $this->withCookie(CartManager::COOKIE_NAME, $cart->token)
        ->get(route('products.show', $variant->product->slug))->assertOk()->getContent();
    expect($html)->not->toMatch('/data-product-cart-counter\s+hidden/')
        ->toMatch('/data-product-cart-step="1"[^>]*disabled/')
        ->not->toMatch('/data-product-cart-step="-1"[^>]*disabled/')
        ->toMatch('/data-product-cart-quantity[^>]*>2<\//');
});

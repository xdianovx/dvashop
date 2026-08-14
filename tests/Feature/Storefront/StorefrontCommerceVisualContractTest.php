<?php

use App\Enums\DeliveryMethod;
use App\Enums\DeliveryPriceMode;
use App\Enums\PaymentMethod;
use App\Enums\StockStatus;
use App\Models\Cart;
use App\Models\DeliveryMethodSetting;
use App\Models\Order;
use App\Models\PaymentMethodSetting;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFitment;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\CartManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

function commerceVisualCartRequest(Cart $cart): Request
{
    return Request::create('/cart', 'GET', [], [CartManager::COOKIE_NAME => $cart->token]);
}

test('commerce pages preserve approved classes assets and real form actions', function (): void {
    $make = VehicleMake::factory()->create(['title' => 'Visual Make', 'slug' => 'visual-make']);
    $model = VehicleModel::factory()->forMake($make)->create(['title' => 'Visual Model', 'slug' => 'visual-model']);
    $otherModel = VehicleModel::factory()->forMake($make)->create(['title' => 'Other Visual Model', 'slug' => 'other-visual-model']);
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create([
        'title' => 'Generation One',
        'slug' => 'generation-one-sedan',
        'years_label' => '2018–2022',
        'body' => 'Седан',
    ]);
    $wagonGeneration = VehicleGeneration::factory()->forVehicleModel($model)->create([
        'title' => 'Generation One',
        'slug' => 'generation-one-wagon',
        'years_label' => '2018–2022',
        'body' => 'Универсал',
    ]);
    $otherGeneration = VehicleGeneration::factory()->forVehicleModel($otherModel)->create([
        'title' => 'Other Generation',
        'slug' => 'other-generation',
    ]);
    foreach ([$generation, $wagonGeneration, $otherGeneration] as $publicGeneration) {
        $publicProduct = Product::factory()->withDefaultVariant()->create();
        ProductFitment::factory()->forProduct($publicProduct)->forVehicleGeneration($publicGeneration)->create();
    }

    $catalog = $this->get(route('catalog.index'))->assertOk();
    foreach (['catalog-search__form', 'catalog-search__submit-text', 'catalog-search__submit-icon'] as $class) {
        $catalog->assertSee($class, false);
    }
    $catalog->assertSee('action="'.route('catalog.index').'"', false)
        ->assertSee('name="q"', false);

    $brand = $this->get(route('catalog.make', $make->slug))->assertOk();
    foreach (['brand-page', 'brand-page__search-submit', 'brand-page__search-icon'] as $class) {
        $brand->assertSee($class, false);
    }
    $brand->assertSee('/img/brand-page/search.svg', false)
        ->assertSee('action="'.route('catalog.index').'"', false)
        ->assertDontSee('brand-page__filters', false);

    $modelPage = $this->get(route('catalog.model', [$make->slug, $model->slug]))->assertOk();
    foreach (['model-page__gen', 'model-page__gen-body', 'model-page__title--other', 'model-page__grid--other'] as $class) {
        $modelPage->assertSee($class, false);
    }
    $modelPage->assertSee('Седан')->assertSee('Универсал')->assertSee($otherModel->title);

    $this->get(route('catalog.generation', [$make->slug, $model->slug, $generation->slug]))
        ->assertOk()
        ->assertSee('product-head', false)
        ->assertSee('product-layout', false)
        ->assertSee('catalog-nav', false);

    $variant = ProductVariant::factory()->default()->create(['stock_quantity' => 10]);
    $this->get(route('products.show', $variant->product->slug))
        ->assertOk()
        ->assertSee('part-top', false)
        ->assertSee('part-gallery', false)
        ->assertSee('part-buy', false)
        ->assertSee('part-buy__cart', false);

    $cart = Cart::factory()->create();
    app(CartManager::class)->addItem(commerceVisualCartRequest($cart), $variant->getKey(), 1);
    $cartResponse = $this->withCookie(CartManager::COOKIE_NAME, $cart->token)->get(route('cart.show'))->assertOk();
    foreach (['cart-layout', 'cart-item__qty', 'cart-item__qty-btn', 'cart-item__qty-value'] as $class) {
        $cartResponse->assertSee($class, false);
    }

    foreach (DeliveryMethod::cases() as $index => $method) {
        DeliveryMethodSetting::factory()->create([
            'code' => $method,
            'position' => $index,
            'price_mode' => $method === DeliveryMethod::TransportCompany
                ? DeliveryPriceMode::OnRequest
                : DeliveryPriceMode::Free,
            'is_active' => true,
        ]);
    }
    foreach (PaymentMethod::cases() as $index => $method) {
        PaymentMethodSetting::factory()->create(['code' => $method, 'position' => $index, 'is_active' => true]);
    }

    $checkout = $this->withCookie(CartManager::COOKIE_NAME, $cart->token)->get(route('checkout.show'))->assertOk();
    foreach (['checkout-layout', 'checkout-card', 'checkout-shipping', 'checkout-payments', 'checkout-order', 'checkout-order__total', 'checkout-order__total-value'] as $class) {
        $checkout->assertSee($class, false);
    }
    $checkout->assertSee('/img/checkout/cdek.svg', false)
        ->assertSee('/img/checkout/pickup.svg', false)
        ->assertSee('💳')
        ->assertSee('⚡')
        ->assertSee('📄')
        ->assertSee('🤝')
        ->assertSee('Выбор способа получения')
        ->assertSee('placeholder="Иванов Иван Иванович"', false)
        ->assertSee('placeholder="+7 (___) ___-__-__"', false)
        ->assertSee('placeholder="mail@yandex.ru"', false)
        ->assertSee('placeholder="Москва"', false)
        ->assertSee('placeholder="Текст...."', false)
        ->assertSee('data-delivery-price=', false)
        ->assertSee('data-delivery-price-mode="on_request"', false)
        ->assertSee('Стоимость уточнит менеджер')
        ->assertSee('data-checkout-subtotal=', false)
        ->assertDontSee('name="delivery_price"', false)
        ->assertDontSee('name="total"', false);

    $order = Order::factory()->create([
        'delivery_price_mode_snapshot' => DeliveryPriceMode::OnRequest,
        'total_is_final' => false,
    ]);
    $token = 'visual-success-token';
    $thanks = $this->withSession(['checkout_success.'.$order->getKey() => $token])
        ->get(route('checkout.success', ['order' => $order->number, 'token' => $token]))
        ->assertOk();
    foreach (['thanks', 'thanks-details', 'thanks-steps', 'thanks-steps__item', 'thanks-steps__num', 'thanks-steps__title', 'thanks-steps__text'] as $class) {
        $thanks->assertSee($class, false);
    }
    $thanks->assertSee('Подтверждение')
        ->assertSee('Комплектация')
        ->assertSee('Доставка')
        ->assertSee('Менеджер перезвонит и уточнит детали заказа и сроки отгрузки.')
        ->assertSee('Детали проверяются по геометрии и упаковываются для отправки.')
        ->assertSee('После отправки сообщим трек-номер по телефону или email.')
        ->assertSee('Доставка рассчитывается отдельно')
        ->assertSee('Сумма товаров (без доставки)');
});

test('product and checkout scripts keep server matrix and totals as presentation only', function (): void {
    $script = file_get_contents(resource_path('js/app.js'));

    expect($script)
        ->toContain('[data-product-variant-fallback]')
        ->toContain('[data-selected-sku]')
        ->toContain('[data-selected-stock-label]')
        ->toContain('stockLabel.textContent =')
        ->toContain('renderSku(selectedVariant.sku)')
        ->toContain('part-buy__stock--in-stock')
        ->toContain('part-buy__stock--out-of-stock')
        ->toContain('part-buy__stock--pre-order')
        ->toContain('part-buy__stock--unavailable')
        ->toContain('candidate.variant_id === Number(fallbackSelect.value)')
        ->toContain('price.textContent = selectedVariant.price_label')
        ->toContain('quantity.max = isInStock')
        ->toContain('submit.disabled = !selectedVariant.purchasable')
        ->not->toContain('selectedVariant.price > 0')
        ->toContain('[data-delivery-price]')
        ->toContain("priceMode === 'on_request'")
        ->toContain('Стоимость уточнит менеджер')
        ->toContain('Сумма товаров (без доставки)')
        ->toContain('subtotal + deliveryPrice')
        ->not->toContain('name="delivery_price"')
        ->not->toContain('name="total"');
});

test('storefront JavaScript source contract isolates product runtime features and traps inquiry focus', function (): void {
    $script = file_get_contents(resource_path('js/app.js'));
    $product = file_get_contents(resource_path('views/part.blade.php'));
    $inquiry = file_get_contents(resource_path('views/components/storefront-inquiry-modal.blade.php'));

    expect($script)
        ->toContain('const initStorefrontFeature = (name, initializer) =>')
        ->toContain('function initProductGallery()')
        ->toContain("const mainGallery = document.querySelector('[data-gallery-main]')")
        ->toContain("const thumbsGallery = document.querySelector('[data-gallery-thumbs]')")
        ->toContain('if (!mainGallery || !thumbsGallery) return;')
        ->toContain('new Swiper(thumbsGallery')
        ->toContain('new Swiper(mainGallery')
        ->toContain("initStorefrontFeature('product-gallery', initProductGallery)")
        ->toContain('function initProductOptions()')
        ->toContain("initStorefrontFeature('product-options', initProductOptions)")
        ->toContain("console.error('[storefront:product-options] Unable to parse variant matrix.'")
        ->toContain("quantity.max = '1'")
        ->toContain('quantity.disabled = true')
        ->toContain('submit.disabled = true')
        ->toContain('function initCartAjax()')
        ->toContain("initStorefrontFeature('cart-ajax', initCartAjax)")
        ->toContain('function initInquiryForms()')
        ->toContain("initStorefrontFeature('inquiry', initInquiryForms)")
        ->toContain('const getFocusableElements = () =>')
        ->toContain("event.key !== 'Tab'")
        ->toContain('event.shiftKey ? lastFocusable : firstFocusable')
        ->toContain('returnFocus.focus()')
        ->and($product)
        ->toContain('data-selected-sku-row')
        ->toContain('@if (blank($displaySku)) hidden @endif')
        ->and($inquiry)
        ->toContain('type="tel"')
        ->toContain('inputmode="tel"')
        ->toContain('placeholder="+7 (___) ___-__-__"')
        ->not->toContain('pattern=');
});

test('homepage search select and product stock icon preserve approved visual contracts', function (): void {
    $searchStyles = file_get_contents(resource_path('scss/_search.scss'));
    $partStyles = file_get_contents(resource_path('scss/_part.scss'));

    expect($searchStyles)
        ->toContain('select.search__field-value')
        ->toContain('width: 100%')
        ->toContain('min-width: 0')
        ->toContain('border: 0')
        ->toContain('background: transparent')
        ->not->toContain('outline: none');
    expect($partStyles)
        ->toContain('.part-buy__stock-icon')
        ->toContain('.part-buy__stock--in-stock .part-buy__stock-icon')
        ->toContain('display: none')
        ->toContain('display: block');

    $inStock = ProductVariant::factory()->default()->create([
        'stock_status' => StockStatus::InStock,
        'stock_quantity' => 5,
    ]);
    $this->get(route('products.show', $inStock->product->slug))
        ->assertOk()
        ->assertSee('part-buy__stock--in-stock', false)
        ->assertSee('part-buy__stock-icon', false)
        ->assertSee('<circle cx="10" cy="10" r="8.5"', false)
        ->assertSee('<path d="m6.5 10 2.5 2.5 4.5-5"', false);

    $outOfStock = ProductVariant::factory()->default()->create([
        'stock_status' => StockStatus::OutOfStock,
        'stock_quantity' => 0,
    ]);
    $this->get(route('products.show', $outOfStock->product->slug))
        ->assertOk()
        ->assertSee('part-buy__stock--out-of-stock', false)
        ->assertDontSee('part-buy__stock--in-stock', false);
});

test('product page preserves approved delivery and related product visual contracts with real data', function (): void {
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->forCategory($category)->withDefaultVariant()->create();
    Product::factory()->forCategory($category)->withDefaultVariant()->create(['title' => 'Related visual product']);
    DeliveryMethodSetting::factory()->create([
        'code' => DeliveryMethod::Courier,
        'title' => 'Курьер до двери',
        'base_price' => 490,
        'price_mode' => DeliveryPriceMode::Fixed,
        'is_active' => true,
    ]);
    DeliveryMethodSetting::factory()->create([
        'code' => DeliveryMethod::Pickup,
        'title' => 'Скрытый самовывоз',
        'is_active' => false,
    ]);

    $response = $this->get(route('products.show', $product->slug))->assertOk();
    foreach (['part-delivery', 'part-delivery__row', 'part-delivery__info', 'part-delivery__icon', 'part-delivery__more', 'part-related', 'part-related__title'] as $class) {
        $response->assertSee($class, false);
    }
    $response->assertSee('Курьер до двери')
        ->assertSee('490 ₽')
        ->assertDontSee('Скрытый самовывоз')
        ->assertSee('href="'.route('payment').'"', false)
        ->assertSee('С этим товаром покупают')
        ->assertSee('Related visual product');
});

test('commerce storefront blade templates contain no raw database output', function (): void {
    $templates = [
        'catalog.blade.php',
        'brand.blade.php',
        'model.blade.php',
        'car.blade.php',
        'part.blade.php',
        'cart.blade.php',
        'checkout.blade.php',
        'thanks.blade.php',
        'components/product-card.blade.php',
        'components/cart-item.blade.php',
        'components/cart-summary.blade.php',
        'components/delivery-method.blade.php',
        'components/payment-method.blade.php',
    ];

    foreach ($templates as $template) {
        expect(file_get_contents(resource_path('views/'.$template)), $template)->not->toContain('{!!');
    }
});

test('checkout visual mappings keep inactive methods hidden and do not invent courier or post assets', function (): void {
    $variant = ProductVariant::factory()->default()->create(['stock_quantity' => 10]);
    $cart = Cart::factory()->create();
    app(CartManager::class)->addItem(commerceVisualCartRequest($cart), $variant->getKey(), 1);

    DeliveryMethodSetting::factory()->create(['code' => DeliveryMethod::TransportCompany, 'title' => 'Active transport', 'is_active' => true]);
    DeliveryMethodSetting::factory()->create(['code' => DeliveryMethod::Pickup, 'title' => 'Inactive pickup', 'is_active' => false]);
    DeliveryMethodSetting::factory()->create(['code' => DeliveryMethod::Courier, 'title' => 'Active courier', 'is_active' => true]);
    DeliveryMethodSetting::factory()->create(['code' => DeliveryMethod::Post, 'title' => 'Active post', 'is_active' => true]);
    PaymentMethodSetting::factory()->create(['code' => PaymentMethod::Card, 'is_active' => true]);

    $this->withCookie(CartManager::COOKIE_NAME, $cart->token)->get(route('checkout.show'))
        ->assertOk()
        ->assertSee('Active transport')
        ->assertSee('/img/checkout/cdek.svg', false)
        ->assertDontSee('Inactive pickup')
        ->assertDontSee('/img/checkout/pickup.svg', false)
        ->assertSee('Active courier')
        ->assertSee('Active post')
        ->assertDontSee('/img/checkout/courier.svg', false)
        ->assertDontSee('/img/checkout/post.svg', false);
});

@props([
    'type',
    'sourceCode',
    'productId' => null,
    'productVariantId' => null,
    'title' => 'Оставить заявку',
])

@php
    $inquiryErrors = $errors->getBag('inquiry');
    $inquirySucceeded = filled(session('inquiry_success'));
    $formAction = $type === \App\Enums\StorefrontInquiryType::ProductConsultation->value && $productId !== null
        ? \Illuminate\Support\Facades\URL::signedRoute('storefront.inquiries.store', [
            'product_context' => (int) $productId,
        ])
        : route('storefront.inquiries.store');
    $privacyPolicyUrl = collect(($storefront ?? null)?->legalDocuments ?? [])
        ->first(fn ($document) => $document->url === route('legal.privacy-policy'))
        ?->url;
@endphp

<div
    id="storefront-inquiry"
    class="inquiry-modal{{ $inquiryErrors->any() ? ' inquiry-modal--open' : '' }}"
    data-inquiry-modal
    @if ($inquiryErrors->any()) data-inquiry-auto-open="form" @endif
>
    <a href="#inquiry-closed" class="inquiry-modal__backdrop" data-inquiry-close tabindex="-1" aria-label="Закрыть форму"></a>
    <section class="inquiry-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="storefront-inquiry-title" tabindex="-1">
        <a href="#inquiry-closed" class="inquiry-modal__close" data-inquiry-close aria-label="Закрыть форму">×</a>
        <h2 id="storefront-inquiry-title" class="inquiry-modal__title">{{ $title }}</h2>
        <p class="inquiry-modal__lead">Оставьте контакты — менеджер свяжется с вами и ответит на вопросы.</p>

        <div class="inquiry-modal__result" data-inquiry-result aria-live="polite">
            @if ($inquiryErrors->any())
                <ul class="inquiry-modal__errors">
                    @foreach ($inquiryErrors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @endif
        </div>

        <form method="POST" action="{{ $formAction }}" class="inquiry-modal__form" data-inquiry-form>
            @csrf
            <input type="hidden" name="type" value="{{ $type }}">
            <input type="hidden" name="source_code" value="{{ $sourceCode }}">
            @if ($productVariantId !== null)
                <input type="hidden" name="product_variant_id" value="{{ old('product_variant_id', $productVariantId) }}" data-inquiry-product-variant>
            @endif

            <div class="inquiry-modal__honeypot" aria-hidden="true">
                <label for="company-website">Сайт компании</label>
                <input id="company-website" type="text" name="company_website" value="" tabindex="-1" autocomplete="off">
            </div>

            <label class="inquiry-modal__field">
                <span>Имя <strong aria-hidden="true">*</strong></span>
                <input type="text" name="name" value="{{ old('name') }}" maxlength="255" autocomplete="name" required>
            </label>
            <label class="inquiry-modal__field">
                <span>Телефон <strong aria-hidden="true">*</strong></span>
                <input type="tel" name="phone" value="{{ old('phone') }}" maxlength="100" autocomplete="tel" inputmode="tel" placeholder="+7 (___) ___-__-__" required>
            </label>
            <label class="inquiry-modal__field">
                <span>Email</span>
                <input type="email" name="email" value="{{ old('email') }}" maxlength="255" autocomplete="email">
            </label>
            <label class="inquiry-modal__field">
                <span>Сообщение</span>
                <textarea name="message" rows="4" maxlength="5000">{{ old('message') }}</textarea>
            </label>

            <button type="submit" class="btn inquiry-modal__submit" data-inquiry-submit>
                <span data-inquiry-submit-label>Отправить заявку</span>
            </button>
            <p class="inquiry-modal__privacy">
                Нажимая кнопку, вы соглашаетесь на обработку персональных данных@if ($privacyPolicyUrl)
                    в соответствии с <a href="{{ $privacyPolicyUrl }}">политикой конфиденциальности</a>@endif.
            </p>
        </form>
    </section>
</div>

<div
    id="storefront-inquiry-success"
    class="inquiry-modal{{ $inquirySucceeded ? ' inquiry-modal--open' : '' }}"
    data-inquiry-success-modal
    @if ($inquirySucceeded) data-inquiry-auto-open="success" @endif
>
    <a href="#inquiry-closed" class="inquiry-modal__backdrop" data-inquiry-close tabindex="-1" aria-label="Закрыть сообщение"></a>
    <section class="inquiry-modal__dialog inquiry-modal__dialog--success" role="dialog" aria-modal="true" aria-labelledby="storefront-inquiry-success-title" tabindex="-1">
        <a href="#inquiry-closed" class="inquiry-modal__close" data-inquiry-close aria-label="Закрыть сообщение">×</a>
        <h2 id="storefront-inquiry-success-title" class="inquiry-modal__title">Спасибо!</h2>
        <p class="inquiry-modal__lead inquiry-modal__lead--success">Заявка принята. Мы свяжемся с вами.</p>
    </section>
</div>

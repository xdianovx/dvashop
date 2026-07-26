@php
    $mainImage = $product?->relationLoaded('mainImage')
        ? $product->getRelation('mainImage')
        : $product?->mainImage()->first();

    $sourceLabel = $mainImage instanceof \App\Models\ProductImage
        ? \App\Models\ProductImage::sourceTypeLabel($mainImage->source_type)
        : 'Дефолтное или заглушка';
@endphp

<div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
        <img
            src="{{ $product?->main_image_url }}"
            alt="{{ $mainImage?->alt ?: $product?->title ?: 'Главное изображение товара' }}"
            class="h-32 w-32 rounded-lg border border-gray-200 bg-gray-50 object-contain dark:border-white/10 dark:bg-gray-950"
        >

        <div class="min-w-0 space-y-1">
            <p class="text-sm font-semibold text-gray-950 dark:text-white">Текущая главная картинка</p>
            <p class="text-sm text-gray-600 dark:text-gray-400">Источник: {{ $sourceLabel }}</p>
            <p class="break-all text-xs text-gray-500 dark:text-gray-500">{{ $mainImage?->path ?: 'Связь ProductImage ещё не создана' }}</p>
        </div>
    </div>
</div>

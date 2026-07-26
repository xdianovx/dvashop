<div class="flex items-center gap-3 rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-gray-950">
    <img
        src="{{ $url }}"
        alt="{{ $alt }}"
        class="h-20 w-20 shrink-0 rounded-md bg-white object-contain dark:bg-gray-900"
    >

    <div class="min-w-0 space-y-1 text-xs">
        <p class="font-medium text-gray-950 dark:text-white">{{ $sourceLabel }}</p>
        <p class="truncate text-gray-600 dark:text-gray-400">{{ $alt }}</p>
        <div class="flex flex-wrap gap-1">
            @if ($isMain)
                <span class="rounded-full bg-primary-100 px-2 py-0.5 text-primary-700 dark:bg-primary-500/20 dark:text-primary-300">Главное</span>
            @endif

            <span class="rounded-full bg-gray-200 px-2 py-0.5 text-gray-700 dark:bg-white/10 dark:text-gray-300">
                {{ $isVisible ? 'Показывается' : 'Скрыто' }}
            </span>
        </div>
    </div>
</div>

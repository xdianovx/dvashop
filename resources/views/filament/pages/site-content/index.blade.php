<x-filament-panels::page>
    <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($this->cards() as $card)
            <a
                href="{{ $card['url'] }}"
                wire:navigate
                class="group rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition hover:-translate-y-0.5 hover:border-primary-400 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 dark:border-white/10 dark:bg-gray-900 dark:hover:border-primary-500"
            >
                <span class="flex size-12 items-center justify-center rounded-xl bg-primary-50 text-primary-600 transition group-hover:bg-primary-100 dark:bg-primary-500/10 dark:text-primary-300">
                    <x-filament::icon :icon="$card['icon']" class="size-6" aria-hidden="true" />
                </span>

                <h2 class="mt-5 text-lg font-bold text-gray-950 dark:text-white">
                    {{ $card['title'] }}
                </h2>

                <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
                    {{ $card['description'] }}
                </p>

                <span class="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-primary-700 dark:text-primary-300">
                    Открыть редактор
                    <x-filament::icon icon="heroicon-m-arrow-right" class="size-4 transition group-hover:translate-x-1" aria-hidden="true" />
                </span>
            </a>
        @endforeach
    </div>
</x-filament-panels::page>

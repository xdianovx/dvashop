<div x-data="{ level: 'all' }" class="min-w-0 max-w-full space-y-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <label for="catalog-import-log-level-{{ $run->id }}" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">Уровень</label>
            <select id="catalog-import-log-level-{{ $run->id }}" x-model="level" class="min-h-11 w-full rounded-lg border-gray-300 bg-white text-sm focus:border-primary-600 focus:ring-primary-600 dark:border-white/10 dark:bg-gray-900 sm:w-auto">
                <option value="all">Все</option>
                <option value="info">Информация</option>
                <option value="warning">Предупреждения</option>
                <option value="error">Ошибки</option>
            </select>
        </div>

        <x-filament::button color="gray" icon="heroicon-o-document-arrow-down" wire:click="downloadLogs({{ $run->id }})" wire:loading.attr="disabled" wire:target="downloadLogs" class="w-full justify-center sm:w-auto">
            Скачать полный лог CSV
        </x-filament::button>
    </div>

    <p class="text-xs leading-5 text-gray-500 dark:text-gray-400">Показаны последние {{ $logs->count() }} записей, не более 200. В CSV включается весь журнал.</p>

    <div class="max-h-[65vh] min-w-0 space-y-2 overflow-y-auto pe-1">
        @forelse ($logs as $log)
            @php($level = $log->level?->value ?? (string) $log->level)
            <article
                x-cloak
                x-show="level === 'all' || level === '{{ $level }}'"
                class="min-w-0 rounded-xl border p-4 {{ $level === 'error' ? 'border-danger-200 bg-danger-50/60 dark:border-danger-500/30 dark:bg-danger-500/10' : ($level === 'warning' ? 'border-warning-200 bg-warning-50/60 dark:border-warning-500/20 dark:bg-warning-500/10' : 'border-gray-200 bg-white dark:border-white/10 dark:bg-white/5') }}"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::badge :color="$level === 'error' ? 'danger' : ($level === 'warning' ? 'warning' : 'gray')">
                        {{ $log->level?->label() ?? $level }}
                    </x-filament::badge>
                    <time class="text-xs text-gray-500 dark:text-gray-400">{{ $log->created_at?->format('d.m.Y H:i:s') }}</time>
                </div>
                <p class="mt-2 break-words text-sm leading-6 text-gray-800 dark:text-gray-100">{{ $log->message }}</p>
                @if ($log->context)
                    <details class="mt-3 min-w-0 text-xs text-gray-600 dark:text-gray-300">
                        <summary class="min-h-8 cursor-pointer rounded py-1 font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600">Технический контекст</summary>
                        <div class="mt-2 max-w-full overflow-x-auto rounded-lg bg-gray-950 p-3 text-gray-100">
                            <pre class="w-max min-w-full whitespace-pre font-mono text-xs leading-5">{{ json_encode($log->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                        </div>
                    </details>
                @endif
            </article>
        @empty
            <div class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-white/15 dark:text-gray-400">Записей пока нет.</div>
        @endforelse
    </div>
</div>

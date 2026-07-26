<div x-data="{ level: 'all' }" class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <label class="block text-sm">
            <span class="mb-1 block font-medium text-gray-950 dark:text-white">Уровень</span>
            <select x-model="level" class="rounded-lg border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-gray-900">
                <option value="all">Все</option>
                <option value="info">Информация</option>
                <option value="warning">Предупреждения</option>
                <option value="error">Ошибки</option>
            </select>
        </label>

        <x-filament::button size="sm" color="gray" icon="heroicon-o-document-arrow-down" wire:click="downloadLogs({{ $run->id }})">
            Скачать полный лог CSV
        </x-filament::button>
    </div>

    <p class="text-xs text-gray-500 dark:text-gray-400">Показаны последние {{ $logs->count() }} записей, не более 200. В CSV включается весь журнал.</p>

    <div class="max-h-[65vh] space-y-2 overflow-y-auto pr-1">
        @forelse ($logs as $log)
            @php($level = $log->level?->value ?? (string) $log->level)
            <article x-show="level === 'all' || level === '{{ $level }}'" class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::badge :color="$level === 'error' ? 'danger' : ($level === 'warning' ? 'warning' : 'gray')">
                        {{ $log->level?->label() ?? $level }}
                    </x-filament::badge>
                    <time class="text-xs text-gray-500">{{ $log->created_at?->format('d.m.Y H:i:s') }}</time>
                </div>
                <p class="mt-2 text-sm text-gray-800 dark:text-gray-100">{{ $log->message }}</p>
                @if ($log->context)
                    <details class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        <summary class="cursor-pointer">Технический контекст</summary>
                        <pre class="mt-2 overflow-x-auto whitespace-pre-wrap rounded bg-gray-50 p-2 font-mono dark:bg-black/20">{{ json_encode($log->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                    </details>
                @endif
            </article>
        @empty
            <div class="rounded-lg border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500 dark:border-white/15">Записей пока нет.</div>
        @endforelse
    </div>
</div>

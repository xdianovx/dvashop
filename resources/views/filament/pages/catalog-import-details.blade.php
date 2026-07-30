@php($progress = app(\App\Services\Import\ImportProgressService::class)->forRun($run))

<div class="min-w-0 space-y-5 text-sm">
    <div class="flex flex-wrap items-center gap-3">
        <x-filament::badge :color="$run->status?->color() ?? 'gray'">{{ $progress->statusLabel }}</x-filament::badge>
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ $progress->stageLabel }}</span>
    </div>

    <dl class="grid min-w-0 gap-3 sm:grid-cols-2">
        @foreach ([
            ['Файл', $run->original_name, true],
            ['Источник', $run->type, false],
            ['Создан', $run->created_at?->format('d.m.Y H:i:s') ?? '—', false],
            ['Завершён', $run->finished_at?->format('d.m.Y H:i:s') ?? '—', false],
            ['Строки', $progress->rowsLabel, false],
            ['Изображения', $progress->imagesLabel, false],
            ['Товары', 'Создано '.$run->created_products.', обновлено '.$run->updated_products.', архивировано '.$run->archived_products, false],
            ['Предупреждения и ошибки', $run->warnings_count.' / '.$run->errors_count, false],
            ['Архивация отсутствующих товаров', $run->archive_skipped ? 'Пропущена' : 'Выполнена или не требовалась', false],
        ] as [$label, $value, $breakAll])
            <div class="min-w-0 rounded-xl border border-gray-200 bg-gray-50/70 p-4 dark:border-white/10 dark:bg-white/5">
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                <dd class="mt-1 font-semibold text-gray-950 dark:text-white {{ $breakAll ? 'break-all' : 'break-words' }}">{{ $value }}</dd>
            </div>
        @endforeach

        @if ($run->archive_skip_reason)
            <div class="min-w-0 rounded-xl border border-warning-200 bg-warning-50/70 p-4 dark:border-warning-500/20 dark:bg-warning-500/10">
                <dt class="text-xs font-medium text-warning-800 dark:text-warning-300">Причина пропуска</dt>
                <dd class="mt-1 break-words font-semibold text-warning-950 dark:text-warning-100">{{ $run->archive_skip_reason === 'row_errors' ? 'Ошибки обработки строк' : $run->archive_skip_reason }}</dd>
            </div>
        @endif
    </dl>

    @if ($run->last_error)
        <div class="flex items-start gap-3 rounded-xl border border-danger-200 bg-danger-50 p-4 text-danger-800 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-200" role="alert">
            <x-filament::icon icon="heroicon-o-x-circle" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <div class="min-w-0">
                <p class="font-semibold">Последняя ошибка</p>
                <p class="mt-1 break-words">{{ $run->last_error }}</p>
            </div>
        </div>
    @endif

    @if ($problems->isNotEmpty())
        <div class="rounded-xl border border-warning-200 bg-warning-50/60 p-4 dark:border-warning-500/20 dark:bg-warning-500/10">
            <p class="font-semibold text-warning-900 dark:text-warning-200">Последние предупреждения и ошибки</p>
            <div class="mt-3 space-y-2">
                @foreach ($problems as $problem)
                    @php($level = $problem->level?->value ?? (string) $problem->level)
                    <article class="rounded-lg bg-white/80 p-3 dark:bg-black/15">
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <span class="font-semibold {{ $level === 'error' ? 'text-danger-700 dark:text-danger-300' : 'text-warning-800 dark:text-warning-300' }}">{{ $level === 'error' ? 'Ошибка' : 'Предупреждение' }}</span>
                            <time class="text-gray-500 dark:text-gray-400">{{ $problem->created_at?->format('d.m.Y H:i:s') }}</time>
                        </div>
                        <p class="mt-1 break-words text-gray-700 dark:text-gray-200">{{ $problem->message }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    @endif
</div>

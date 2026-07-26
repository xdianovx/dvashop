@php($progress = app(\App\Services\Import\ImportProgressService::class)->forRun($run))

<div class="space-y-5 text-sm">
    <div class="flex flex-wrap items-center gap-3">
        <x-filament::badge :color="$run->status?->color() ?? 'gray'">{{ $progress->statusLabel }}</x-filament::badge>
        <span class="text-gray-500 dark:text-gray-400">{{ $progress->stageLabel }}</span>
    </div>

    <dl class="grid gap-3 sm:grid-cols-2">
        <div><dt class="text-xs text-gray-500">Файл</dt><dd class="mt-1 font-medium">{{ $run->original_name }}</dd></div>
        <div><dt class="text-xs text-gray-500">Источник</dt><dd class="mt-1 font-medium">{{ $run->type }}</dd></div>
        <div><dt class="text-xs text-gray-500">Создан</dt><dd class="mt-1">{{ $run->created_at?->format('d.m.Y H:i:s') ?? '—' }}</dd></div>
        <div><dt class="text-xs text-gray-500">Завершён</dt><dd class="mt-1">{{ $run->finished_at?->format('d.m.Y H:i:s') ?? '—' }}</dd></div>
        <div><dt class="text-xs text-gray-500">Строки</dt><dd class="mt-1">{{ $progress->rowsLabel }}</dd></div>
        <div><dt class="text-xs text-gray-500">Изображения</dt><dd class="mt-1">{{ $progress->imagesLabel }}</dd></div>
        <div><dt class="text-xs text-gray-500">Товары</dt><dd class="mt-1">Создано {{ $run->created_products }}, обновлено {{ $run->updated_products }}, архивировано {{ $run->archived_products }}</dd></div>
        <div><dt class="text-xs text-gray-500">Предупреждения и ошибки</dt><dd class="mt-1">{{ $run->warnings_count }} / {{ $run->errors_count }}</dd></div>
        <div><dt class="text-xs text-gray-500">Архивация отсутствующих товаров</dt><dd class="mt-1">{{ $run->archive_skipped ? 'Пропущена' : 'Выполнена или не требовалась' }}</dd></div>
        @if ($run->archive_skip_reason)
            <div><dt class="text-xs text-gray-500">Причина пропуска</dt><dd class="mt-1">{{ $run->archive_skip_reason === 'row_errors' ? 'Ошибки обработки строк' : $run->archive_skip_reason }}</dd></div>
        @endif
    </dl>

    @if ($run->last_error)
        <div class="rounded-lg bg-danger-50 p-3 text-danger-700 dark:bg-danger-500/10 dark:text-danger-300">{{ $run->last_error }}</div>
    @endif

    @if ($problems->isNotEmpty())
        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
            <div class="mb-2 font-semibold">Последние предупреждения и ошибки</div>
            @foreach ($problems as $problem)
                <div class="text-xs">[{{ $problem->created_at?->format('d.m.Y H:i:s') }}] {{ $problem->message }}</div>
            @endforeach
        </div>
    @endif
</div>

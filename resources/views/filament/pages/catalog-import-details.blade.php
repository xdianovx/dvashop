<div class="space-y-4 text-sm">
    <div class="grid gap-3 md:grid-cols-2">
        <div><strong>Файл:</strong> {{ $run->original_name }}</div>
        <div><strong>Source/type:</strong> {{ $run->type }}</div>
        <div><strong>Строки:</strong> {{ $run->processed_rows }} / {{ $run->total_rows }}</div>
        <div><strong>Изображения:</strong> {{ $run->processed_images }} / {{ $run->queued_images }}, failed {{ $run->failed_images }}</div>
        <div><strong>Товары:</strong> +{{ $run->created_products }} / ~{{ $run->updated_products }} / arch {{ $run->archived_products }}</div>
        <div><strong>Ошибки/warnings:</strong> {{ $run->errors_count }} / {{ $run->warnings_count }}</div>
    </div>

    @if ($run->last_error)
        <div class="rounded-lg bg-danger-50 p-3 text-danger-700">{{ $run->last_error }}</div>
    @endif

    <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
        <div class="mb-2 font-semibold">Последние логи</div>
        @forelse ($logs as $log)
            <div class="font-mono text-xs">
                [{{ $log->created_at?->format('d.m.Y H:i:s') }}]
                {{ strtoupper($log->level?->value ?? (string) $log->level) }}:
                {{ $log->message }}
                @if ($log->context)
                    <span class="text-gray-400">{{ json_encode($log->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</span>
                @endif
            </div>
        @empty
            <div class="text-gray-400">Логов пока нет.</div>
        @endforelse
    </div>
</div>

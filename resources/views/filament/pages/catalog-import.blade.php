<x-filament-panels::page>
    <div class="space-y-6">
        <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Загрузить файл импорта</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Поддерживаются CSV и XLSX. На этом этапе строки только читаются чанками, товары не создаются.
            </p>

            <form wire:submit.prevent="upload" class="mt-4 space-y-4">
                <input
                    type="file"
                    wire:model="file"
                    accept=".csv,.xlsx"
                    class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-800"
                />

                @error('file')
                    <p class="text-sm text-danger-600">{{ $message }}</p>
                @enderror

                <x-filament::button type="submit" wire:loading.attr="disabled">
                    Загрузить файл
                </x-filament::button>
            </form>
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Последние импорты</h2>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-4">ID</th>
                            <th class="py-2 pr-4">Файл</th>
                            <th class="py-2 pr-4">Статус</th>
                            <th class="py-2 pr-4">Прогресс</th>
                            <th class="py-2 pr-4">Строка</th>
                            <th class="py-2 pr-4">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->runs() as $run)
                            <tr class="border-b border-gray-100 align-top dark:border-white/5">
                                <td class="py-3 pr-4 font-mono">#{{ $run->id }}</td>
                                <td class="py-3 pr-4">
                                    <div class="font-medium text-gray-950 dark:text-white">{{ $run->original_name }}</div>
                                    <div class="text-xs text-gray-500">{{ $run->created_at?->format('d.m.Y H:i') }}</div>
                                </td>
                                <td class="py-3 pr-4">{{ $this->statusLabel($run) }}</td>
                                <td class="py-3 pr-4">
                                    <div class="h-2 w-40 rounded-full bg-gray-100 dark:bg-white/10">
                                        <div class="h-2 rounded-full bg-primary-500" style="width: {{ $run->progressPercent() }}%"></div>
                                    </div>
                                    <div class="mt-1 text-xs text-gray-500">{{ $run->progressPercent() }}%</div>
                                </td>
                                <td class="py-3 pr-4">{{ $run->processed_rows }} / {{ $run->total_rows }}</td>
                                <td class="py-3 pr-4">
                                    <div class="flex flex-wrap gap-2">
                                        @if ($run->status?->value === 'ready')
                                            <x-filament::button size="xs" wire:click="start({{ $run->id }})">Старт</x-filament::button>
                                        @endif

                                        @if ($run->status?->value === 'running')
                                            <x-filament::button size="xs" color="warning" wire:click="pause({{ $run->id }})">Пауза</x-filament::button>
                                        @endif

                                        @if ($run->status?->value === 'paused')
                                            <x-filament::button size="xs" wire:click="resume({{ $run->id }})">Продолжить</x-filament::button>
                                        @endif

                                        @if (! $run->isTerminal())
                                            <x-filament::button size="xs" color="danger" wire:click="cancel({{ $run->id }})">Отменить</x-filament::button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <td></td>
                                <td colspan="5" class="pb-4 pr-4 text-xs text-gray-600 dark:text-gray-300">
                                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                                        <div class="mb-2 font-semibold">Последние логи</div>
                                        @forelse ($this->latestLogs($run) as $log)
                                            <div class="font-mono">
                                                [{{ $log->created_at?->format('H:i:s') }}]
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
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-6 text-center text-gray-500">Импортов пока нет.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-filament-panels::page>

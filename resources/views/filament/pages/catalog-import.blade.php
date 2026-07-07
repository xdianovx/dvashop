<x-filament-panels::page>
    <div class="space-y-6" @if ($this->hasActiveImport()) wire:poll.2s="refreshImportSnapshot" @endif>
        <x-filament::section>
            <x-slot name="heading">Загрузить файл импорта</x-slot>
            <x-slot name="description">
                Поддерживаются CSV и XLSX. Импорт создаёт марки, модели, поколения, категории, товары, применимость и ставит изображения в очередь.
            </x-slot>

            <form wire:submit.prevent="submitImport" class="space-y-4">
                <div class="grid gap-4 md:grid-cols-4">
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Файл</label>
                        <input
                            type="file"
                            wire:model="file"
                            accept=".csv,.xlsx"
                            class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-800"
                        />
                        @error('file')
                            <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Источник импорта</label>
                        <input
                            type="text"
                            wire:model.defer="type"
                            class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-800"
                            placeholder="catalog"
                        />
                        @error('type')
                            <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Размер чанка</label>
                        <select
                            wire:model.defer="chunkSize"
                            class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-800"
                        >
                            <option value="100">100</option>
                            <option value="300">300</option>
                            <option value="500">500</option>
                        </select>
                        @error('chunkSize')
                            <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                    <input type="checkbox" wire:model.defer="startAfterUpload" class="rounded border-gray-300" />
                    Запустить сразу после загрузки
                </label>

                <div>
                    <x-filament::button type="submit" wire:loading.attr="disabled">
                        Загрузить файл
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        @if ($activeRun = $this->activeRun())
            <x-filament::section>
                <x-slot name="heading">Активный импорт #{{ $activeRun->id }}</x-slot>
                <x-slot name="description">{{ $activeRun->original_name }}</x-slot>

                <div class="space-y-5">
                    <div class="flex flex-wrap items-center gap-3">
                        <x-filament::badge :color="$activeRun->status?->color() ?? 'gray'">
                            {{ $this->statusLabel($activeRun) }}
                        </x-filament::badge>

                        <span class="text-sm text-gray-500 dark:text-gray-400">
                            Последний heartbeat: {{ $activeRun->heartbeat_at?->format('d.m.Y H:i:s') ?? '—' }}
                        </span>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <div class="mb-1 flex justify-between text-sm text-gray-600 dark:text-gray-300">
                                <span>Строки</span>
                                <span>{{ $activeRun->processed_rows }} / {{ $activeRun->total_rows }} · {{ $activeRun->rowsProgressPercent() }}%</span>
                            </div>
                            <div class="h-3 rounded-full bg-gray-100 dark:bg-white/10">
                                <div class="h-3 rounded-full bg-primary-500" style="width: {{ $activeRun->rowsProgressPercent() }}%"></div>
                            </div>
                        </div>

                        <div>
                            <div class="mb-1 flex justify-between text-sm text-gray-600 dark:text-gray-300">
                                <span>Изображения</span>
                                <span>{{ $activeRun->processed_images + $activeRun->failed_images }} / {{ $activeRun->queued_images }} · ошибок {{ $activeRun->failed_images }}</span>
                            </div>
                            <div class="h-3 rounded-full bg-gray-100 dark:bg-white/10">
                                <div class="h-3 rounded-full bg-primary-500" style="width: {{ $activeRun->imagesProgressPercent() }}%"></div>
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-3 text-sm md:grid-cols-4">
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">Товары: +{{ $activeRun->created_products }} / ~{{ $activeRun->updated_products }} / архив {{ $activeRun->archived_products }}</div>
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">Авто: +{{ $activeRun->created_makes + $activeRun->created_models + $activeRun->created_generations }} / ~{{ $activeRun->updated_makes + $activeRun->updated_models + $activeRun->updated_generations }}</div>
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">Категории: +{{ $activeRun->created_categories }} / ~{{ $activeRun->updated_categories }}</div>
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">Ошибки/предупреждения: {{ $activeRun->errors_count }} / {{ $activeRun->warnings_count }}</div>
                    </div>

                    @if ($activeRun->last_error)
                        <div class="rounded-lg border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-300">
                            {{ $activeRun->last_error }}
                        </div>
                    @endif

                    <div class="flex flex-wrap gap-2">
                        @if ($activeRun->status === \App\Enums\ImportRunStatus::Ready)
                            <x-filament::button size="sm" wire:click="start({{ $activeRun->id }})">Старт</x-filament::button>
                        @endif

                        @if ($activeRun->status?->isRowsRunning())
                            <x-filament::button size="sm" color="warning" wire:click="pause({{ $activeRun->id }})">Пауза</x-filament::button>
                        @endif

                        @if ($activeRun->status === \App\Enums\ImportRunStatus::Paused)
                            <x-filament::button size="sm" wire:click="resume({{ $activeRun->id }})">Продолжить</x-filament::button>
                        @endif

                        @if (! $activeRun->isTerminal())
                            <x-filament::button size="sm" color="danger" wire:click="cancel({{ $activeRun->id }})">Отменить</x-filament::button>
                        @endif
                    </div>

                    <div class="rounded-lg bg-gray-50 p-3 text-xs text-gray-700 dark:bg-white/5 dark:text-gray-200">
                        <div class="mb-2 font-semibold">Последние логи</div>
                        @forelse ($this->latestLogs($activeRun, 10) as $log)
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
                </div>
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">История импортов</x-slot>
            <x-slot name="description">Сортировка по ID desc, фильтры по статусу, источнику и дате.</x-slot>

            {{ $this->table }}
        </x-filament::section>
    </div>
</x-filament-panels::page>

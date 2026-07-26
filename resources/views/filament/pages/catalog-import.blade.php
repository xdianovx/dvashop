<x-filament-panels::page>
    <div class="space-y-6" @if ($this->hasActiveImport()) wire:poll.2s="refreshImportSnapshot" @endif>
        <x-filament::section>
            <x-slot name="heading">Загрузка файла</x-slot>
            <x-slot name="description">
                Поддерживаются файлы CSV и XLSX размером до 50 МБ. После загрузки импорт можно запустить сразу или оставить в состоянии готовности.
            </x-slot>

            <form wire:submit.prevent="submitImport" class="space-y-5">
                <div class="grid gap-5 lg:grid-cols-4">
                    <div class="lg:col-span-2">
                        <span class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">Файл импорта</span>
                        <label for="catalog-import-file" class="flex min-h-24 cursor-pointer items-center justify-center rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 text-center transition hover:border-primary-500 hover:bg-primary-50/50 dark:border-white/15 dark:bg-white/5 dark:hover:border-primary-400 dark:hover:bg-primary-500/10">
                            <span>
                                <span class="block text-sm font-semibold text-primary-600 dark:text-primary-400">Выбрать CSV/XLSX</span>
                                <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">
                                    {{ $file?->getClientOriginalName() ?? 'Файл не выбран' }}
                                </span>
                            </span>
                        </label>
                        <input id="catalog-import-file" type="file" wire:model="file" accept=".csv,.xlsx" class="sr-only" />
                        <div wire:loading wire:target="file" class="mt-2 text-xs text-primary-600 dark:text-primary-400">Файл загружается…</div>
                        @error('file')
                            <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="catalog-import-type" class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">Источник импорта</label>
                        <input id="catalog-import-type" type="text" wire:model.defer="type" placeholder="catalog" class="block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-white/10 dark:bg-white/5" />
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Используется для безопасного обновления и архивирования только товаров этого источника.</p>
                        @error('type')
                            <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="catalog-import-chunk" class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">Строк в одном чанке</label>
                        <select id="catalog-import-chunk" wire:model.defer="chunkSize" class="block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-white/10 dark:bg-white/5">
                            <option value="100">100</option>
                            <option value="300">300</option>
                            <option value="500">500</option>
                        </select>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Меньший чанк снижает нагрузку и чаще сохраняет прогресс; больший может ускорить импорт.</p>
                        @error('chunkSize')
                            <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-4 border-t border-gray-200 pt-5 dark:border-white/10">
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" wire:model.defer="startAfterUpload" class="rounded border-gray-300 text-primary-600 focus:ring-primary-600" />
                        Запустить сразу после загрузки
                    </label>

                    <x-filament::button type="submit" icon="heroicon-o-arrow-up-tray" wire:loading.attr="disabled" wire:target="submitImport,file" :disabled="$uploadInProgress">
                        <span wire:loading.remove wire:target="submitImport">Загрузить файл</span>
                        <span wire:loading wire:target="submitImport">Создаём импорт…</span>
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        @if ($activeRun = $this->activeRun())
            @php($progress = $this->progress($activeRun))

            <x-filament::section>
                <x-slot name="heading">Активный импорт #{{ $activeRun->id }}</x-slot>
                <x-slot name="description">{{ $activeRun->original_name }}</x-slot>

                <div class="space-y-6">
                    <div class="flex flex-wrap items-center gap-3">
                        <x-filament::badge :color="$activeRun->status?->color() ?? 'gray'">{{ $progress->statusLabel }}</x-filament::badge>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ $progress->stageLabel }}</span>
                        <span class="text-sm text-gray-500 dark:text-gray-400">Источник: {{ $activeRun->type }}</span>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-gray-50/70 p-4 dark:border-white/10 dark:bg-white/5">
                        <div class="mb-2 flex items-center justify-between gap-3 text-sm">
                            <span class="font-semibold text-gray-950 dark:text-white">Общая готовность</span>
                            <span class="tabular-nums text-gray-600 dark:text-gray-300">{{ $progress->overallPercent }}%</span>
                        </div>
                        <div class="h-3 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                            <div class="h-full rounded-full bg-primary-600 transition-all duration-500" style="width: {{ $progress->overallPercent }}%"></div>
                        </div>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        @foreach ([['Строки', $progress->rowsLabel, $progress->rowsPercent], ['Изображения', $progress->imagesLabel, $progress->imagesPercent]] as [$heading, $label, $percent])
                            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                                <div class="mb-2 flex items-center justify-between gap-3 text-sm">
                                    <span class="font-medium text-gray-950 dark:text-white">{{ $heading }}</span>
                                    <span class="tabular-nums text-gray-500 dark:text-gray-400">{{ $percent }}%</span>
                                </div>
                                <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                                    <div class="h-full rounded-full bg-primary-500 transition-all duration-500" style="width: {{ $percent }}%"></div>
                                </div>
                                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                            </div>
                        @endforeach
                    </div>

                    <dl class="grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-5">
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5"><dt class="text-xs text-gray-500">Создан</dt><dd class="mt-1 font-medium">{{ $activeRun->created_at?->format('d.m.Y H:i:s') ?? '—' }}</dd></div>
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5"><dt class="text-xs text-gray-500">Запущен</dt><dd class="mt-1 font-medium">{{ $activeRun->started_at?->format('d.m.Y H:i:s') ?? '—' }}</dd></div>
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5"><dt class="text-xs text-gray-500">Последняя активность</dt><dd class="mt-1 font-medium">{{ $activeRun->heartbeat_at?->format('d.m.Y H:i:s') ?? '—' }}</dd></div>
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5"><dt class="text-xs text-gray-500">Завершён</dt><dd class="mt-1 font-medium">{{ $activeRun->finished_at?->format('d.m.Y H:i:s') ?? '—' }}</dd></div>
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5"><dt class="text-xs text-gray-500">Товары</dt><dd class="mt-1 font-medium">+{{ $activeRun->created_products }} / обновлено {{ $activeRun->updated_products }} / архив {{ $activeRun->archived_products }}</dd></div>
                    </dl>

                    @if ($activeRun->last_error)
                        <div class="rounded-lg border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-300">{{ $activeRun->last_error }}</div>
                    @endif

                    @if ($problems = $this->latestProblems($activeRun, 3))
                        @if ($problems->isNotEmpty())
                            <div class="rounded-xl border border-warning-200 bg-warning-50/60 p-4 dark:border-warning-500/20 dark:bg-warning-500/10">
                                <div class="mb-2 text-sm font-semibold text-warning-800 dark:text-warning-300">Последние предупреждения и ошибки</div>
                                <div class="space-y-1 text-sm text-gray-700 dark:text-gray-200">
                                    @foreach ($problems as $problem)
                                        <div>[{{ $problem->created_at?->format('H:i:s') }}] {{ $problem->message }}</div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endif

                    <div class="flex flex-wrap gap-2">
                        @if ($activeRun->status === \App\Enums\ImportRunStatus::Ready)
                            <x-filament::button size="sm" icon="heroicon-o-play" wire:click="start({{ $activeRun->id }})">Старт</x-filament::button>
                        @endif
                        @if ($activeRun->status?->isActive() && $activeRun->status !== \App\Enums\ImportRunStatus::Paused)
                            <x-filament::button size="sm" color="warning" icon="heroicon-o-pause" wire:click="pause({{ $activeRun->id }})">Пауза</x-filament::button>
                        @endif
                        @if ($activeRun->status === \App\Enums\ImportRunStatus::Paused)
                            <x-filament::button size="sm" icon="heroicon-o-arrow-path" wire:click="resume({{ $activeRun->id }})">Продолжить</x-filament::button>
                        @endif
                        @if (! $activeRun->isTerminal())
                            <x-filament::button size="sm" color="danger" icon="heroicon-o-x-mark" wire:click="cancel({{ $activeRun->id }})" wire:confirm="Отменить импорт? Уже внесённые изменения сохранятся.">Отменить</x-filament::button>
                        @endif

                        <x-filament::modal id="active-import-logs-{{ $activeRun->id }}" width="5xl" slide-over>
                            <x-slot name="trigger"><x-filament::button size="sm" color="gray" icon="heroicon-o-list-bullet">Открыть логи</x-filament::button></x-slot>
                            <x-slot name="heading">Логи импорта #{{ $activeRun->id }}</x-slot>
                            @include('filament.pages.catalog-import-logs', ['run' => $activeRun, 'logs' => $this->latestLogs($activeRun, 200)])
                        </x-filament::modal>

                        <x-filament::button size="sm" color="gray" icon="heroicon-o-document-text" wire:click="downloadReport({{ $activeRun->id }})">Скачать отчёт</x-filament::button>
                    </div>
                </div>
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">История импортов</x-slot>
            <x-slot name="description">Последние запуски, их результаты, отчёты и полные журналы.</x-slot>
            {{ $this->table }}
        </x-filament::section>
    </div>
</x-filament-panels::page>

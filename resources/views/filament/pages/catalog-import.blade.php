<x-filament-panels::page>
    <div class="space-y-6" @if ($this->hasActiveImport()) wire:poll.2s="refreshImportSnapshot" @endif>
        <x-filament::section>
            <x-slot name="heading">Новый импорт каталога</x-slot>
            <x-slot name="description">
                Загрузите таблицу CSV или XLSX. После загрузки система создаст марки, модели, поколения, товары, применимости и поставит изображения в очередь обработки.
            </x-slot>

            <div class="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(17rem,1fr)]">
                <form wire:submit.prevent="submitImport" class="min-w-0 space-y-5">
                    <div class="min-w-0">
                        <span id="catalog-import-file-label" class="mb-2 block text-sm font-semibold text-gray-950 dark:text-white">
                            Файл каталога
                        </span>

                        @if ($file)
                            @php
                                $fileSize = (int) $file->getSize();
                                $readableFileSize = $fileSize >= 1048576
                                    ? number_format($fileSize / 1048576, 1, ',', ' ').' МБ'
                                    : max(1, (int) ceil($fileSize / 1024)).' КБ';
                            @endphp

                            <div class="rounded-2xl border border-success-200 bg-success-50/70 p-4 dark:border-success-500/30 dark:bg-success-500/10 sm:p-5">
                                <div class="flex min-w-0 items-start gap-4">
                                    <div class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-white text-success-600 shadow-sm ring-1 ring-success-200 dark:bg-white/10 dark:text-success-300 dark:ring-success-500/30">
                                        <x-filament::icon icon="heroicon-o-document-text" class="size-6" aria-hidden="true" />
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <p class="break-all text-base font-semibold text-gray-950 dark:text-white">
                                            {{ $file->getClientOriginalName() }}
                                        </p>
                                        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-600 dark:text-gray-300">
                                            <span>{{ $readableFileSize }}</span>
                                            <span class="inline-flex items-center gap-1.5 font-medium text-success-700 dark:text-success-300">
                                                <x-filament::icon icon="heroicon-m-check-circle" class="size-4" aria-hidden="true" />
                                                Готов к загрузке
                                            </span>
                                        </div>
                                    </div>

                                    <button
                                        type="button"
                                        wire:click="removeSelectedFile"
                                        wire:loading.attr="disabled"
                                        wire:target="removeSelectedFile,submitImport,file"
                                        class="inline-flex size-11 shrink-0 items-center justify-center rounded-xl text-gray-500 transition hover:bg-white hover:text-danger-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-50 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-danger-300 dark:focus-visible:ring-offset-gray-900"
                                        aria-label="Убрать выбранный файл"
                                    >
                                        <x-filament::icon icon="heroicon-o-trash" class="size-5" aria-hidden="true" />
                                    </button>
                                </div>

                                <div class="mt-4 border-t border-success-200 pt-4 dark:border-success-500/20">
                                    <input
                                        id="catalog-import-file-replace"
                                        type="file"
                                        wire:model="file"
                                        wire:loading.attr="disabled"
                                        wire:target="file,submitImport"
                                        accept=".csv,.xlsx"
                                        class="peer sr-only"
                                        aria-describedby="catalog-import-file-help"
                                    />
                                    <label for="catalog-import-file-replace" class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold text-primary-700 transition hover:bg-white focus-within:outline-none peer-focus-visible:ring-2 peer-focus-visible:ring-primary-600 peer-focus-visible:ring-offset-2 dark:text-primary-300 dark:hover:bg-white/10 dark:peer-focus-visible:ring-offset-gray-900">
                                        <x-filament::icon icon="heroicon-o-arrow-path" class="size-5" aria-hidden="true" />
                                        Заменить файл
                                    </label>
                                </div>
                            </div>
                        @else
                            <div class="group relative min-h-44 overflow-hidden rounded-2xl border-2 border-dashed {{ $errors->has('file') ? 'border-danger-500 bg-danger-50/70 dark:border-danger-400 dark:bg-danger-500/10' : 'border-gray-300 bg-gray-50/80 hover:border-primary-500 hover:bg-primary-50/70 dark:border-white/20 dark:bg-white/5 dark:hover:border-primary-400 dark:hover:bg-primary-500/10' }} transition focus-within:border-primary-600 focus-within:ring-4 focus-within:ring-primary-600/20">
                                <input
                                    id="catalog-import-file"
                                    type="file"
                                    wire:model="file"
                                    wire:loading.attr="disabled"
                                    wire:target="file,submitImport"
                                    accept=".csv,.xlsx"
                                    class="absolute inset-0 z-10 size-full cursor-pointer opacity-0 disabled:cursor-wait"
                                    aria-labelledby="catalog-import-file-label catalog-import-drop-title"
                                    aria-describedby="catalog-import-file-help catalog-import-file-error"
                                    aria-invalid="{{ $errors->has('file') ? 'true' : 'false' }}"
                                />

                                <div wire:loading.remove wire:target="file" class="pointer-events-none flex min-h-44 flex-col items-center justify-center px-5 py-7 text-center">
                                    <div class="flex size-12 items-center justify-center rounded-2xl bg-white text-primary-600 shadow-sm ring-1 ring-gray-200 transition group-hover:-translate-y-0.5 group-hover:shadow-md dark:bg-white/10 dark:text-primary-300 dark:ring-white/10">
                                        <x-filament::icon icon="heroicon-o-arrow-up-tray" class="size-6" aria-hidden="true" />
                                    </div>
                                    <p id="catalog-import-drop-title" class="mt-4 text-lg font-bold text-gray-950 dark:text-white">Перетащите файл сюда</p>
                                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">или нажмите, чтобы выбрать CSV/XLSX</p>
                                    <span class="mt-4 inline-flex min-h-11 items-center rounded-xl bg-primary-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm">
                                        Выбрать файл
                                    </span>
                                    <p id="catalog-import-file-help" class="mt-3 text-xs font-medium text-gray-500 dark:text-gray-400">CSV или XLSX, до 50 МБ</p>
                                </div>

                                <div wire:loading.flex wire:target="file" class="pointer-events-none min-h-44 flex-col items-center justify-center px-5 py-7 text-center" role="status" aria-live="polite" aria-atomic="true">
                                    <x-filament::loading-indicator class="size-8 text-primary-600" />
                                    <p class="mt-4 text-base font-semibold text-gray-950 dark:text-white">Загружаем файл…</p>
                                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Не закрывайте страницу до завершения загрузки.</p>
                                </div>
                            </div>
                        @endif

                        @error('file')
                            <p id="catalog-import-file-error" class="mt-2 flex items-start gap-2 text-sm font-medium text-danger-600 dark:text-danger-400" role="alert">
                                <x-filament::icon icon="heroicon-m-exclamation-circle" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                {{ $message }}
                            </p>
                        @else
                            <span id="catalog-import-file-error" class="sr-only" aria-live="polite"></span>
                        @enderror
                    </div>

                    <x-filament::button
                        type="submit"
                        size="lg"
                        icon="heroicon-o-arrow-up-tray"
                        wire:loading.attr="disabled"
                        wire:target="submitImport,file,removeSelectedFile"
                        :disabled="blank($file) || $uploadInProgress"
                        class="w-full justify-center sm:w-auto"
                    >
                        <span wire:loading.remove wire:target="submitImport">
                            {{ $startAfterUpload ? 'Загрузить и запустить импорт' : 'Только загрузить файл' }}
                        </span>
                        <span wire:loading wire:target="submitImport" role="status">Создаём импорт…</span>
                    </x-filament::button>

                    <details class="group rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-white/5">
                        <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-4 rounded-xl px-4 py-3 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-900">
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold text-gray-950 dark:text-white">Дополнительные настройки</span>
                                <span class="mt-0.5 block truncate text-xs text-gray-500 dark:text-gray-400">Источник: {{ $type }} · чанк: {{ $chunkSize }} строк</span>
                            </span>
                            <x-filament::icon icon="heroicon-m-chevron-down" class="size-5 shrink-0 text-gray-500 transition group-open:rotate-180" aria-hidden="true" />
                        </summary>

                        <div class="grid gap-5 border-t border-gray-200 p-4 dark:border-white/10 lg:grid-cols-2">
                            <div>
                                <label for="catalog-import-type" class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">Ключ источника</label>
                                <input id="catalog-import-type" type="text" wire:model.defer="type" placeholder="catalog" class="block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm focus:border-primary-600 focus:ring-primary-600 dark:border-white/10 dark:bg-white/5" aria-describedby="catalog-import-type-help catalog-import-type-error" aria-invalid="{{ $errors->has('type') ? 'true' : 'false' }}" />
                                <p id="catalog-import-type-help" class="mt-2 text-xs leading-5 text-gray-500 dark:text-gray-400">Используется при повторном импорте, чтобы обновлять и архивировать только товары из этого набора данных. Обычно менять не нужно.</p>
                                @error('type')
                                    <p id="catalog-import-type-error" class="mt-2 text-sm text-danger-600 dark:text-danger-400" role="alert">{{ $message }}</p>
                                @else
                                    <span id="catalog-import-type-error" class="sr-only"></span>
                                @enderror
                            </div>

                            <div>
                                <label for="catalog-import-chunk" class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">Размер чанка</label>
                                <select id="catalog-import-chunk" wire:model.defer="chunkSize" class="block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm focus:border-primary-600 focus:ring-primary-600 dark:border-white/10 dark:bg-white/5" aria-describedby="catalog-import-chunk-help catalog-import-chunk-error" aria-invalid="{{ $errors->has('chunkSize') ? 'true' : 'false' }}">
                                    <option value="10">10 — рекомендуемый режим</option>
                                    <option value="20">20 — повышенная нагрузка</option>
                                    <option value="30">30 — быстрее, но выше нагрузка</option>
                                </select>
                                <p id="catalog-import-chunk-help" class="mt-2 text-xs leading-5 text-gray-500 dark:text-gray-400">Количество строк таблицы, обрабатываемых одной задачей очереди.</p>
                                @error('chunkSize')
                                    <p id="catalog-import-chunk-error" class="mt-2 text-sm text-danger-600 dark:text-danger-400" role="alert">{{ $message }}</p>
                                @else
                                    <span id="catalog-import-chunk-error" class="sr-only"></span>
                                @enderror
                            </div>

                            <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5 lg:col-span-2">
                                <label class="flex cursor-pointer items-start gap-3">
                                    <input type="checkbox" wire:model.live="startAfterUpload" class="mt-0.5 size-5 rounded border-gray-300 text-primary-600 focus:ring-primary-600 dark:border-white/20 dark:bg-white/5" />
                                    <span>
                                        <span class="block text-sm font-semibold text-gray-950 dark:text-white">Запустить импорт сразу после загрузки</span>
                                        <span class="mt-1 block text-xs leading-5 text-gray-500 dark:text-gray-400">Если отключено, файл появится в истории со статусом “Готов”, и импорт можно будет запустить вручную.</span>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </details>
                </form>

                <aside class="rounded-2xl bg-gray-950 p-5 text-white shadow-sm dark:bg-black/30" aria-labelledby="catalog-import-steps-title">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-xl bg-primary-500/20 text-primary-300">
                            <x-filament::icon icon="heroicon-o-list-bullet" class="size-5" aria-hidden="true" />
                        </div>
                        <h3 id="catalog-import-steps-title" class="text-base font-bold">Как это работает</h3>
                    </div>

                    <ol class="mt-5 space-y-4">
                        @foreach ([
                            ['Выберите таблицу', 'CSV или XLSX размером до 50 МБ.'],
                            ['Проверьте настройки', 'Обычно достаточно значений по умолчанию.'],
                            ['Запустите и следите за прогрессом', 'Строки и изображения обрабатываются очередями.'],
                        ] as $index => [$step, $description])
                            <li class="flex gap-3">
                                <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary-500 text-xs font-bold text-gray-950">{{ $index + 1 }}</span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-semibold">{{ $step }}</span>
                                    <span class="mt-0.5 block text-xs leading-5 text-gray-300">{{ $description }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ol>
                </aside>
            </div>
        </x-filament::section>

        @if ($activeRun = $this->activeRun())
            @php($progress = $this->progress($activeRun))

            <x-filament::section>
                <div class="space-y-6" wire:key="active-import-{{ $activeRun->id }}">
                    <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
                        <div class="min-w-0">
                            <p class="text-lg font-bold text-gray-950 dark:text-white">Импорт #{{ $activeRun->id }}</p>
                            <p class="mt-1 break-all text-sm font-medium text-gray-700 dark:text-gray-200">{{ $activeRun->original_name }}</p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Источник: {{ $activeRun->type }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                            <x-filament::badge :color="$activeRun->status?->color() ?? 'gray'">{{ $progress->statusLabel }}</x-filament::badge>
                            <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">{{ $progress->stageLabel }}</span>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-primary-200 bg-primary-50/70 p-5 dark:border-primary-500/30 dark:bg-primary-500/10">
                        <div class="flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-gray-700 dark:text-gray-200">Общий прогресс</p>
                                <p class="mt-1 text-3xl font-bold tabular-nums text-gray-950 dark:text-white">{{ $progress->overallPercent }}%</p>
                            </div>
                            <div class="text-right text-xs leading-5 text-gray-600 dark:text-gray-300">
                                <p>{{ $progress->stageLabel }}</p>
                                <p>Последняя активность: {{ $activeRun->heartbeat_at?->format('d.m.Y H:i:s') ?? '—' }}</p>
                            </div>
                        </div>
                        <div class="mt-4 h-3 overflow-hidden rounded-full bg-white ring-1 ring-primary-200 dark:bg-white/10 dark:ring-primary-500/20" role="progressbar" aria-label="Общий прогресс импорта" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress->overallPercent }}">
                            <div class="h-full rounded-full bg-primary-600 transition-[width] duration-500 motion-reduce:transition-none" style="width: {{ $progress->overallPercent }}%"></div>
                        </div>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        @foreach ([['Обработка строк', $progress->rowsLabel, $progress->rowsPercent, 'heroicon-o-table-cells'], ['Обработка изображений', $progress->imagesLabel, $progress->imagesPercent, 'heroicon-o-photo']] as [$heading, $label, $percent, $icon])
                            <div class="rounded-2xl border border-gray-200 p-5 dark:border-white/10">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex items-center gap-3">
                                        <span class="flex size-10 items-center justify-center rounded-xl bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                            <x-filament::icon :icon="$icon" class="size-5" aria-hidden="true" />
                                        </span>
                                        <span class="font-semibold text-gray-950 dark:text-white">{{ $heading }}</span>
                                    </div>
                                    <span class="text-lg font-bold tabular-nums text-gray-950 dark:text-white">{{ $percent }}%</span>
                                </div>
                                <div class="mt-4 h-2.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10" role="progressbar" aria-label="{{ $heading }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}">
                                    <div class="h-full rounded-full bg-primary-500 transition-[width] duration-500 motion-reduce:transition-none" style="width: {{ $percent }}%"></div>
                                </div>
                                <p class="mt-3 text-sm font-medium text-gray-700 dark:text-gray-200">{{ $label }}</p>
                            </div>
                        @endforeach
                    </div>

                    <dl class="grid gap-3 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-5">
                        @foreach ([
                            ['Создано товаров', $activeRun->created_products, 'heroicon-o-plus-circle'],
                            ['Обновлено товаров', $activeRun->updated_products, 'heroicon-o-arrow-path'],
                            ['Архивировано', $activeRun->archived_products, 'heroicon-o-archive-box'],
                            ['Предупреждения', $activeRun->warnings_count, 'heroicon-o-exclamation-triangle'],
                            ['Ошибки', $activeRun->errors_count, 'heroicon-o-x-circle'],
                        ] as [$label, $value, $icon])
                            <div class="rounded-xl border border-gray-200 bg-gray-50/70 p-4 dark:border-white/10 dark:bg-white/5">
                                <dt class="flex items-center gap-2 text-xs font-medium text-gray-600 dark:text-gray-300">
                                    <x-filament::icon :icon="$icon" class="size-4" aria-hidden="true" />
                                    {{ $label }}
                                </dt>
                                <dd class="mt-2 text-2xl font-bold tabular-nums text-gray-950 dark:text-white">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    @if ($activeRun->last_error)
                        <div class="flex items-start gap-3 rounded-xl border border-danger-200 bg-danger-50 p-4 text-sm text-danger-800 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-200" role="alert">
                            <x-filament::icon icon="heroicon-o-x-circle" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                            <div class="min-w-0">
                                <p class="font-semibold">Последняя ошибка</p>
                                <p class="mt-1 break-words">{{ $activeRun->last_error }}</p>
                            </div>
                        </div>
                    @endif

                    @if (($problems = $this->latestProblems($activeRun, 3))->isNotEmpty())
                        <div class="rounded-2xl border border-warning-200 bg-warning-50/60 p-4 dark:border-warning-500/20 dark:bg-warning-500/10">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold text-warning-900 dark:text-warning-200">Последние предупреждения и ошибки</p>
                                    <p class="mt-1 text-xs text-warning-800/80 dark:text-warning-300/80">Показаны три последние записи, требующие внимания.</p>
                                </div>
                                <button type="button" x-on:click="$dispatch('open-modal', { id: 'active-import-logs-{{ $activeRun->id }}' })" class="min-h-11 rounded-lg px-3 py-2 text-sm font-semibold text-warning-900 underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-warning-700 dark:text-warning-200">
                                    Открыть все логи
                                </button>
                            </div>
                            <div class="mt-3 space-y-2">
                                @foreach ($problems as $problem)
                                    @php($problemLevel = $problem->level?->value ?? (string) $problem->level)
                                    <article class="flex min-w-0 items-start gap-3 rounded-xl bg-white/80 p-3 dark:bg-black/15">
                                        <x-filament::icon :icon="$problemLevel === 'error' ? 'heroicon-m-x-circle' : 'heroicon-m-exclamation-triangle'" class="mt-0.5 size-5 shrink-0 {{ $problemLevel === 'error' ? 'text-danger-600 dark:text-danger-300' : 'text-warning-700 dark:text-warning-300' }}" aria-hidden="true" />
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                                <span class="font-semibold text-gray-900 dark:text-white">{{ $problemLevel === 'error' ? 'Ошибка' : 'Предупреждение' }}</span>
                                                <time class="text-gray-500 dark:text-gray-400">{{ $problem->created_at?->format('d.m.Y H:i:s') }}</time>
                                            </div>
                                            <p class="mt-1 break-words text-sm text-gray-700 dark:text-gray-200">{{ $problem->message }}</p>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="grid gap-4 border-t border-gray-200 pt-5 dark:border-white/10 lg:grid-cols-[1fr_auto] lg:items-center">
                        <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                            @if ($activeRun->status === \App\Enums\ImportRunStatus::Ready)
                                <x-filament::button icon="heroicon-o-play" wire:click="start({{ $activeRun->id }})" wire:loading.attr="disabled" wire:target="start,pause,resume,cancel" class="w-full justify-center sm:w-auto">Старт</x-filament::button>
                            @elseif ($activeRun->status?->isActive() && $activeRun->status !== \App\Enums\ImportRunStatus::Paused)
                                <x-filament::button color="warning" icon="heroicon-o-pause" wire:click="pause({{ $activeRun->id }})" wire:loading.attr="disabled" wire:target="start,pause,resume,cancel" class="w-full justify-center sm:w-auto">Пауза</x-filament::button>
                            @elseif ($activeRun->status === \App\Enums\ImportRunStatus::Paused)
                                <x-filament::button icon="heroicon-o-arrow-path" wire:click="resume({{ $activeRun->id }})" wire:loading.attr="disabled" wire:target="start,pause,resume,cancel" class="w-full justify-center sm:w-auto">Продолжить</x-filament::button>
                            @endif

                            <x-filament::modal id="active-import-logs-{{ $activeRun->id }}" width="5xl" slide-over>
                                <x-slot name="trigger">
                                    <x-filament::button color="gray" icon="heroicon-o-list-bullet" class="w-full justify-center sm:w-auto">Открыть логи</x-filament::button>
                                </x-slot>
                                <x-slot name="heading">Логи импорта #{{ $activeRun->id }}</x-slot>
                                @include('filament.pages.catalog-import-logs', ['run' => $activeRun, 'logs' => $this->latestLogs($activeRun, 200)])
                            </x-filament::modal>

                            <x-filament::button color="gray" icon="heroicon-o-document-text" wire:click="downloadReport({{ $activeRun->id }})" wire:loading.attr="disabled" wire:target="downloadReport" class="w-full justify-center sm:w-auto">Скачать отчёт</x-filament::button>
                        </div>

                        @if (! $activeRun->isTerminal())
                            <div class="border-t border-danger-200 pt-4 dark:border-danger-500/20 lg:border-s lg:border-t-0 lg:ps-4 lg:pt-0">
                                <x-filament::button color="danger" outlined icon="heroicon-o-x-mark" wire:click="cancel({{ $activeRun->id }})" wire:confirm="Отменить импорт? Уже внесённые изменения сохранятся." wire:loading.attr="disabled" wire:target="start,pause,resume,cancel" class="w-full justify-center sm:w-auto">Отменить импорт</x-filament::button>
                            </div>
                        @endif
                    </div>
                </div>
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">История импортов</x-slot>
            <x-slot name="description">Последние запуски, их результаты, отчёты и полные журналы.</x-slot>
            <div class="min-w-0">{{ $this->table }}</div>
        </x-filament::section>
    </div>
</x-filament-panels::page>

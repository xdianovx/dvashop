<x-filament-panels::page>
    <div class="catalog-prices-page" wire:poll.10s="refreshOperation">
        <x-filament::section heading="1. Настройка операции" description="Выберите область изменения. До подтверждения цены в каталоге не меняются.">
            <div class="cp-fields">
                {{ $this->form }}
                <div class="cp-toolbar">
                    <p class="cp-muted">Текущая базовая цена: <strong>{{ $currentBase === null ? 'не задана' : $currentBase . ' ₽' }}</strong></p>
                    <x-filament::badge :color="$calculationState === 'ready' ? 'success' : 'gray'">{{ ['idle' => 'Не рассчитано', 'ready' => 'Расчёт актуален', 'dirty' => 'Требуется перерасчёт', 'submitted' => 'Операция выполняется'][$calculationState] }}</x-filament::badge>
                </div>
                @if ($operationMode === 'adopt')
                    <p class="cp-note">Анализ предложит базу и покажет отклонения. Самая распространённая цена — только подсказка. В наследование принимаются совпадающие карточки без индивидуальных цен; суммы сохраняются.</p>
                @endif
                @if ($operationMode === 'adopt' && $preview === null)
                    @error('price')<p role="alert" class="cp-note">{{ $message }}</p>@enderror
                @endif
                <x-filament::button :disabled="$this->operationRunning" :wire:click="$operationMode === 'adopt' && $preview === null ? 'analyzeHistory' : 'previewPrices'">
                    {{ $operationMode === 'adopt' ? ($preview === null ? 'Проанализировать исторические цены' : 'Пересчитать принятие цен') : 'Рассчитать предварительный просмотр' }}
                </x-filament::button>
            </div>
        </x-filament::section>

        @if ($preview)
            @php $c = $preview['counts']; @endphp
            <x-filament::section heading="2. Анализ и предварительный просмотр">
                @if ($calculationState === 'dirty')
                    <p role="status" class="cp-note">Настройки или каталог изменились. Ниже показан предыдущий расчёт. Пересчитайте его перед подтверждением.</p>
                @endif
                <div class="cp-stats">
                    @foreach (['products' => 'Товаров в выборке', 'active' => 'Активных', 'archived' => 'Архивных', 'variants' => 'Вариантов', 'standard' => 'Без исключений', 'individual' => 'С индивидуальными ценами', 'historical' => 'С историческими ценами', 'ambiguous' => 'Неодинаковые варианты'] as $key => $label)
                        <div class="cp-stat"><span>{{ $label }}</span><strong>{{ number_format($c[$key], 0, ',', ' ') }}</strong></div>
                    @endforeach
                </div>
                @if ($preview['action'] === 'adopt')
                    <div class="cp-summary">
                        <p>Совпадают с базой целиком: <strong>{{ $c['matching'] }}</strong>. Отклонения: <strong>{{ $c['deviation'] }}</strong>. Недостаточно данных: <strong>{{ $c['insufficient'] }}</strong>.</p>
                        <p>Изменений сумм: 0. Прежние зачёркнутые цены и цены импорта сохраняются.</p>
                    </div>
                    <details class="cp-details"><summary>Распределение цен · наиболее распространённая: {{ $preview['suggested_price'] ?? 'нет данных' }} ₽</summary>
                        <div class="cp-distribution">@foreach ($preview['distribution'] as $value => $count)<p><strong>{{ $value }} ₽</strong><span>{{ $count }} товаров</span></p>@endforeach</div>
                    </details>
                @else
                    <div class="cp-bases">@foreach ($preview['types'] as $type)<p><span>{{ $type['full_title'] }}</span><strong>{{ $type['base_price'] ?? 'не задана' }} → {{ $preview['price'] }} ₽</strong></p>@endforeach</div>
                @endif
            </x-filament::section>

            <x-filament::section heading="3. Исключения" description="Общее решение действует на всю выборку, включая другие страницы. Поиск только меняет отображаемые строки.">
                <div class="cp-toolbar">
                    <div class="cp-actions">
                        <x-filament::button color="gray" wire:click="preserveAll" :disabled="$calculationState !== 'ready'">Сохранить все</x-filament::button>
                        @if ($operationMode === 'change')
                            <x-filament::button color="warning" wire:click="replaceAllExceptions" :disabled="$calculationState !== 'ready'">Заменить все</x-filament::button>
                        @else
                            <x-filament::button wire:click="adoptAllMatching" :disabled="$calculationState !== 'ready'">Принять все совпадающие</x-filament::button>
                        @endif
                    </div>
                    <div class="cp-search"><label for="pricing-search">Поиск по названию или ID</label><x-filament::input.wrapper><x-filament::input id="pricing-search" wire:model.live.debounce.400ms="search" placeholder="Название товара или ID" /></x-filament::input.wrapper></div>
                </div>
                <p class="cp-note">{{ $operationMode === 'change' ? ($replaceAll ? 'Общее решение: заменить исключения общей ценой.' : 'Общее решение: сохранить индивидуальные и исторические цены.') : ($adoptMatching ? 'Общее решение: принять совпадающие исторические цены, сохранить отклонения.' : 'Общее решение: сохранить суммы и режимы.') }} Массовая кнопка сбрасывает ранее выбранные индивидуальные решения.</p>
                @if ($operationMode === 'adopt')
                    <label class="cp-check"><x-filament::input.checkbox wire:model.live="classifyDeviations" :disabled="$calculationState !== 'ready'" /> Отдельно отметить неопределённые отклонения как индивидуальные, не меняя суммы</label>
                @else
                    <p class="cp-muted">«Заменить общей» выравнивает цену карточки и всех вариантов, включая индивидуальные. При сохранении исключений уже наследуемые цены продолжают обновляться.</p>
                @endif
                <div class="cp-table-scroll">
                    <table class="cp-table">
                        <thead><tr><th>Товар / автомобиль</th><th>Тип детали</th><th>Текущая цена</th><th>После операции</th><th>Причина</th><th>Решение</th></tr></thead>
                        <tbody>
                        @forelse ($exceptions as $product)
                            @php
                                $matches = $product->price !== null && $product->variants_count > 0 && $product->price === $preview['price']
                                    && (string) $product->variants_min_price == $product->price && (string) $product->variants_max_price == $product->price
                                    && $product->price_mode !== 'individual' && $product->individual_prices_count === 0;
                                $explicit = array_key_exists($product->id, $overrides) && $overrides[$product->id] !== '';
                                $decision = $explicit ? (bool) $overrides[$product->id] : ($operationMode === 'change' ? $replaceAll : ($matches ? $adoptMatching : $classifyDeviations));
                                $nextPrice = $operationMode === 'change' && ($decision || $product->price_mode === 'inherited') ? $preview['price'] : $product->price;
                            @endphp
                            <tr wire:key="price-row-{{ $product->id }}">
                                <td data-label="Товар / автомобиль"><a class="cp-product" href="{{ \App\Filament\Resources\Products\ProductResource::getUrl('edit', ['record' => $product]) }}">{{ $product->title }}</a><span class="cp-muted">#{{ $product->id }}</span>
                                    @foreach ($product->fitments as $fitment)<span class="cp-muted">{{ $fitment->generation?->model?->make?->title }} {{ $fitment->generation?->model?->title }} {{ $fitment->generation?->title }} {{ $fitment->generation?->body }}</span>@endforeach
                                </td>
                                <td data-label="Тип детали">{{ $product->partType?->full_title }}</td>
                                <td data-label="Текущая цена"><strong>{{ $product->price ?? 'не задана' }} ₽</strong><span class="cp-muted">Варианты: {{ $product->variants_min_price ?? 'нет' }} — {{ $product->variants_max_price ?? 'нет' }} ₽</span></td>
                                <td data-label="После операции"><strong>{{ $nextPrice ?? 'не задана' }} ₽</strong><span class="cp-muted">{{ $operationMode === 'adopt' ? 'Суммы без изменений' : ($decision ? 'Все варианты — общая цена' : 'Защищённые варианты сохраняются') }}</span></td>
                                <td data-label="Причина"><span>{{ \App\Services\Catalog\CatalogPriceOwnership::options()[$product->price_mode] }}</span><span class="cp-muted">Индивидуальных вариантов: {{ $product->individual_prices_count }}. Исторических: {{ $product->unknown_prices_count }}.</span>@if ($product->variants_min_price != $product->variants_max_price)<span>Цены вариантов различаются.</span>@endif</td>
                                <td data-label="Решение">
                                    <div wire:key="decision-{{ $product->id }}-{{ $operationMode }}-{{ (int) $decision }}-{{ (int) $explicit }}">
                                        <x-filament::input.wrapper>
                                            <x-filament::input.select :aria-label="'Решение для товара #' . $product->id" wire:change="setDecision({{ $product->id }}, $event.target.value)" :disabled="$calculationState !== 'ready'">
                                                <option value="">По умолчанию</option>
                                                <option value="0" @selected(! $decision)>Сохранить цену{{ $operationMode === 'adopt' ? ' и режим' : '' }}</option>
                                                <option value="1" @selected($decision)>{{ $operationMode === 'change' ? 'Заменить общей ценой' : ($matches ? 'Принять в наследование' : 'Исторические → индивидуальные') }}</option>
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                        <span class="cp-muted">{{ $explicit ? 'Индивидуальное решение' : 'Общее решение' }}</span>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6">{{ $search !== '' ? 'По вашему запросу ничего не найдено.' : 'Исключений в этой выборке нет.' }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <x-filament::pagination :paginator="$exceptions" />
            </x-filament::section>

            <x-filament::section heading="4. Подтверждение">
                <div class="cp-stats cp-stats-final">
                    @foreach ([$preview['action'] === 'adopt' ? 'classified_products' : 'products_to_update' => $preview['action'] === 'adopt' ? 'Карточек сменят режим' : 'Карточек получат новую цену', $preview['action'] === 'adopt' ? 'classified_variants' : 'variants_to_update' => 'Вариантов изменится', 'preserved' => 'Исключений сохраняется', 'forced' => 'Принудительно заменяется'] as $key => $label)
                        <div class="cp-stat"><span>{{ $label }}</span><strong>{{ number_format($c[$key], 0, ',', ' ') }}</strong></div>
                    @endforeach
                </div>
                <p class="cp-muted">Обработка выполняется пакетами. Можно закрыть страницу. Отмена после запуска остановит оставшиеся пакеты; уже применённые изменения сохранятся.</p>
                <div class="cp-actions">
                    @if ($calculationState === 'ready')
                        <x-filament::button wire:click="confirmPrices" wire:confirm="Применить показанные изменения ко всей выбранной области?">Подтвердить изменения</x-filament::button>
                    @else
                        <p role="status">Для подтверждения выполните новый расчёт.</p>
                    @endif
                    <x-filament::button color="gray" wire:click="discardPreview">Отказаться без изменений</x-filament::button>
                </div>
            </x-filament::section>
        @endif

        <x-filament::section heading="Журнал операций" description="Результаты сохраняются независимо от того, открыта ли эта страница.">
            @forelse ($operations as $operation)
                @php $r = json_decode($operation->report, true); @endphp
                @php $total = json_decode($operation->preview, true)['counts']['products']; @endphp
                <div class="cp-operation" wire:key="operation-{{ $operation->id }}">
                    <div class="cp-toolbar"><strong>#{{ $operation->id }} · {{ $operation->created_at }}</strong><x-filament::badge :color="$operation->status === 'done' ? 'success' : (in_array($operation->status, ['failed', 'paused']) ? 'danger' : 'gray')">{{ ['pending' => 'Ожидает выполнения', 'running' => 'Выполняется', 'done' => 'Завершена', 'paused' => 'Требует внимания', 'failed' => 'Завершена с ошибкой', 'canceled' => 'Остаток отменён'][$operation->status] }}</x-filament::badge></div>
                    <p>Обработано {{ $r['processed'] ?? 0 }} из {{ $total }}. Осталось {{ max(0, $total - ($r['processed'] ?? 0)) }}.</p>
                    <progress max="{{ max(1, $total) }}" value="{{ $r['processed'] ?? 0 }}" aria-label="Прогресс операции {{ $operation->id }}"></progress>
                    <p class="cp-muted">{{ $operation->action === 'adopt' ? 'Сменили режим карточек' : 'Изменено карточек' }}: {{ $r['products'] }}; вариантов: {{ $r['variants'] }}. Ошибок пакетов: {{ $r['errors'] }}. Администратор #{{ $operation->user_id }}.</p>
                    @if ($operation->error)<p role="alert" class="cp-note">{{ $operation->error }} Зафиксированные пакеты сохранены.</p>@endif
                    <div class="cp-actions">
                        <x-filament::button color="gray" wire:click="showOperation({{ $operation->id }})">Аудит изменений</x-filament::button>
                        @if (in_array($operation->status, ['pending', 'running', 'paused', 'failed']))
                            <x-filament::button wire:click="resumePrices({{ $operation->id }})">Продолжить обработку</x-filament::button>
                            <x-filament::button color="danger" wire:click="cancelPrices({{ $operation->id }})" wire:confirm="Отменить оставшиеся пакеты? Уже применённые изменения сохранятся.">Отменить остаток</x-filament::button>
                        @endif
                    </div>
                </div>
            @empty
                <p class="cp-muted">Подтверждённых операций пока нет.</p>
            @endforelse
            @if ($operationId && $audit->total())
                <details class="cp-details"><summary>Изменённые карточки операции #{{ $operationId }} · {{ $audit->total() }}</summary>
                    @foreach ($audit as $entry)
                        @php $before = json_decode($entry->previous_prices, true); @endphp
                        <details class="cp-details"><summary>#{{ $entry->product_id }} {{ $entry->title }} · прежняя цена {{ $before['price'] ?? 'не задана' }} ₽</summary>
                            @foreach ($before['variants'] ?? [] as $variant)<p>Вариант #{{ $variant['id'] }}: {{ $variant['price'] ?? 'не задана' }} ₽; {{ \App\Services\Catalog\CatalogPriceOwnership::options()[$variant['price_mode']] }}.</p>@endforeach
                        </details>
                    @endforeach
                    <x-filament::pagination :paginator="$audit" />
                </details>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>

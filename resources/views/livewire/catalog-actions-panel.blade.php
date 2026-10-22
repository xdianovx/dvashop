<div class="catalog-prices-page" wire:poll.10s>
    <x-filament::section heading="Массовое управление товарами" description="Выберите действие и товары. Сначала расчёт, затем подтверждение. Импорт Excel и CSV выполняется отдельно.">
        <div class="cp-stats">
            <label>Действие
                <x-filament::input.wrapper><x-filament::input.select wire:model.live="parameters.kind">
                    <option value="status">Изменить статус товаров</option>
                    <option value="price">Изменить цены</option>
                </x-filament::input.select></x-filament::input.wrapper>
            </label>
            <label>Тип детали
                <x-filament::input.wrapper><x-filament::input.select wire:model.live="parameters.type_id">
                    <option value="">Выберите тип</option>
                    @foreach($types as $id => $title)<option value="{{ $id }}">{{ $title }}</option>@endforeach
                </x-filament::input.select></x-filament::input.wrapper>
            </label>
            <label>Текущий статус
                <x-filament::input.wrapper><x-filament::input.select wire:model.live="parameters.source_status">
                    <option value="all">Все</option>
                    @foreach(\App\Enums\ProductStatus::cases() as $status)
                        <option value="{{ $status->value }}">{{ ['draft'=>'Черновик','active'=>'Активен','archived'=>'Архив'][$status->value] }}</option>
                    @endforeach
                </x-filament::input.select></x-filament::input.wrapper>
            </label>
            @if($parameters['kind'] === 'status')
                <label class="cp-check"><x-filament::input.checkbox wire:model.live="parameters.descendants" /> Включить подтипы</label>
            @endif
        </div>
        <label>Товары (необязательно: ID или часть названия; пусто — весь выбранный тип)
            <x-filament::input.wrapper><x-filament::input wire:model.live.debounce.400ms="parameters.search" placeholder="Найти нужные товары" /></x-filament::input.wrapper>
        </label>
        @if($parameters['kind'] === 'status')
            <label>Новый статус
                <x-filament::input.wrapper><x-filament::input.select wire:model.live="parameters.target_status">
                    <option value="active">Активен</option><option value="draft">Черновик</option><option value="archived">Архив</option>
                </x-filament::input.select></x-filament::input.wrapper>
            </label>
            <p class="cp-note">Отсутствующий в Excel товар нельзя активировать вручную. Изменение статусов не меняет цены.</p>
        @else
            <label>Конфигурация
                <x-filament::input.wrapper><x-filament::input.select wire:model.live="parameters.configuration_id">
                    <option value="">Выберите комбинацию</option>
                    @foreach($configurations as $config)
                        <option value="{{ $config->id }}">{{ $config->label() }} (сейчас в CSV: {{ number_format((float) $config->price, 2, ',', ' ') }} ₽)</option>
                    @endforeach
                </x-filament::input.select></x-filament::input.wrapper>
            </label>
            <label>Новая цена, ₽
                <x-filament::input.wrapper><x-filament::input type="text" inputmode="decimal" wire:model.live.debounce.400ms="parameters.price" placeholder="Например, 2300" /></x-filament::input.wrapper>
            </label>
            <p class="cp-note">Новая цена применяется только к выбранной конфигурации у найденных товаров. Следующий импорт вернёт цены из исходного CSV. Сам CSV не меняется.</p>
        @endif
        @foreach($errors->all() as $error)<p role="alert" class="cp-note">{{ $error }}</p>@endforeach
        <x-filament::button wire:click="calculate">Рассчитать изменения</x-filament::button>
    </x-filament::section>

    @if($preview)
        <x-filament::section heading="Предпросмотр">
            <div class="cp-stats">
                @foreach(['products'=>'В выборке', 'active'=>'Активных', 'archived'=>'Архивных', 'draft'=>'Черновиков', 'changed'=>'Изменится', 'same'=>'Без изменений', 'protected'=>'Пропущено', 'prices_to_change'=>'Цен вариантов изменится', 'product_prices_to_change'=>'Базовых цен изменится'] as $key => $title)
                    <div class="cp-stat"><span>{{ $title }}</span><strong>{{ $preview['counts'][$key] }}</strong></div>
                @endforeach
            </div>
            <div class="cp-table-scroll"><table class="cp-table"><thead><tr><th>Товар</th><th>Статус</th><th>Причина пропуска</th></tr></thead><tbody>
                @forelse($products as $product)
                    <tr wire:key="action-product-{{ $product->id }}">
                        <td data-label="Товар">#{{ $product->id }} {{ $product->title }}</td>
                        <td data-label="Статус">{{ ['draft'=>'Черновик','active'=>'Активен','archived'=>'Архив'][$product->status->value] }}</td>
                        <td data-label="Причина пропуска">{{ app(\App\Services\Catalog\CatalogActions::class)->reason($product, $preview['parameters']) ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">Товары не найдены.</td></tr>
                @endforelse
            </tbody></table></div>
            <x-filament::pagination :paginator="$products" />
            <x-filament::button wire:click="confirm" wire:confirm="Подтвердить изменение всей показанной выборки?">Подтвердить изменения</x-filament::button>
        </x-filament::section>
    @endif

    <x-filament::section heading="Журнал операций">
        @forelse($operations as $op)
            @php $report = json_decode($op->report, true); $scope = json_decode($op->parameters, true); @endphp
            <div class="cp-operation">
                <strong>#{{ $op->id }} · {{ $op->created_at }} · Администратор #{{ $op->user_id }}</strong>
                <span>{{ ['pending'=>'Ожидает','running'=>'Выполняется','paused'=>'Приостановлена','done'=>'Завершена','canceled'=>'Остаток отменён'][$op->status] ?? $op->status }} — {{ $report['processed'] ?? 0 }} / {{ $report['total'] ?? 0 }}. Изменено: {{ $report['changed'] ?? 0 }}. Пропущено: {{ $report['protected'] ?? 0 }}.</span>
                <span>{{ $op->published_at ? 'Поиск и фид обновлены' : 'Обновление поиска и фида ожидает завершения' }}</span>
                @if($op->error)<p role="alert">{{ $op->error }}</p>@endif
                <details><summary>Параметры</summary><p class="cp-note">
                    {{ ['status'=>'Статус','price'=>'Цена конфигурации','configurations'=>'Синхронизация с CSV','configuration_price'=>'Старая операция цены'][$scope['kind'] ?? ''] ?? 'Операция' }}.
                    Тип: {{ $types[$scope['type_id'] ?? 0] ?? '#'.($scope['type_id'] ?? '?') }}.
                    @if(($scope['kind'] ?? '') === 'status') Новый статус: {{ ['draft'=>'Черновик','active'=>'Активен','archived'=>'Архив'][$scope['target_status']] ?? '?' }}. @endif
                    @if(in_array(($scope['kind'] ?? ''), ['price','configuration_price'], true)) Конфигурация #{{ $scope['configuration_id'] ?? '?' }}: {{ $scope['price'] ?? '?' }} ₽. @endif
                </p></details>
                <div class="cp-actions">
                    @if($op->status === 'paused')<x-filament::button wire:click="resume({{ $op->id }})">Продолжить</x-filament::button>@endif
                    @if(in_array($op->status, ['pending','running','paused']))
                        <x-filament::button color="danger" wire:click="cancel({{ $op->id }})" wire:confirm="Остановить ещё не обработанные товары?">Отменить остаток</x-filament::button>
                    @endif
                </div>
            </div>
        @empty
            <p>Массовых операций пока не было.</p>
        @endforelse
    </x-filament::section>
</div>

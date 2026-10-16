<div class="catalog-prices-page" wire:poll.10s>
    <x-filament::section heading="Статусы и конфигурации товаров" description="Предпросмотр не изменяет каталог. Применение запускается только после подтверждения.">
        <div class="cp-stats">
            <label>Действие<x-filament::input.wrapper><x-filament::input.select wire:model.live="parameters.kind">
                <option value="status">Статусы товаров</option><option value="configurations">Применить конфигурации</option><option value="configuration_price">Цена конфигурации</option>
            </x-filament::input.select></x-filament::input.wrapper></label>
            <label>Тип детали<x-filament::input.wrapper><x-filament::input.select wire:model.live="parameters.type_id"><option value="">Выберите тип</option>@foreach($types as $id => $title)<option value="{{ $id }}">{{ $title }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
            <label>Текущий статус<x-filament::input.wrapper><x-filament::input.select wire:model.live="parameters.source_status"><option value="all">Все</option>@foreach(\App\Enums\ProductStatus::cases() as $status)<option value="{{ $status->value }}">{{ ['draft'=>'Черновик','active'=>'Активен','archived'=>'Архив'][$status->value] }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
            <label class="cp-check"><x-filament::input.checkbox wire:model.live="parameters.descendants" /> Включить подтипы</label>
        </div>
        @if($parameters['kind'] === 'status')
            <label>Целевой статус<x-filament::input.wrapper><x-filament::input.select wire:model.live="parameters.target_status"><option value="active">Активен</option><option value="draft">Черновик</option><option value="archived">Архив</option></x-filament::input.select></x-filament::input.wrapper></label>
            <p class="cp-note">Ручной архив и черновик сохраняются после следующих Excel-импортов. Обычная активация снимает ручное скрытие, но пропускает отсутствующие в Excel товары. Цены и варианты не меняются.</p>
            @if($parameters['target_status'] === 'active')<label class="cp-check"><x-filament::input.checkbox wire:model.live="parameters.force" /> Явно разрешаю публикацию вопреки отсутствию в Excel. Решение сохраняется и записывается в аудит.</label>@endif
        @elseif($parameters['kind'] === 'configuration_price')
            <label>Конфигурация<x-filament::input.wrapper><x-filament::input.select wire:model.live="parameters.configuration_id"><option value="">Выберите комбинацию</option>@foreach($configurations as $row)<option value="{{ $row->id }}">{{ $row->label() }} — {{ $row->price }} ₽</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
            <label>Новая цена, ₽<x-filament::input.wrapper><x-filament::input wire:model.blur="parameters.price" inputmode="decimal" /></x-filament::input.wrapper></label>
            <p class="cp-note">Обновляется одна комбинация выбранного типа. Индивидуальные и исторические цены вариантов сохраняются. Остальные комбинации не изменяются.</p>
        @else
            <p class="cp-note">Совпадающие варианты сохраняют ID; индивидуальные и исторические цены защищены. Общие цены берутся из матрицы. Устаревшие варианты останутся в базе, но будут недоступны для покупки. Недостающие создаются по ценовой матрице. Пользовательские шаблоны защищены.</p>
        @endif
        @foreach($errors->all() as $error)<p role="alert" class="cp-note">{{ $error }}</p>@endforeach
        <x-filament::button wire:click="calculate">Рассчитать предпросмотр</x-filament::button>
    </x-filament::section>
    @if($preview)
        <x-filament::section heading="Предварительный расчёт">
            <div class="cp-stats">@foreach(['products'=>'Найдено','active'=>'Активных','archived'=>'Архивных','draft'=>'Черновиков','changed'=>'Изменится','same'=>'Без изменения','protected'=>'Защищено / пропущено','variants_to_update'=>'Вариантов сменят цену','variants_to_create'=>'Вариантов создаётся','variants_to_retire'=>'Вариантов выводится из продажи'] as $key=>$title)<div class="cp-stat"><span>{{ $title }}</span><strong>{{ $preview['counts'][$key] }}</strong></div>@endforeach</div>
            <label>Поиск<x-filament::input.wrapper><x-filament::input wire:model.live.debounce.400ms="search" /></x-filament::input.wrapper></label>
            <div class="cp-table-scroll"><table class="cp-table"><thead><tr><th>Товар</th><th>Статус</th><th>Причина пропуска</th></tr></thead><tbody>@foreach($products as $product)<tr wire:key="action-product-{{ $product->id }}"><td data-label="Товар">#{{ $product->id }} {{ $product->title }}</td><td data-label="Статус">{{ ['draft'=>'Черновик','active'=>'Активен','archived'=>'Архив'][$product->status->value] }}</td><td data-label="Причина пропуска">{{ app(\App\Services\Catalog\CatalogActions::class)->reason($product, $preview['parameters']) ?? '—' }}</td></tr>@endforeach</tbody></table></div>
            <x-filament::pagination :paginator="$products" />
            <p class="cp-note">Активные товары доступны на витрине при соблюдении остальных правил доступности. Архив и черновик скрывают карточку из продажи и поиска. Обработка пакетная; отмена остановит только оставшиеся пакеты.</p>
            <x-filament::button wire:click="confirm" wire:confirm="Применить выбранное действие ко всей выборке с учётом показанных исключений?">Подтвердить операцию</x-filament::button>
        </x-filament::section>
    @endif
    <x-filament::section heading="Журнал массовых действий">
        @foreach($operations as $op)
            @php $report = json_decode($op->report, true); $scope = json_decode($op->parameters, true); @endphp
            <div class="cp-operation"><strong>#{{ $op->id }} · {{ $op->created_at }} · Администратор #{{ $op->user_id }}</strong>
                <span>{{ ['pending'=>'Ожидает','running'=>'Выполняется','paused'=>'Приостановлена','done'=>'Завершена','canceled'=>'Остаток отменён'][$op->status] }} — {{ $report['processed'] }} / {{ $report['total'] }}. Изменено: {{ $report['changed'] }}. Защищено: {{ $report['protected'] }}.</span>
                <span>{{ $op->published_at ? 'Обновление поиска и фида подтверждено' : 'Обновление поиска и фида ожидает завершения' }}</span>
                @if($op->error)<p role="alert">{{ $op->error }}</p>@endif
                <details><summary>Параметры операции</summary><p class="cp-note">{{ ['status'=>'Изменение статуса','configurations'=>'Применение конфигураций','configuration_price'=>'Цена конфигурации'][$scope['kind']] }}. Тип: {{ $types[$scope['type_id']] ?? '#'.$scope['type_id'] }}{{ $scope['descendants'] ? ', включая подтипы' : '' }}.
                    @if($scope['kind'] === 'status') Новый статус: {{ ['draft'=>'Черновик','active'=>'Активен','archived'=>'Архив'][$scope['target_status']] }}. {{ $scope['force'] ? 'Разрешена публикация отсутствующих в Excel товаров.' : '' }} @endif
                    @if($scope['kind'] === 'configuration_price') Комбинация #{{ $scope['configuration_id'] }}: {{ $scope['price'] }} ₽. @endif
                </p></details>
                <div class="cp-actions">@if($op->status === 'paused')<x-filament::button wire:click="resume({{ $op->id }})">Продолжить</x-filament::button>@endif
                @if(in_array($op->status, ['pending','running','paused']))<x-filament::button color="danger" wire:click="cancel({{ $op->id }})" wire:confirm="Остановить остаток операции? Уже обработанные товары сохранят изменения.">Отменить остаток</x-filament::button>@endif</div>
            </div>
        @endforeach
    </x-filament::section>
</div>

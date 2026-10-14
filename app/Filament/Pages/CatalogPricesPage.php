<?php

namespace App\Filament\Pages;

use App\Models\PartType;
use App\Services\Catalog\CatalogBulkPrices;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CatalogPricesPage extends Page
{
    use WithPagination;

    protected string $view = 'filament.pages.catalog-prices';

    protected static ?string $slug = 'catalog/prices';

    public ?int $typeId = null;

    public bool $descendants = true;

    public string $price = '';

    public string $search = '';

    public bool $replaceAll = false;

    public string $operationMode = 'change';

    public bool $adoptMatching = true;

    #[Locked]
    public string $calculationState = 'idle';

    #[Locked]
    public ?string $observedStatus = null;

    public bool $classifyDeviations = false;

    public array $overrides = [];

    #[Locked]
    public ?array $preview = null;

    #[Locked]
    public ?int $operationId = null;

    public static function getNavigationGroup(): ?string
    {
        return 'Каталог';
    }

    public static function getNavigationLabel(): string
    {
        return 'Массовое изменение цен';
    }

    public function getTitle(): string
    {
        return 'Массовое изменение цен';
    }

    public static function canAccess(): bool
    {
        try {
            app(CatalogBulkPrices::class)->authorize(Filament::auth()->user());

            return true;
        } catch (HttpException $e) {
            return false;
        }
    }

    public function mount(): void
    {
        $this->form->fill(['operationMode' => 'change', 'typeId' => null, 'descendants' => true, 'price' => '']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(['default' => 1, 'md' => 2])->components([
            Select::make('operationMode')->label('Тип операции')->native(false)->options([
                'change' => 'Изменение общей цены', 'adopt' => 'Принятие исторических цен',
            ])->required()->live()->disabled(fn () => $this->operationRunning),
            Select::make('typeId')->label('Тип детали')->native(false)->searchable()->options(fn () => PartType::query()->orderBy('full_title')->pluck('full_title', 'id'))
                ->required()->validationAttribute('тип детали')->live()->disabled(fn () => $this->operationRunning),
            Toggle::make('descendants')->label('Включить вложенные типы')->live()->visible(fn () => $this->operationMode === 'change')->disabled(fn () => $this->operationRunning),
            TextInput::make('price')->label(fn () => $this->operationMode === 'adopt' ? 'Базовая цена для принятия, ₽' : 'Новая общая цена, ₽')
                ->helperText(fn () => $this->operationMode === 'adopt' ? 'Предложение для проверки. Принятие не меняет суммы товаров и вариантов.' : 'Исключения сохраняются, пока вы явно не выберете замену.')
                ->inputMode('decimal')->required()->validationAttribute('цена')->live(onBlur: true)
                ->visible(fn () => $this->operationMode === 'change' || $this->preview !== null)
                ->disabled(fn () => $this->operationRunning),
        ]);
    }

    #[Computed]
    public function operationRunning(): bool
    {
        return $this->operationId !== null && DB::table('catalog_price_operations')->where('id', $this->operationId)->whereIn('status', ['pending', 'running', 'paused'])->exists();
    }

    public function updated(string $name): void
    {
        if ($name === 'operationMode') {
            $this->descendants = $this->operationMode === 'change';
            $this->preview = null;
            $this->overrides = [];
            $this->replaceAll = false;
            $this->classifyDeviations = false;
            $this->adoptMatching = true;
            $this->calculationState = 'idle';
            $this->resetValidation();
        } elseif (in_array($name, ['typeId', 'descendants', 'price'], true)) {
            $this->calculationState = $this->preview === null ? 'idle' : 'dirty';
            if ($name === 'typeId') {
                if ($this->operationMode === 'adopt') {
                    $this->preview = null;
                    $this->calculationState = 'idle';
                }
                $this->overrides = [];
                $this->resetPage();
            }
            $this->resetValidation();
        } elseif (str_starts_with($name, 'overrides.') || in_array($name, ['replaceAll', 'classifyDeviations', 'adoptMatching'], true)) {
            $this->recalculateDecisions();
        }
    }

    private function calculate(): array
    {
        return app(CatalogBulkPrices::class)->preview($this->typeId, $this->descendants, $this->price, $this->replaceAll, $this->overrides,
            $this->operationMode, $this->classifyDeviations, adoptMatching: $this->adoptMatching);
    }

    private function recalculateDecisions(): void
    {
        if ($this->preview === null || $this->calculationState !== 'ready') {
            return;
        }
        $next = $this->calculate();
        if (! hash_equals($this->preview['source_hash'], $next['source_hash'])) {
            $this->calculationState = 'dirty';
            $this->addError('price', 'Каталог изменён другим пользователем. Выполните новый расчёт.');

            return;
        }
        $next['request_id'] = $this->preview['request_id'];
        $this->preview = $next;
    }

    public function setDecision(int $id, string $value): void
    {
        $service = app(CatalogBulkPrices::class);
        $service->authorize(Filament::auth()->user());
        abort_unless($this->preview !== null && in_array($value, ['', '0', '1'], true)
            && $service->exceptions($this->preview['type_ids'])->whereKey($id)->exists(), 422);
        if ($value === '') {
            unset($this->overrides[$id]);
        } else {
            $this->overrides[$id] = $value === '1';
        }
        $this->recalculateDecisions();
    }

    public function adoptAllMatching(): void
    {
        app(CatalogBulkPrices::class)->authorize(Filament::auth()->user());
        $this->adoptMatching = true;
        $this->classifyDeviations = false;
        $this->overrides = [];
        $this->recalculateDecisions();
    }

    public function refreshOperation(): void
    {
        if ($this->operationId === null) {
            return;
        }
        $status = DB::table('catalog_price_operations')->where('id', $this->operationId)->value('status');
        if ($status !== $this->observedStatus) {
            $this->observedStatus = $status;
            unset($this->operationRunning);
            if (in_array($status, ['done', 'failed', 'canceled'], true)) {
                $this->preview = null;
                $this->calculationState = 'idle';
                if ($status === 'done') {
                    Notification::make()->success()->title('Операция завершена')->body('Цены и режимы обновлены. Для следующей операции выполните новый расчёт.')->send();
                }
            }
        }
    }

    public function analyzeHistory(): void
    {
        $service = app(CatalogBulkPrices::class);
        $service->authorize(Filament::auth()->user());
        $this->validate(['typeId' => 'required|integer|exists:part_types,id'], [], ['typeId' => 'тип детали']);
        $this->operationMode = 'adopt';
        $this->descendants = false;
        $this->replaceAll = false;
        $this->overrides = [];
        $base = PartType::query()->findOrFail($this->typeId)->base_price;
        $suggested = $service->products([$this->typeId])->whereNotNull('price')->select('price')
            ->selectRaw('count(*) as frequency')->groupBy('price')->orderByDesc('frequency')->orderBy('price')->first()?->price;
        $this->price = (string) ($base ?? $suggested ?? '');
        if ($this->price === '') {
            throw ValidationException::withMessages(['typeId' => 'Для этого типа нет цен для принятия. Сначала задайте базовую цену в режиме изменения общей цены.']);
        }
        $this->previewPrices();
    }

    public function previewPrices(): void
    {
        $service = app(CatalogBulkPrices::class);
        $service->authorize(Filament::auth()->user());
        $this->validate(['typeId' => 'required|integer|exists:part_types,id'], [], ['typeId' => 'тип детали']);
        $this->preview = $this->calculate();
        $this->preview['request_id'] = (string) Str::uuid();
        $this->calculationState = 'ready';
        $this->resetValidation();
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function preserveAll(): void
    {
        app(CatalogBulkPrices::class)->authorize(Filament::auth()->user());
        $this->replaceAll = false;
        $this->adoptMatching = false;
        $this->classifyDeviations = false;
        $this->overrides = [];
        $this->recalculateDecisions();
    }

    public function replaceAllExceptions(): void
    {
        app(CatalogBulkPrices::class)->authorize(Filament::auth()->user());
        abort_unless($this->operationMode === 'change', 422);
        $this->replaceAll = true;
        $this->overrides = [];
        $this->recalculateDecisions();
    }

    public function confirmPrices(): void
    {
        if ($this->preview === null && $this->operationId !== null) {
            return;
        }
        abort_unless($this->preview !== null, 422);
        if ($this->calculationState !== 'ready') {
            throw ValidationException::withMessages(['price' => 'Параметры изменены. Выполните новый расчёт.']);
        }
        if ($this->operationMode !== $this->preview['action'] || $this->classifyDeviations !== $this->preview['classify_deviations'] || $this->typeId !== $this->preview['type_id'] || $this->descendants !== $this->preview['descendants']
            || app(CatalogBulkPrices::class)->price($this->price) !== $this->preview['price']) {
            throw ValidationException::withMessages(['price' => 'Параметры изменены. Обновите предварительный просмотр.']);
        }
        $this->operationId = app(CatalogBulkPrices::class)->confirm(Filament::auth()->user(), $this->preview, $this->replaceAll, $this->overrides);
        $this->preview = null;
        $this->calculationState = 'submitted';
        unset($this->operationRunning);
        Notification::make()->success()->title('Операция поставлена в очередь')->body('Можно закрыть страницу: обработка продолжится.')->send();
    }

    public function resumePrices(int $id): void
    {
        app(CatalogBulkPrices::class)->authorize(Filament::auth()->user());
        app(CatalogBulkPrices::class)->resume(Filament::auth()->user(), $id);
        $this->operationId = $id;
    }

    public function cancelPrices(int $id): void
    {
        app(CatalogBulkPrices::class)->cancel(Filament::auth()->user(), $id);
    }

    public function discardPreview(): void
    {
        app(CatalogBulkPrices::class)->authorize(Filament::auth()->user());
        $this->preview = null;
        $this->overrides = [];
        $this->calculationState = 'idle';
        $this->resetValidation();
    }

    public function showOperation(int $id): void
    {
        app(CatalogBulkPrices::class)->authorize(Filament::auth()->user());
        $this->operationId = $id;
        $this->resetPage('auditPage');
    }

    protected function getViewData(): array
    {
        app(CatalogBulkPrices::class)->authorize(Filament::auth()->user());
        $query = app(CatalogBulkPrices::class)->exceptions($this->preview['type_ids'] ?? [])
            ->with(['partType', 'defaultVariant', 'fitments.generation.model.make'])
            ->withCount([
                'variants as individual_prices_count' => fn ($q) => $q->where('price_mode', 'individual'),
                'variants as unknown_prices_count' => fn ($q) => $q->where('price_mode', 'unknown'),
            ])
            ->withMin('variants', 'price')->withMax('variants', 'price')->withCount('variants');
        if (trim($this->search) !== '') {
            $query->where(fn ($q) => $q->where('title', 'like', '%'.$this->search.'%')->orWhere('id', $this->search));
        }

        return ['currentBase' => $this->typeId ? PartType::query()->whereKey($this->typeId)->value('base_price') : null, 'types' => PartType::query()->orderBy('full_title')->pluck('full_title', 'id'),
            'exceptions' => $query->orderBy('id')->paginate(20),
            'audit' => DB::table('catalog_price_overrides')->where('operation_id', $this->operationId)->orderBy('id')->paginate(20, ['*'], 'auditPage'),
            'operations' => DB::table('catalog_price_operations')->orderByDesc('id')->limit(10)->get(),
        ];
    }
}

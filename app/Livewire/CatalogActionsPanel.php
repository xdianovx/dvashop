<?php

namespace App\Livewire;

use App\Models\CatalogConfiguration;
use App\Models\PartType;
use App\Models\Product;
use App\Services\Catalog\CatalogActions;
use App\Services\Catalog\CatalogBulkPrices;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class CatalogActionsPanel extends Component
{
    use WithPagination;

    public array $parameters = [
        'kind' => 'status',
        'type_id' => '',
        'descendants' => false,
        'source_status' => 'all',
        'target_status' => 'archived',
        'force' => false,
        'configuration_id' => '',
        'price' => '',
        'search' => '',
    ];

    #[Locked]
    public ?array $preview = null;

    #[Locked]
    public ?int $operationId = null;

    public function mount(): void
    {
        app(CatalogBulkPrices::class)->authorize(Filament::auth()->user());
    }

    public function updatedParameters($value = null, $key = null): void
    {
        // A price always addresses one concrete part type and CSV combination.
        if (($this->parameters['kind'] ?? null) === 'price') {
            $this->parameters['descendants'] = false;
        }
        if ($key === 'type_id' || $key === 'kind') {
            $this->parameters['configuration_id'] = '';
        }
        $this->preview = null;
        $this->resetValidation();
        $this->resetPage();
    }

    public function calculate(): void
    {
        $this->preview = app(CatalogActions::class)->preview(Filament::auth()->user(), $this->parameters);
        $this->resetPage();
    }

    public function confirm(): void
    {
        abort_unless($this->preview !== null, 422);
        $this->operationId = app(CatalogActions::class)->confirm(Filament::auth()->user(), $this->preview);
        $this->preview = null;
    }

    public function resume(int $id): void
    {
        app(CatalogActions::class)->resume(Filament::auth()->user(), $id);
    }

    public function cancel(int $id): void
    {
        app(CatalogActions::class)->cancel(Filament::auth()->user(), $id);
    }

    public function render()
    {
        app(CatalogBulkPrices::class)->authorize(Filament::auth()->user());
        $query = $this->preview
            ? app(CatalogActions::class)->query($this->preview['parameters'])
            : Product::whereRaw('1 = 0');
        $products = $query->with('optionTemplate')->orderBy('id')->paginate(20);
        $typeId = (int) ($this->parameters['type_id'] ?? 0);
        $configurations = ($this->parameters['kind'] ?? 'status') === 'price' && $typeId > 0
            ? CatalogConfiguration::query()->where('part_type_id', $typeId)->where('is_active', true)
                ->orderBy('position')->orderBy('id')->get()
            : collect();

        return view('livewire.catalog-actions-panel', [
            'types' => PartType::orderBy('full_title')->pluck('full_title', 'id'),
            'configurations' => $configurations,
            'products' => $products,
            'operations' => DB::table('catalog_actions')->latest('id')->limit(10)->get(),
        ]);
    }
}

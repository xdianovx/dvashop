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

    public array $parameters = ['kind' => 'status', 'type_id' => '', 'descendants' => false, 'source_status' => 'all',
        'target_status' => 'archived', 'force' => false, 'configuration_id' => null, 'price' => null];

    public string $search = '';

    #[Locked]
    public ?array $preview = null;

    #[Locked]
    public ?int $operationId = null;

    public function updatedParameters(): void
    {
        $this->preview = null;
        $this->resetValidation();
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
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
        $query = $this->preview ? app(CatalogActions::class)->query($this->preview['parameters']) : Product::whereRaw('1 = 0');
        $products = $query->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q->where('title', 'like', '%'.$this->search.'%')->orWhere('id', $this->search)))
            ->with('optionTemplate')->orderBy('id')->paginate(20);

        return view('livewire.catalog-actions-panel', ['types' => PartType::orderBy('full_title')->pluck('full_title', 'id'),
            'configurations' => CatalogConfiguration::where('part_type_id', $this->parameters['type_id'] ?: 0)->orderBy('position')->get(),
            'products' => $products, 'operations' => DB::table('catalog_actions')->latest('id')->limit(10)->get()]);
    }
}

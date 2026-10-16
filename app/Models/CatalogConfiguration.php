<?php

namespace App\Models;

use App\Services\Catalog\CatalogConfigurationSource;
use App\Services\Catalog\CatalogPriceGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class CatalogConfiguration extends Model
{
    protected $guarded = [];

    public function save(array $options = []): bool
    {
        return DB::transaction(function () use ($options): bool {
            app(CatalogPriceGuard::class)->assertWritable();

            return parent::save($options);
        });
    }

    public function delete()
    {
        return DB::transaction(function () {
            app(CatalogPriceGuard::class)->assertWritable();

            return parent::delete();
        });
    }

    public function label(): string
    {
        return implode(' / ', array_map(fn ($group, $code) => array_search($code, CatalogConfigurationSource::VALUES[$group] ?? [], true) ?: $code, array_keys($this->options), array_values($this->options)));
    }

    public function snapshot(): array
    {
        $titles = ['profile' => 'Профиль', 'position' => 'Положение', 'material' => 'Материал', 'thickness' => 'Толщина металла'];
        $snapshot = [];
        foreach ($this->options as $group => $code) {
            $snapshot[$group] = ['group' => $titles[$group], 'value' => array_search($code, CatalogConfigurationSource::VALUES[$group], true)];
        }
        return $snapshot;
    }

    protected function casts(): array
    {
        return ['options' => 'array', 'price' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function partType(): BelongsTo
    {
        return $this->belongsTo(PartType::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ProductOptionTemplate::class, 'product_option_template_id');
    }
}

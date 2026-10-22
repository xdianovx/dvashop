<?php

namespace App\Services\Import;

use App\Models\ImportRun;
use App\Models\VehicleGeneration;
use App\Support\CatalogText;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Reviewed, deployable allowlist. Never reads diagnostic files from storage/. */
final class CatalogImportApprovedIdentities
{
    private ?array $entries = null;

    private array $vehicles = [];

    private ?bool $historicalCatalog = null;

    public function __construct(private readonly ?string $path = null) {}

    public function entry(string $key, ?ImportRun $run = null): ?array
    {
        $this->load();

        return $this->active($run) ? ($this->entries[$key] ?? null) : null;
    }

    public function vehicleEntry(array $row, ?ImportRun $run = null): ?array
    {
        $this->load();

        return $this->active($run) ? ($this->vehicles[$this->vehicleKey($this->incoming($row))] ?? null) : null;
    }

    /** Numeric IDs alone are not evidence that this database owns the old registry. */
    public function active(?ImportRun $run = null): bool
    {
        $this->load();
        $sealed = $run?->prepared_rows['full_scope']['identity_baseline']['approved_vw_active'] ?? null;
        if (is_bool($sealed)) {
            return $sealed;
        }

        return $this->historicalCatalog ??= DB::table('products')
            ->whereIn('import_key', array_column($this->entries, 'import_key'))->exists()
            || DB::table('vehicle_makes')->where('norm_key', 'volkswagen')->exists();
    }

    /** Fresh guards are shared by preview and the actual row/product processors. */
    public function validate(array $entry, array $row, string $path, string $source): array
    {
        $incoming = $this->incoming($row);
        $expected = $entry['vehicle']['incoming'];
        if ($source !== $entry['source'] || $path !== $entry['part_type_path']
            || array_map(self::canonical(...), $incoming) !== array_map(self::canonical(...), $expected)
            || CatalogImportIdentity::version($incoming['restyling']) !== '') {
            throw new RuntimeException('Утверждённое соответствие противоречит входным полям автомобиля, источнику или типу детали.');
        }

        $old = $entry['vehicle']['historical'];
        $current = DB::table('products as p')
            ->leftJoin('part_types as t', 't.id', '=', 'p.part_type_id')
            ->leftJoin('product_fitments as f', 'f.product_id', '=', 'p.id')
            ->leftJoin('vehicle_generations as g', 'g.id', '=', 'f.vehicle_generation_id')
            ->leftJoin('vehicle_models as m', 'm.id', '=', 'g.vehicle_model_id')
            ->leftJoin('vehicle_makes as b', 'b.id', '=', 'm.vehicle_make_id')
            ->where('p.id', $entry['product_id'])
            ->select(['p.id as product_id', 'p.import_key', 'p.import_source as product_source', 'p.part_type_id',
                'p.deleted_at as product_deleted', 't.full_slug as part_path', 'f.note', 'f.is_primary',
                'g.id', 'g.title', 'g.norm_key', 'g.years_label', 'g.body', 'g.platform', 'g.restyling', 'g.import_source',
                'g.deleted_at as generation_deleted', 'm.id as model_id', 'm.title as model', 'm.norm_key as model_key',
                'm.deleted_at as model_deleted', 'b.id as make_id', 'b.title as make', 'b.norm_key as make_key', 'b.deleted_at as make_deleted'])
            ->get();
        if ($current->count() !== 1) {
            throw new RuntimeException('Исторический товар отсутствует или его единственная связь автомобиля изменилась.');
        }
        $actual = (array) $current->first();
        if ($actual['product_deleted'] !== null || $actual['generation_deleted'] !== null
            || $actual['model_deleted'] !== null || $actual['make_deleted'] !== null
            || $actual['import_key'] !== $entry['import_key'] || $actual['product_source'] !== $source
            || (int) $actual['part_type_id'] !== $entry['part_type_id'] || $actual['part_path'] !== $path
            || $actual['note'] !== $entry['fitment']['note'] || (bool) $actual['is_primary'] !== $entry['fitment']['is_primary']) {
            throw new RuntimeException('Исторический ключ, источник, тип детали или связь товара изменились после утверждения.');
        }
        foreach ($old as $field => $value) {
            if (in_array($field, ['id', 'model_id', 'make_id'], true)) {
                $actual[$field] = (int) $actual[$field];
            }
            if ($actual[$field] !== $value) {
                throw new RuntimeException('Историческая идентичность автомобиля изменилась: '.$field.'.');
            }
        }

        $neighbors = VehicleGeneration::query()->where('vehicle_model_id', $old['model_id'])
            ->get(['id', 'years_label', 'body'])->filter(fn ($g) => self::canonical($g->years_label) === self::canonical($old['years_label'])
                && self::canonical($g->body) === self::canonical($old['body']));
        if ($neighbors->count() !== 1 || (int) $neighbors->first()->id !== $old['id']) {
            throw new RuntimeException('Появилась конкурирующая идентичность автомобиля с теми же годами и кузовом.');
        }
        $competitor = DB::table('products as p')->leftJoin('product_fitments as f', 'f.product_id', '=', 'p.id')
            ->where('p.id', '!=', $entry['product_id'])
            ->where(function ($q) use ($entry): void {
                $q->where('p.import_key', $entry['incoming_key'])
                    ->orWhere(fn ($q) => $q->where('f.vehicle_generation_id', $entry['vehicle_generation_id'])
                        ->where('p.part_type_id', $entry['part_type_id']));
            })->exists();
        if ($competitor) {
            throw new RuntimeException('Новый ключ занят или существует конкурирующий товар автомобиля и типа детали.');
        }

        return $entry;
    }

    private function load(): void
    {
        if ($this->entries !== null) {
            return;
        }
        $path = $this->path ?? resource_path('imports/approved-vw-identities.v1.json');
        if (! is_file($path)) {
            throw new RuntimeException('Отсутствует постоянный реестр утверждённых соответствий VW: '.$path);
        }
        $document = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $products = $document['products'] ?? [];
        if (($document['version'] ?? null) !== 1 || ($document['scope'] ?? null) !== 'vw_to_volkswagen_id3a'
            || count($products) !== ($document['approved_count'] ?? null)
            || ($this->path === null && count($products) !== 558)) {
            throw new RuntimeException('Неверная версия, область или количество утверждённых соответствий VW.');
        }
        $entries = $vehicles = $ids = [];
        foreach ($products as $entry) {
            $vehicle = $document['vehicles'][(string) ($entry['vehicle_generation_id'] ?? '')] ?? null;
            $key = $entry['incoming_key'] ?? '';
            if (! $vehicle || ($entry['source'] ?? null) !== 'catalog'
                || ($vehicle['incoming']['make'] ?? null) !== 'VW'
                || ($vehicle['historical']['make'] ?? null) !== 'Volkswagen'
                || ! str_starts_with($key, 'catalog:vw:')
                || ($entry['import_key'] ?? null) !== 'catalog:volkswagen:'.substr($key, strlen('catalog:vw:'))
                || isset($ids[$entry['product_id']]) || isset($entries[$key])) {
                throw new RuntimeException('Недопустимая или повторная пара в реестре VW; один старый ID нельзя использовать для двух новых товаров.');
            }
            $entry['vehicle'] = $vehicle;
            $vehicleKey = $this->vehicleKey($vehicle['incoming']);
            if (isset($vehicles[$vehicleKey]) && $vehicles[$vehicleKey]['vehicle'] !== $vehicle) {
                throw new RuntimeException('Одному входному автомобилю назначены разные исторические идентичности.');
            }
            $ids[$entry['product_id']] = true;
            $entries[$key] = $entry;
            $vehicles[$vehicleKey] ??= $entry;
        }
        $this->entries = $entries;
        $this->vehicles = $vehicles;
    }

    private function incoming(array $row): array
    {
        return ['make' => (string) $row[1], 'model' => (string) $row[2], 'generation' => (string) $row[3],
            'years' => (string) $row[4], 'body' => (string) $row[5],
            'platform' => (string) ($row['_platform'] ?? ''), 'restyling' => (string) ($row['_restyling'] ?? '')];
    }

    private function vehicleKey(array $incoming): string
    {
        return implode(':', [CatalogText::normKey($incoming['make'], 'make', 120),
            CatalogText::normKey($incoming['model'], 'model', 120),
            CatalogImportIdentity::structuralKey($incoming['generation'], $incoming['years'], $incoming['body'])]);
    }

    private static function canonical(mixed $value): string
    {
        $value = mb_strtolower(CatalogText::plain($value, 1000));
        $value = preg_replace('/\s*([\p{Pd}()\/])\s*/u', '$1', $value);

        return preg_replace('/\p{Pd}/u', '-', $value);
    }
}

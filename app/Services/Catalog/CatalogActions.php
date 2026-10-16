<?php

namespace App\Services\Catalog;

use App\Jobs\ApplyCatalogAction;
use App\Models\CatalogConfiguration;
use App\Models\Product;
use App\Models\User;
use App\Services\Feeds\YandexFeedInvalidator;
use App\Services\Search\CatalogSearchDocument;
use App\Services\Search\CatalogSearchIndexer;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Durable reservation, bounded batches and acknowledged publication, shared by administrative actions. */
final class CatalogActions
{
    public const BATCH = 100;

    public function parameters(array $input): array
    {
        $p = Validator::make($input, [
            'kind' => 'required|in:status,configurations,configuration_price', 'type_id' => 'required|integer|exists:part_types,id',
            'descendants' => 'required|boolean', 'source_status' => 'required|in:all,draft,active,archived',
            'target_status' => 'required_if:kind,status|in:draft,active,archived', 'force' => 'required|boolean',
            'configuration_id' => 'required_if:kind,configuration_price|nullable|integer|exists:catalog_configurations,id',
            'price' => 'required_if:kind,configuration_price|nullable',
        ])->validate();
        $p['type_id'] = (int) $p['type_id'];
        $p['descendants'] = (bool) $p['descendants'];
        $p['force'] = (bool) $p['force'];
        $p['type_ids'] = app(CatalogBulkPrices::class)->scope($p['type_id'], $p['descendants']);
        if ($p['kind'] === 'configuration_price') {
            $row = CatalogConfiguration::findOrFail($p['configuration_id']);
            if ($row->part_type_id !== $p['type_id'] || $p['descendants'] || $p['source_status'] !== 'all') {
                throw ValidationException::withMessages(['parameters' => 'Выберите конкретный тип конфигурации, без подтипов, и все статусы.']);
            }
            $p['configuration_id'] = (int) $row->id;
            $p['price'] = app(CatalogBulkPrices::class)->price($p['price']);
            $p['configuration_version'] = hash('sha256', json_encode($row->only(['signature', 'price', 'is_active']), JSON_THROW_ON_ERROR));
        }
        ksort($p);

        return $p;
    }

    private function override(array $parameters): ?string
    {
        return $parameters['target_status'] === 'active'
            ? ($parameters['force'] ? 'force_active' : null)
            : $parameters['target_status'];
    }

    public function query(array $p)
    {
        return Product::where('product_type', 'auto_part')->whereIn('part_type_id', $p['type_ids'])
            ->when($p['source_status'] !== 'all', fn ($q) => $q->where('status', $p['source_status']));
    }

    public function reason(Product $product, array $p): ?string
    {
        if ($p['kind'] === 'status') {
            return $p['target_status'] === 'active' && ! $p['force'] && ! app(CatalogPublicationPolicy::class)->present($product)
                ? 'Отсутствует в актуальном Excel; требуется явная принудительная публикация.' : null;
        }
        if ($p['kind'] === 'configurations') {
            $template = app(CatalogConfigurations::class)->template($product->part_type_id);
            if (! $template) {
                // All combinations can be removed from a type, but its already managed offers
                // must still be retired by the explicit administrative operation.
                if (app(CatalogConfigurations::class)->canRetireManagedProduct($product)) {
                    return null;
                }

                return 'Для типа нет конфигурационного ТЗ.';
            }
            if ($product->product_option_template_id && ! in_array($product->optionTemplate?->slug, ['default_auto_part', $template->slug], true)) {
                return 'Пользовательский шаблон защищён.';
            }
        }

        return null;
    }

    private function state(Product $product, array $p): array
    {
        $state = $product->only(['id', 'status', 'part_type_id', 'import_present', 'publication_override', 'import_source', 'import_key', 'deleted_at']);
        if ($p['kind'] !== 'status') {
            $state += $product->only(['price', 'price_mode', 'old_price', 'product_option_template_id', 'configuration_managed']);
            $state['variants'] = $product->variants->map(fn ($v) => $v->only(['id', 'title', 'price', 'price_mode', 'old_price', 'options', 'is_active', 'is_default', 'catalog_configuration_id'])
                + ['configuration_auto_retired' => $v->configuration_auto_retired,
                    'values' => $v->optionValues->pluck('id')->sort()->values()->all()])->all();
        }

        return $state;
    }

    private function loadQuery($query, array $p)
    {
        return $query->with($p['kind'] === 'status' ? [] : ['optionTemplate', 'variants' => fn ($q) => $q->orderBy('id'), 'variants.optionValues.group']);
    }

    public function preview(User $user, array $input, ?\Closure $capture = null): array
    {
        app(CatalogBulkPrices::class)->authorize($user);
        $p = $this->parameters($input);
        app(CatalogConfigurations::class)->clear();
        if ($p['kind'] === 'configurations') {
            app(CatalogConfigurations::class)->prime($p['type_ids']);
        }
        $counts = array_fill_keys(['products', 'active', 'archived', 'draft', 'changed', 'same', 'protected', 'variants_to_update', 'variants_to_create', 'variants_to_retire'], 0);
        $hash = hash_init('sha256');
        hash_update($hash, json_encode($p, JSON_THROW_ON_ERROR));
        if ($p['kind'] === 'configurations') {
            hash_update($hash, CatalogConfiguration::whereIn('part_type_id', $p['type_ids'])->orderBy('id')->get()->toJson());
        }
        $this->loadQuery($this->query($p), $p)->chunkById(self::BATCH, function ($products) use ($p, &$counts, $hash, $capture): void {
            foreach ($products as $product) {
                $state = $this->state($product, $p);
                $fingerprint = hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
                hash_update($hash, $fingerprint);
                $counts['products']++;
                $counts[$product->status->value]++;
                if ($this->reason($product, $p)) {
                    $counts['protected']++;
                } elseif ($p['kind'] === 'status' && $product->status->value === $p['target_status'] && $product->publication_override === $this->override($p)) {
                    $counts['same']++;
                } elseif ($p['kind'] === 'configuration_price') {
                    $changed = $product->variants->filter(fn ($v) => (int) $v->catalog_configuration_id === $p['configuration_id'] && $v->price_mode === 'inherited' && $v->price !== $p['price'])->count();
                    $counts['variants_to_update'] += $changed;
                    $counts[$changed > 0 ? 'changed' : 'same']++;
                    $counts['protected'] += $product->variants->contains(fn ($v) => (int) $v->catalog_configuration_id === $p['configuration_id'] && $v->price_mode !== 'inherited') ? 1 : 0;
                } elseif ($p['kind'] === 'configurations') {
                    $difference = app(CatalogConfigurations::class)->changes($product);
                    $counts['variants_to_create'] += $difference['created_variants'];
                    $counts['variants_to_update'] += $difference['updated_variants'];
                    $counts['variants_to_retire'] += $difference['retired_variants'];
                    $counts[$difference['changed'] ? 'changed' : 'same']++;
                } else {
                    $counts['changed']++;
                }
                if ($capture) {
                    $capture($product, $fingerprint, $state);
                }
            }
        });

        return ['parameters' => $p, 'counts' => $counts, 'hash' => hash_final($hash), 'request_id' => (string) Str::uuid()];
    }

    public function confirm(User $user, array $preview): int
    {
        app(CatalogBulkPrices::class)->authorize($user);

        return DB::transaction(function () use ($user, $preview): int {
            app(CatalogPriceGuard::class)->lock();
            $existing = DB::table('catalog_actions')->where('request_id', $preview['request_id'])->where('user_id', $user->id)->value('id');
            if ($existing) {
                return (int) $existing;
            }
            app(CatalogPriceGuard::class)->assertWritable();
            if (DB::table('import_runs')->whereIn('status', ['running', 'running_rows', 'paused'])->exists()) {
                throw ValidationException::withMessages(['parameters' => 'Сначала завершите импорт.']);
            }
            $id = DB::table('catalog_actions')->insertGetId(['request_id' => $preview['request_id'], 'user_id' => $user->id,
                'kind' => $preview['parameters']['kind'], 'parameters' => json_encode($preview['parameters']),
                'report' => json_encode(['processed' => 0, 'changed' => 0, 'protected' => 0, 'total' => $preview['counts']['products']]), 'created_at' => now(), 'updated_at' => now()]);
            $current = $this->preview($user, $preview['parameters'], function ($product, $fingerprint, $state) use ($id): void {
                DB::table('catalog_action_items')->insert(['catalog_action_id' => $id, 'product_id' => $product->id,
                    'fingerprint' => $fingerprint, 'before' => json_encode($state)]);
            });
            if (! hash_equals($preview['hash'], $current['hash'])) {
                throw ValidationException::withMessages(['parameters' => 'Каталог или матрица изменились. Пересчитайте предпросмотр.']);
            }
            DB::table('catalog_price_locks')->where('id', 1)->update(['catalog_action_id' => $id]);
            DB::afterCommit(fn () => $this->dispatchPending());

            return $id;
        });
    }

    public function batch(int $id, int $cursor): void
    {
        DB::transaction(function () use ($id, $cursor): void {
            $lock = app(CatalogPriceGuard::class)->lock();
            $op = DB::table('catalog_actions')->where('id', $id)->lockForUpdate()->first();
            if (! $op || ! in_array($op->status, ['pending', 'running'], true) || (int) $op->last_id !== $cursor) {
                return;
            }
            if ((int) $lock->catalog_action_id !== $id) {
                throw new \LogicException('Lost catalog reservation');
            }
            $p = json_decode($op->parameters, true);
            app(CatalogConfigurations::class)->clear();
            if ($p['kind'] === 'configurations') {
                app(CatalogConfigurations::class)->prime($p['type_ids']);
            }
            $report = json_decode($op->report, true);
            $items = DB::table('catalog_action_items')->where('catalog_action_id', $id)->where('product_id', '>', $cursor)->orderBy('product_id')->limit(self::BATCH)->get();
            $products = $this->loadQuery(Product::whereIn('id', $items->pluck('product_id')), $p)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($items as $item) {
                $product = $products->get($item->product_id);
                if (! $product || ! hash_equals($item->fingerprint, hash('sha256', json_encode($this->state($product, $p), JSON_THROW_ON_ERROR)))) {
                    throw ValidationException::withMessages(['parameters' => 'Товар изменён после подтверждения: #'.$item->product_id]);
                }
            }
            if ($p['kind'] === 'configuration_price' && $cursor === 0) {
                $row = CatalogConfiguration::whereKey($p['configuration_id'])->lockForUpdate()->firstOrFail();
                if (! hash_equals($p['configuration_version'], hash('sha256', json_encode($row->only(['signature', 'price', 'is_active']), JSON_THROW_ON_ERROR)))) {
                    throw ValidationException::withMessages(['parameters' => 'Цена конфигурации изменилась.']);
                }
                DB::table('catalog_configurations')->where('id', $row->id)->update(['price' => $p['price'], 'updated_at' => now()]);
            }
            foreach ($items as $item) {
                $product = $products[$item->product_id];
                $result = [];
                if ($reason = $this->reason($product, $p)) {
                    $result = ['protected' => $reason];
                    $report['protected']++;
                } elseif ($p['kind'] === 'status') {
                    $override = $this->override($p);
                    DB::table('products')->where('id', $product->id)->update(['status' => $p['target_status'], 'publication_override' => $override, 'updated_at' => now()]);
                    $result = ['status' => $p['target_status'], 'publication_override' => $override];
                    $report['changed'] += $product->status->value !== $p['target_status'] || $product->publication_override !== $override ? 1 : 0;
                } elseif ($p['kind'] === 'configurations') {
                    $result = app(CatalogConfigurations::class)->synchronize($product);
                    $report['changed'] += ! empty($result['changed']) ? 1 : 0;
                } else {
                    $changed = DB::table('product_variants')->where('product_id', $product->id)->where('catalog_configuration_id', $p['configuration_id'])
                        ->where('price_mode', 'inherited')->where('price', '!=', $p['price'])->update(['price' => $p['price'], 'updated_at' => now()]);
                    if ($product->price_mode === 'inherited') {
                        $default = DB::table('product_variants')->where('product_id', $product->id)->where('is_default', true)->where('is_active', true)->first();
                        if ($default) {
                            DB::table('products')->where('id', $product->id)->update(['price' => $default->price, 'updated_at' => now()]);
                        }
                    }
                    $result = ['changed_variants' => $changed];
                    $report['changed'] += $changed > 0 ? 1 : 0;
                }
                $report['processed']++;
                DB::table('catalog_action_items')->where('catalog_action_id', $id)->where('product_id', $product->id)->update(['result' => json_encode($result)]);
            }
            $done = $items->count() < self::BATCH;
            DB::table('catalog_actions')->where('id', $id)->update(['last_id' => $items->last()?->product_id ?? $cursor,
                'status' => $done ? 'done' : 'pending', 'report' => json_encode($report), 'attempts' => 0, 'next_attempt_at' => null, 'error' => null, 'updated_at' => now()]);
            if ($done) {
                DB::table('catalog_price_locks')->where('catalog_action_id', $id)->update(['catalog_action_id' => null]);
            }
        }, 3);
    }

    public function resume(User $user, int $id): void
    {
        app(CatalogBulkPrices::class)->authorize($user);
        DB::transaction(function () use ($id): void {
            $lock = app(CatalogPriceGuard::class)->lock();
            abort_unless((int) $lock?->catalog_action_id === $id, 422);
            DB::table('catalog_actions')->where('id', $id)->where('status', 'paused')->update(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => null]);
        });
        $this->dispatchPending();
    }

    public function cancel(User $user, int $id): void
    {
        app(CatalogBulkPrices::class)->authorize($user);
        DB::transaction(function () use ($id): void {
            app(CatalogPriceGuard::class)->lock();
            DB::table('catalog_actions')->where('id', $id)->whereIn('status', ['pending', 'running', 'paused'])->update(['status' => 'canceled']);
            DB::table('catalog_price_locks')->where('catalog_action_id', $id)->update(['catalog_action_id' => null]);
        });
        $this->dispatchPending();
    }

    public function failure(int $id, int $cursor, Throwable $error): void
    {
        DB::table('catalog_actions')->where('id', $id)->where('last_id', $cursor)->whereIn('status', ['pending', 'running'])
            ->update(['status' => 'paused', 'error' => mb_substr($error->getMessage(), 0, 2000)]);
    }

    public function publish(int $id): void
    {
        DB::transaction(function () use ($id): void {
            app(CatalogPriceGuard::class)->lock();
            if (app(CatalogPriceGuard::class)->busy() || DB::table('import_runs')->whereIn('status', ['running', 'running_rows', 'paused'])->exists()) {
                return;
            }
            $op = DB::table('catalog_actions')->where('id', $id)->lockForUpdate()->first();
            if (! $op || ! in_array($op->status, ['done', 'canceled'], true) || $op->published_at !== null) {
                return;
            }
            $ids = DB::table('catalog_action_items')->where('catalog_action_id', $id)->whereNotNull('result')->where('product_id', '>', $op->publication_cursor)->orderBy('product_id')->limit(self::BATCH)->pluck('product_id');
            if ($ids->isNotEmpty()) {
                if (config('scout.driver') === 'meilisearch') {
                    app(CatalogSearchIndexer::class)->refreshConfirmed(Product::withTrashed()->with(CatalogSearchDocument::relations(new Product))->whereKey($ids)->get());
                }
                DB::table('catalog_actions')->where('id', $id)->update(['publication_cursor' => $ids->last()]);
            } else {
                if (config('yandex-feed.enabled')) {
                    app(YandexFeedInvalidator::class)->invalidate();
                }
                DB::table('catalog_actions')->where('id', $id)->update(['published_at' => now()]);
            }
        });
    }

    public function dispatchPending(): void
    {
        if (! app(CatalogConfigurations::class)->ready()) {
            return;
        }
        $delivery = DB::transaction(function (): ?array {
            app(CatalogPriceGuard::class)->lock();
            $op = DB::table('catalog_actions')->whereIn('status', ['pending', 'running'])->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->lockForUpdate()->first();
            if (! $op) {
                return null;
            }
            if ($op->attempts >= 3) {
                DB::table('catalog_actions')->where('id', $op->id)->update(['status' => 'paused', 'error' => 'Пакет не подтверждён после трёх доставок. Продолжите или отмените остаток.']);

                return null;
            }
            DB::table('catalog_actions')->where('id', $op->id)->update(['status' => 'running', 'attempts' => $op->attempts + 1, 'next_attempt_at' => now()->addMinutes(5)]);

            return [(int) $op->id, (int) $op->last_id];
        });
        try {
            if ($delivery) {
                Bus::dispatch(new ApplyCatalogAction(...$delivery));
            }
            if (app(CatalogPriceGuard::class)->busy() || DB::table('import_runs')->whereIn('status', ['running', 'running_rows', 'paused'])->exists()) {
                return;
            }
            foreach (DB::table('catalog_actions')->whereIn('status', ['done', 'canceled'])->whereNull('published_at')->limit(5)->pluck('id') as $id) {
                Bus::dispatch(new ApplyCatalogAction($id, 0, true));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}

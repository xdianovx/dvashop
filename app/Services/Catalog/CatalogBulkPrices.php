<?php

namespace App\Services\Catalog;

use App\Enums\AdminPermission;
use App\Jobs\ApplyCatalogPrices;
use App\Models\PartType;
use App\Models\Product;
use App\Models\User;
use App\Services\Feeds\YandexFeedInvalidator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CatalogBulkPrices
{
    public const BATCH_SIZE = 100;

    public function authorize(?User $user): void
    {
        abort_unless($user?->canPerformAdminAction(AdminPermission::UpdateProducts)
            && $user->canPerformAdminAction(AdminPermission::UpdateCatalog), 403);
    }

    public function scope(int $typeId, bool $descendants): array
    {
        $type = PartType::query()->findOrFail($typeId);

        return PartType::query()->whereKey([$type->id, ...($descendants ? app(PartTypeTreeService::class)->descendantIds($type) : [])])
            ->orderBy('id')->pluck('id')->all();
    }

    public function products(array $ids): Builder
    {
        return Product::query()->where('product_type', 'auto_part')->whereIn('part_type_id', $ids);
    }

    public function exceptions(array $ids): Builder
    {
        return $this->products($ids)->where(fn (Builder $query) => $query->where('price_mode', '!=', 'inherited')
            ->orWhereHas('variants', fn (Builder $variants) => $variants->where('price_mode', '!=', 'inherited')));
    }

    private function normalizeOverrides(array $overrides): array
    {
        $overrides = array_filter($overrides, fn ($value) => $value !== '' && $value !== null);
        Validator::make(['overrides' => $overrides], ['overrides' => 'array', 'overrides.*' => 'boolean'])->validate();
        ksort($overrides, SORT_NUMERIC);

        return array_map(fn ($value): bool => (bool) $value, $overrides);
    }

    public function price(mixed $price): string
    {
        $value = trim((string) $price);
        Validator::make(['price' => $value], ['price' => ['required', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/']], [], ['price' => 'цена'])->validate();
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');

        return (ltrim($whole, '0') ?: '0').'.'.str_pad($fraction, 2, '0');
    }

    /** Read-only, bounded memory. Hash every price/mode/identity, not a float checksum. */
    public function preview(int $typeId, bool $descendants, mixed $price, bool $replaceAll = false, array $overrides = [], string $action = 'change', bool $classifyDeviations = false, ?\Closure $capture = null, bool $adoptMatching = true): array
    {
        Validator::make(['action' => $action], ['action' => 'in:change,adopt'])->validate();
        if ($action === 'adopt' && $descendants) {
            throw ValidationException::withMessages(['price' => 'Для принятия исторических цен выберите один конкретный тип без вложенных типов.']);
        }
        $overrides = $this->normalizeOverrides($overrides);
        $price = $this->price($price);
        $ids = $this->scope($typeId, $descendants);
        $types = PartType::query()->whereKey($ids)->orderBy('id')->get(['id', 'full_title', 'base_price', 'base_old_price'])->toArray();
        $hash = hash_init('sha256');
        hash_update($hash, json_encode($types, JSON_THROW_ON_ERROR));
        $counts = array_fill_keys(['products', 'active', 'archived', 'variants', 'standard', 'exceptions', 'same', 'products_to_update', 'variants_to_update', 'preserved', 'forced', 'matching', 'deviation', 'ambiguous', 'individual', 'insufficient', 'classified_products', 'classified_variants', 'old_prices', 'import_prices', 'historical'], 0);
        $distribution = [];
        $plan = app(CatalogPricePlan::class);
        $plan->query($this->products($ids))
            ->chunkById(self::BATCH_SIZE, function ($products) use (&$counts, &$distribution, $hash, $price, $replaceAll, $overrides, $action, $classifyDeviations, $capture, $plan, $adoptMatching): void {
                if ($capture) {
                    $capture($products);
                }
                foreach ($products as $product) {
                    hash_update($hash, $plan->fingerprint($product));
                    $counts[$plan->classification($product, $price)]++;
                    $counts['old_prices'] += $product->old_price !== null || $product->variants->contains(fn ($v) => $v->old_price !== null) ? 1 : 0;
                    $counts['import_prices'] += $product->last_import_price !== null ? 1 : 0;
                    $key = $product->price ?? 'не задана';
                    $distribution[$key] = ($distribution[$key] ?? 0) + 1;
                    $force = (bool) ($overrides[$product->id] ?? $replaceAll);
                    $exception = $product->price_mode !== 'inherited' || $product->variants->contains(fn ($v) => $v->price_mode !== 'inherited');
                    $counts['products']++;
                    $counts['historical'] += $product->price_mode === 'unknown' || $product->variants->contains(fn ($v) => $v->price_mode === 'unknown') ? 1 : 0;
                    $counts['active'] += $product->status->value === 'active' ? 1 : 0;
                    $counts['archived'] += $product->status->value === 'archived' ? 1 : 0;
                    $counts['variants'] += $product->variants->count();
                    $counts[$exception ? 'exceptions' : 'standard']++;
                    $counts['same'] += $product->price === $price && $product->variants->every(fn ($v) => $v->price === $price) ? 1 : 0;
                    if ($action === 'adopt') {
                        if (($product->price_mode === 'inherited' && $product->price !== $price)
                            || $product->variants->contains(fn ($v) => $v->price_mode === 'inherited' && $v->price !== $product->price)) {
                            throw ValidationException::withMessages(['price' => 'Уже наследуемые цены не соответствуют выбранной базе. Сначала выполните обычное изменение общей цены.']);
                        }
                        $mode = $plan->adoption($product, $price, $classifyDeviations, $overrides, $adoptMatching);
                        $counts['classified_products'] += $mode !== null && $product->price_mode === 'unknown' ? 1 : 0;
                        $counts['classified_variants'] += $mode !== null ? $product->variants->where('price_mode', 'unknown')->count() : 0;
                        $counts['preserved'] += $mode === null ? 1 : 0;

                        continue;
                    }
                    $counts['products_to_update'] += ($force || $product->price_mode === 'inherited') && ($product->price !== $price || $product->price_mode !== 'inherited') ? 1 : 0;
                    $counts['variants_to_update'] += $product->variants->filter(fn ($v) => ($force || ($product->price_mode === 'inherited' && $v->price_mode === 'inherited')) && ($v->price !== $price || $v->price_mode !== 'inherited'))->count();
                    $counts['preserved'] += $exception && ! $force ? 1 : 0;
                    $counts['forced'] += $exception && $force ? 1 : 0;
                }
            });

        arsort($distribution);

        $sourceHash = hash_final($hash);
        $parametersHash = hash('sha256', json_encode([$typeId, $descendants, $price, $action], JSON_THROW_ON_ERROR));
        $decisionsHash = hash('sha256', json_encode([$replaceAll, $overrides, $classifyDeviations, $adoptMatching], JSON_THROW_ON_ERROR));

        return ['source_hash' => $sourceHash, 'parameters_hash' => $parametersHash, 'decisions_hash' => $decisionsHash, 'adopt_matching' => $adoptMatching, 'action' => $action, 'classify_deviations' => $classifyDeviations, 'distribution' => $distribution, 'suggested_price' => array_key_first($distribution), 'type_id' => $typeId, 'descendants' => $descendants, 'type_ids' => $ids, 'types' => $types,
            'price' => $price, 'counts' => $counts, 'hash' => hash('sha256', $sourceHash.$parametersHash.$decisionsHash)];
    }

    public function confirm(User $user, array $preview, bool $replaceAll = false, array $overrides = []): int
    {
        $this->authorize($user);
        $overrides = $this->normalizeOverrides($overrides);

        return DB::transaction(function () use ($user, $preview, $replaceAll, $overrides): int {
            app(CatalogPriceGuard::class)->lock();
            if (! empty($preview['request_id'])) {
                $existing = DB::table('catalog_price_operations')->where('user_id', $user->id)->where('preview->request_id', $preview['request_id'])->value('id');
                if ($existing !== null) {
                    return (int) $existing;
                }
            }
            app(CatalogPriceGuard::class)->assertWritable();
            if (DB::table('import_runs')->whereIn('status', ['running', 'running_rows', 'paused'])->exists()) {
                throw ValidationException::withMessages(['price' => 'Сначала завершите или отмените импорт каталога.']);
            }
            $action = $preview['action'] ?? 'change';
            $deviations = $preview['classify_deviations'] ?? false;
            $id = DB::table('catalog_price_operations')->insertGetId([
                'user_id' => $user->id, 'action' => $action, 'classify_deviations' => $deviations,
                'type_ids' => json_encode($preview['type_ids']), 'previous_bases' => json_encode($preview['types']),
                'price' => $preview['price'], 'replace_all' => $replaceAll, 'overrides' => json_encode($overrides),
                'preview' => json_encode($preview), 'report' => json_encode(['products' => 0, 'variants' => 0, 'preserved' => 0, 'forced' => 0, 'skipped' => 0, 'archived' => 0, 'errors' => 0, 'processed' => 0]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $current = $this->preview($preview['type_id'], $preview['descendants'], $preview['price'], $replaceAll, $overrides, $action, $deviations,
                function ($products) use ($id): void {
                    DB::table('catalog_price_items')->insert($products->map(fn ($p) => [
                        'operation_id' => $id, 'product_id' => $p->id, 'fingerprint' => app(CatalogPricePlan::class)->fingerprint($p),
                    ])->all());
                }, $preview['adopt_matching'] ?? true);
            if (isset($preview['source_hash']) && ! hash_equals($current['source_hash'], $preview['source_hash'])) {
                throw ValidationException::withMessages(['price' => 'Другой пользователь изменил цены или состав каталога. Выполните новый расчёт и проверьте изменения.']);
            }
            if (! hash_equals($current['hash'], $preview['hash'])) {
                throw ValidationException::withMessages(['price' => 'Параметры или решения изменены после расчёта. Пересчитайте итог перед подтверждением.']);
            }
            DB::table('catalog_price_locks')->where('id', 1)->update(['operation_id' => $id]);
            // A durable operation is committed before dispatch; scheduler also redelivers it.
            DB::afterCommit(fn () => $this->dispatchPending());

            return $id;
        });
    }

    /** One transaction per bounded batch; cursor and effects commit together. */
    public function batch(int $id, ?int $expectedCursor = null): bool
    {
        return DB::transaction(function () use ($id, $expectedCursor): bool {
            $lock = app(CatalogPriceGuard::class)->lock();
            $op = DB::table('catalog_price_operations')->where('id', $id)->lockForUpdate()->first();
            if (! $op || ! in_array($op->status, ['pending', 'running'], true)
                || ($expectedCursor !== null && (int) $op->last_id !== $expectedCursor)) {
                return true;
            }
            if ((int) $lock?->operation_id !== $id) {
                throw new \LogicException('Pricing operation lost its catalog reservation.');
            }
            $price = $this->price($op->price);
            $ids = json_decode($op->type_ids, true);
            $overrides = json_decode($op->overrides, true);
            $report = json_decode($op->report, true);
            $plan = app(CatalogPricePlan::class);
            $adoptMatching = json_decode($op->preview, true)['adopt_matching'] ?? true;
            $items = DB::table('catalog_price_items')->where('operation_id', $id)->where('product_id', '>', $op->last_id)
                ->orderBy('product_id')->limit(self::BATCH_SIZE)->get();
            $products = $plan->query($this->products($ids)->whereKey($items->pluck('product_id')), true)->orderBy('id')->get();
            $expected = $items->pluck('fingerprint', 'product_id');
            $bases = PartType::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get(['id', 'full_title', 'base_price', 'base_old_price'])->toArray();
            $previous = json_decode($op->previous_bases, true);
            if ((int) $op->last_id !== 0) {
                foreach ($previous as &$base) {
                    $base['base_price'] = $price;
                }
                unset($base);
            }
            if ($bases != $previous || $products->count() !== $items->count()
                || $products->contains(fn ($p) => ! hash_equals($expected[$p->id], $plan->fingerprint($p)))
                || $this->products($ids)->count() !== (int) json_decode($op->preview, true)['counts']['products']) {
                DB::table('catalog_price_operations')->where('id', $id)->update([
                    'status' => 'paused', 'error' => 'Цены или состав каталога изменились после подтверждения. Отмените остаток операции и выполните новый предварительный просмотр.',
                    'next_attempt_at' => null, 'updated_at' => now(),
                ]);

                return true;
            }
            if ((int) $op->last_id === 0) {
                DB::table('part_types')->whereIn('id', $ids)->update(['base_price' => $op->price, 'updated_at' => now()]);
            }
            $affected = $products->filter(function ($p) use ($op, $plan, $overrides, $price, $adoptMatching): bool {
                if ($op->action === 'adopt') {
                    return $plan->adoption($p, $price, (bool) $op->classify_deviations, $overrides, $adoptMatching) !== null
                        && ($p->price_mode === 'unknown' || $p->variants->contains(fn ($v) => $v->price_mode === 'unknown'));
                }
                $force = (bool) ($overrides[$p->id] ?? $op->replace_all);

                return (($force || $p->price_mode === 'inherited') && ($p->price !== $price || $p->price_mode !== 'inherited'))
                    || $p->variants->contains(fn ($v) => ($force || ($p->price_mode === 'inherited' && $v->price_mode === 'inherited'))
                        && ($v->price !== $price || $v->price_mode !== 'inherited'));
            });
            if ($affected->isNotEmpty()) {
                $titles = Product::query()->whereKey($affected->modelKeys())->pluck('title', 'id');
                DB::table('catalog_price_overrides')->insert($affected->map(fn ($p) => [
                    'operation_id' => $id, 'product_id' => $p->id, 'title' => $titles[$p->id],
                    'previous_prices' => json_encode($p->toArray(), JSON_THROW_ON_ERROR),
                ])->values()->all());
            }
            if ($op->action === 'adopt') {
                foreach (['inherited', 'individual'] as $mode) {
                    $selected = $products->filter(fn ($p) => $plan->adoption($p, $price, (bool) $op->classify_deviations, $overrides, $adoptMatching) === $mode)->modelKeys();
                    $report['products'] += DB::table('products')->whereIn('id', $selected)->where('price_mode', 'unknown')->update(['price_mode' => $mode, 'updated_at' => now()]);
                    $report['variants'] += DB::table('product_variants')->whereIn('product_id', $selected)->where('price_mode', 'unknown')->update(['price_mode' => $mode, 'updated_at' => now()]);
                }
                $report['preserved'] += $products->filter(fn ($p) => $plan->adoption($p, $price, (bool) $op->classify_deviations, $overrides, $adoptMatching) === null)->count();
            } else {
                $forced = $products->filter(fn ($p) => (bool) ($overrides[$p->id] ?? $op->replace_all))->pluck('id')->all();
                $inherited = $products->where('price_mode', 'inherited')->pluck('id')->all();
                $changedProducts = array_values(array_unique([...$forced, ...$inherited]));
                $exceptions = $this->exceptions($ids)->whereKey($products->modelKeys())->pluck('id');
                $overwritten = $exceptions->intersect($forced)->values();
                $report['forced'] += $overwritten->count();
                $report['preserved'] += $exceptions->diff($forced)->count();
                $report['archived'] += $products->filter(fn ($p) => $p->status->value === 'archived')->count();
                $report['skipped'] += $products->count() - count($changedProducts);
                $report['products'] += DB::table('products')->whereIn('id', $changedProducts)
                    ->where(fn ($q) => $q->whereNull('price')->orWhere('price', '!=', $op->price)->orWhere('price_mode', '!=', 'inherited'))
                    ->update(['price' => $op->price, 'price_mode' => 'inherited', 'updated_at' => now()]);
                $report['variants'] += DB::table('product_variants')->where(fn ($q) => $q->whereIn('product_id', $forced)
                    ->orWhere(fn ($q) => $q->whereIn('product_id', $inherited)->where('price_mode', 'inherited')))
                    ->where(fn ($q) => $q->where('price', '!=', $op->price)->orWhere('price_mode', '!=', 'inherited'))
                    ->update(['price' => $op->price, 'price_mode' => 'inherited', 'updated_at' => now()]);
            }
            $report['processed'] = ($report['processed'] ?? 0) + $products->count();
            $done = $items->count() < self::BATCH_SIZE;
            DB::table('catalog_price_operations')->where('id', $id)->update([
                'last_id' => $products->last()?->id ?? $op->last_id, 'status' => $done ? 'done' : 'pending',
                'report' => json_encode($report), 'error' => null, 'completed_at' => $done ? now() : null,
                'updated_at' => now(), 'next_attempt_at' => null, 'attempts' => 0, 'failures' => 0,
            ]);
            if ($done) {
                DB::table('catalog_price_locks')->where('id', 1)->update(['operation_id' => null]);
            }

            return $done;
        }, 3);
    }

    public function recordFailure(int $id, Throwable $error, ?int $expectedCursor = null): void
    {
        DB::transaction(function () use ($id, $error, $expectedCursor): void {
            app(CatalogPriceGuard::class)->lock();
            $op = DB::table('catalog_price_operations')->where('id', $id)->lockForUpdate()->first();
            if (! $op || ! in_array($op->status, ['pending', 'running'], true)
                || ($expectedCursor !== null && (int) $op->last_id !== $expectedCursor)) {
                return;
            }
            $report = json_decode($op->report, true);
            $report['errors']++;
            $failures = (int) $op->failures + 1;
            $attempts = max((int) $op->attempts, $failures);
            DB::table('catalog_price_operations')->where('id', $id)->update([
                'status' => 'pending', 'attempts' => $attempts, 'failures' => $failures, 'report' => json_encode($report),
                'error' => mb_substr($error->getMessage(), 0, 2000), 'next_attempt_at' => now()->addMinutes(5), 'updated_at' => now(),
            ]);
            if ($attempts >= 3) {
                $this->stop($id, 'failed', mb_substr($error->getMessage(), 0, 2000));
            }
        }, 3);
    }

    private function stop(int $id, string $status, ?string $error): void
    {
        DB::table('catalog_price_operations')->where('id', $id)->update([
            'status' => $status, 'error' => $error, 'completed_at' => now(), 'next_attempt_at' => null, 'notified_at' => null,
        ]);
        DB::table('catalog_price_locks')->where('operation_id', $id)->update(['operation_id' => null]);
    }

    public function cancel(User $user, int $id): void
    {
        $this->authorize($user);
        DB::transaction(function () use ($id): void {
            app(CatalogPriceGuard::class)->lock();
            $op = DB::table('catalog_price_operations')->where('id', $id)->lockForUpdate()->first();
            if ($op && in_array($op->status, ['pending', 'running', 'paused', 'failed'], true)) {
                $this->stop($id, 'canceled', 'Остаток операции отменён. Уже применённые изменения сохранены.');
            }
        }, 3);
        $this->publishNotifications();
    }

    public function resume(User $user, int $id): void
    {
        $this->authorize($user);
        DB::transaction(function () use ($id): void {
            $lock = app(CatalogPriceGuard::class)->lock();
            $op = DB::table('catalog_price_operations')->where('id', $id)->lockForUpdate()->first();
            if (! $op || ! in_array($op->status, ['pending', 'running', 'paused', 'failed'], true)) {
                throw ValidationException::withMessages(['price' => 'Эту операцию нельзя продолжить.']);
            }
            if (($lock->operation_id !== null && (int) $lock->operation_id !== $id)
                || DB::table('catalog_price_operations')->where('id', '>', $id)->exists()
                || DB::table('import_runs')->whereIn('status', ['running', 'running_rows', 'paused'])->exists()) {
                throw ValidationException::withMessages(['price' => 'После остановки началась другая операция или импорт. Создайте новый предварительный просмотр.']);
            }
            DB::table('catalog_price_locks')->where('id', 1)->update(['operation_id' => $id]);
            DB::table('catalog_price_operations')->where('id', $id)->update(['status' => 'pending', 'attempts' => 0, 'failures' => 0, 'completed_at' => null, 'next_attempt_at' => null]);
            DB::afterCommit(fn () => $this->dispatchPending());
        }, 3);
    }

    public function publishNotifications(): void
    {
        foreach (DB::table('catalog_price_operations')->whereIn('status', ['done', 'failed', 'canceled'])->whereNull('notified_at')->limit(10)->get(['id']) as $op) {
            try {
                if (config('yandex-feed.enabled')) {
                    app(YandexFeedInvalidator::class)->invalidate();
                }
                DB::table('catalog_price_operations')->where('id', $op->id)->update(['notified_at' => now()]);
            } catch (Throwable $e) {
                report($e); // Retry publication even if the worker died after its last batch.
            }
        }
    }

    public function dispatchPending(): void
    {
        if (! app(CatalogPricingSchema::class)->ready()) {
            return;
        }
        $this->publishNotifications();
        $delivery = DB::transaction(function (): ?array {
            app(CatalogPriceGuard::class)->lock();
            $op = DB::table('catalog_price_operations')->whereIn('status', ['pending', 'running'])
                ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->first();
            if (! $op) {
                return null;
            }
            // Count durable deliveries too: hard worker deaths cannot retry forever.
            if ($op->attempts >= 3) {
                $this->stop($op->id, 'failed', 'Превышено число повторов пакета. Выполненные пакеты сохранены.');

                return null;
            }
            DB::table('catalog_price_operations')->where('id', $op->id)->update(['status' => 'running', 'attempts' => $op->attempts + 1, 'next_attempt_at' => now()->addMinutes(5)]);

            return ['id' => $op->id, 'cursor' => (int) $op->last_id];
        });
        if ($delivery !== null) {
            try {
                Bus::dispatch(new ApplyCatalogPrices($delivery['id'], $delivery['cursor']));
            } catch (Throwable $e) {
                $this->recordFailure($delivery['id'], $e, $delivery['cursor']);
                report($e); // Persistent lease expires; scheduler retries a lost enqueue.
            }
        }
    }
}

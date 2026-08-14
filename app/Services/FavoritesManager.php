<?php

namespace App\Services;

use App\Models\FavoriteItem;
use App\Models\FavoriteList;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FavoritesManager
{
    public const COOKIE_NAME = 'favorites_token';

    public const COOKIE_TTL_DAYS = 365;

    private const REQUEST_SUMMARY_ATTRIBUTE = 'dvashop.favorites_summary';

    private const COOKIE_MINUTES = 60 * 24 * self::COOKIE_TTL_DAYS;

    public function __construct(
        private readonly StorefrontProductAvailability $availability,
    ) {}

    /** @return array{count:int, product_ids:list<int>} */
    public function summaryForRequest(Request $request): array
    {
        $cached = $request->attributes->get(self::REQUEST_SUMMARY_ATTRIBUTE);

        if (is_array($cached)
            && isset($cached['count'], $cached['product_ids'])
            && is_array($cached['product_ids'])) {
            return $cached;
        }

        $token = trim((string) $request->cookie(self::COOKIE_NAME));
        $summary = $token === '' ? $this->emptySummary() : $this->summaryForToken($token);
        $request->attributes->set(self::REQUEST_SUMMARY_ATTRIBUTE, $summary);

        return $summary;
    }

    public function contains(Request $request, Product|int $product): bool
    {
        $productId = $product instanceof Product ? (int) $product->getKey() : $product;

        return in_array($productId, $this->productIds($request), true);
    }

    /** @return array{count:int, product_ids:list<int>} */
    public function add(Request $request, int $productId): array
    {
        $product = $this->findAvailableProduct($productId);

        $list = DB::transaction(function () use ($request, $product): FavoriteList {
            $list = $this->resolveActiveList($request) ?? FavoriteList::query()->create([
                'expires_at' => now()->addDays(self::COOKIE_TTL_DAYS),
            ]);

            FavoriteItem::query()->insertOrIgnore([
                'favorite_list_id' => $list->getKey(),
                'product_id' => $product->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->extendLifetime($list);

            return $list;
        });

        $this->queueCookie($list);
        $this->forgetRequestSummary($request);
        $this->requestWithToken($request, $list->token);

        return $this->summaryForRequest($request);
    }

    /** @return array{count:int, product_ids:list<int>} */
    public function remove(Request $request, int $productId): array
    {
        $list = $this->resolveActiveList($request);

        if (! $list instanceof FavoriteList) {
            $this->forgetRequestSummary($request);

            return $this->summaryForRequest($request);
        }

        DB::transaction(function () use ($list, $productId): void {
            $list->items()->where('product_id', $productId)->delete();
            $this->extendLifetime($list);
        });

        $this->queueCookie($list);
        $this->forgetRequestSummary($request);
        $this->requestWithToken($request, $list->token);

        return $this->summaryForRequest($request);
    }

    /** @return list<int> */
    public function productIds(Request $request): array
    {
        return $this->summaryForRequest($request)['product_ids'];
    }

    public function count(Request $request): int
    {
        return $this->summaryForRequest($request)['count'];
    }

    /** @return Collection<int, Product> */
    public function products(Request $request): Collection
    {
        $productIds = $this->productIds($request);

        if ($productIds === []) {
            return collect();
        }

        return $this->availableProductsQuery()
            ->whereKey($productIds)
            ->with([
                'variants' => fn ($query) => $this->availability->variants($query)
                    ->orderByDesc('is_default')
                    ->orderBy('id'),
                'variants.optionValues.group',
                'mainImage',
                'visibleImages',
                'category',
                'partType',
            ])
            ->orderBy('position')
            ->orderBy('title')
            ->orderBy('id')
            ->get();
    }

    private function findAvailableProduct(int $productId): Product
    {
        $product = $this->availableProductsQuery()->whereKey($productId)->first();

        if (! $product instanceof Product) {
            throw ValidationException::withMessages([
                'product_id' => 'Товар недоступен для добавления в избранное.',
            ]);
        }

        return $product;
    }

    private function availableProductsQuery(): Builder
    {
        return $this->availability->products(Product::query())
            ->whereHas('variants', fn (Builder $query): Builder => $this->availability->variants($query));
    }

    /** @return array{count:int, product_ids:list<int>} */
    private function summaryForToken(string $token): array
    {
        $productIds = FavoriteItem::query()
            ->whereHas('favoriteList', fn (Builder $query): Builder => $query
                ->active()
                ->where('token', $token))
            ->whereHas('product', fn (Builder $query): Builder => $this->availability->products($query)
                ->whereHas('variants', fn (Builder $variantQuery): Builder => $this->availability->variants($variantQuery)))
            ->orderBy('favorite_items.id')
            ->pluck('product_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return [
            'count' => count($productIds),
            'product_ids' => $productIds,
        ];
    }

    private function resolveActiveList(Request $request): ?FavoriteList
    {
        $token = trim((string) $request->cookie(self::COOKIE_NAME));

        if ($token === '') {
            return null;
        }

        return FavoriteList::query()->active()->where('token', $token)->first();
    }

    private function extendLifetime(FavoriteList $list): void
    {
        $list->forceFill(['expires_at' => now()->addDays(self::COOKIE_TTL_DAYS)])->save();
    }

    private function queueCookie(FavoriteList $list): void
    {
        Cookie::queue(cookie(
            self::COOKIE_NAME,
            $list->token,
            self::COOKIE_MINUTES,
            null,
            null,
            null,
            true,
            false,
            'lax'
        ));
    }

    private function requestWithToken(Request $request, string $token): Request
    {
        $request->cookies->set(self::COOKIE_NAME, $token);

        return $request;
    }

    private function forgetRequestSummary(Request $request): void
    {
        $request->attributes->remove(self::REQUEST_SUMMARY_ATTRIBUTE);
    }

    /** @return array{count:int, product_ids:list<int>} */
    private function emptySummary(): array
    {
        return [
            'count' => 0,
            'product_ids' => [],
        ];
    }
}

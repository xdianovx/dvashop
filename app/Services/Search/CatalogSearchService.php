<?php

namespace App\Services\Search;

use App\Models\PartType;
use App\Models\ProductCategory;
use Illuminate\Support\Facades\Log;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Exceptions\CommunicationException;
use Meilisearch\Exceptions\InvalidResponseBodyException;
use Meilisearch\Exceptions\TimeOutException;

final class CatalogSearchService
{
    public function __construct(
        private readonly DatabaseCatalogSearchProvider $database,
        private readonly MeilisearchCatalogSearchProvider $meilisearch,
    ) {}

    public function search(string $query, ?ProductCategory $category = null, ?PartType $partType = null): array
    {
        if ($query !== '' && config('catalog-search.driver') === 'meilisearch') {
            try {
                return $this->meilisearch->search($query, $category, $partType);
            } catch (CommunicationException|TimeOutException $exception) {
                $this->fallback($exception::class);
            } catch (InvalidResponseBodyException $exception) {
                if ($exception->getCode() < 500) {
                    throw $exception;
                }
                $this->fallback($exception::class, $exception->getCode());
            } catch (ApiException $exception) {
                // A broken request is a programming error, not an outage.
                if ($exception->getCode() < 500 && ! in_array($exception->getCode(), [401, 403, 404, 429], true) && $exception->errorCode !== 'index_not_found') {
                    throw $exception;
                }
                $this->fallback($exception::class, $exception->getCode());
            }
        }

        return $this->database->search($query, $category, $partType);
    }

    private function fallback(string $exception, ?int $status = null): void
    {
        Log::warning('Catalog search unavailable; using database search.', ['exception' => $exception, 'status' => $status]);
    }
}

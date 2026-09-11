<?php

namespace App\Services\Search;

use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

/** A search continuation has no invented public total or last page. */
final class CatalogSearchPaginator extends CursorPaginator
{
    public function __construct(
        Collection $items,
        private readonly ?Cursor $next,
        private readonly ?Cursor $previous,
        public readonly bool $scanLimited = false,
    ) {
        parent::__construct($items, 12, null, [
            'path' => Paginator::resolveCurrentPath(),
            'cursorName' => 'search_cursor',
        ]);
        $this->withQueryString();
    }

    public function nextCursor(): ?Cursor
    {
        return $this->next;
    }

    public function previousCursor(): ?Cursor
    {
        return $this->previous;
    }

    public function hasMorePages(): bool
    {
        return $this->next !== null;
    }

    public function hasPages(): bool
    {
        return $this->next !== null || $this->previous !== null;
    }
}

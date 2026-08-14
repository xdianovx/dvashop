<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'favorite_list_id',
    'product_id',
])]
class FavoriteItem extends Model
{
    public function favoriteList(): BelongsTo
    {
        return $this->belongsTo(FavoriteList::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

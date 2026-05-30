<?php

use Illuminate\Database\Eloquent\Builder;

/**
 * Category variant that registers an Eloquent global scope hiding the "galaxy"
 * node. Used to verify that tree integrity checks and tree fixing operate on
 * the whole tree, ignoring model global scopes.
 */
class CategoryWithGlobalScope extends Category
{
    protected $table = 'categories';

    protected static function booted(): void
    {
        static::addGlobalScope('visible', function (Builder $query) {
            $query->where('name', '<>', 'galaxy');
        });
    }
}

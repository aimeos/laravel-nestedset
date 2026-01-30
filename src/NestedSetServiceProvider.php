<?php

namespace Aimeos\Nestedset;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

class NestedSetServiceProvider extends ServiceProvider
{
    public function register()
    {
        Blueprint::macro('nestedSet', function (string $idColumn = 'id', string $type = 'unsignedInteger') {
            NestedSet::columns($this, $idColumn, $type);
        });

        Blueprint::macro('nestedSet2', function (string $idColumn = 'id') {
            NestedSet::columns2($this, $idColumn);
        });

        Blueprint::macro('dropNestedSet', function () {
            NestedSet::dropColumns($this);
        });
    }
}

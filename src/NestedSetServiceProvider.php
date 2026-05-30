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

        Blueprint::macro('nestedSetDepth', function (string $idColumn = 'id') {
            NestedSet::columnsDepth($this, $idColumn);
        });

        Blueprint::macro('nestedSetIndex', function (string $idColumn = 'id') {
            NestedSet::columnsIndex($this, $idColumn);
        });

        Blueprint::macro('dropNestedSet', function () {
            NestedSet::dropColumns($this);
        });

        Blueprint::macro('dropNestedSetDepth', function () {
            NestedSet::dropColumnsDepth($this);
        });

        Blueprint::macro('dropNestedSetIndex', function () {
            NestedSet::dropColumnsIndex($this);
        });
    }
}

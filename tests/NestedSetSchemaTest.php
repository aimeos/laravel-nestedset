<?php

use Aimeos\Nestedset\NestedSet;
use Illuminate\Support\Facades\Schema;

class NestedSetSchemaTest extends Orchestra\Testbench\TestCase
{
    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => env('DB_DRIVER', 'sqlite'),
            'host' => env('DB_HOST', ''),
            'port' => env('DB_PORT', ''),
            'database' => env('DB_DATABASE', ':memory:'),
            'username' => env('DB_USERNAME', ''),
            'password' => env('DB_PASSWORD', ''),
            'prefix' => 'prfx_',
        ]);
    }

    public function testDropsEveryNestedSetIndexItCreates(): void
    {
        $table = 'nested_set_index_test';

        Schema::dropIfExists($table);
        Schema::create($table, function ($table) {
            $table->increments('id');
            NestedSet::columns($table);
            NestedSet::columnsIndex($table);
        });

        $schema = Schema::getConnection()->getSchemaBuilder();

        $indexes = $schema->getIndexes($table);

        $this->assertTrue($this->hasIndexOn($indexes, [NestedSet::PARENT_ID, NestedSet::LFT]));
        $this->assertTrue($this->hasIndexOn($indexes, [NestedSet::LFT, NestedSet::RGT]));
        $this->assertTrue($this->hasIndexOn($indexes, [NestedSet::RGT]));

        Schema::table($table, function ($table) {
            NestedSet::dropColumnsIndex($table);
        });

        $indexes = $schema->getIndexes($table);

        $this->assertFalse($this->hasIndexOn($indexes, [NestedSet::PARENT_ID, NestedSet::LFT]));
        $this->assertFalse($this->hasIndexOn($indexes, [NestedSet::LFT, NestedSet::RGT]));
        $this->assertFalse($this->hasIndexOn($indexes, [NestedSet::RGT]));

        Schema::dropIfExists($table);
    }

    protected function hasIndexOn(array $indexes, array $columns): bool
    {
        foreach ($indexes as $index) {
            if (($index['columns'] ?? []) === $columns) {
                return true;
            }
        }

        return false;
    }
}

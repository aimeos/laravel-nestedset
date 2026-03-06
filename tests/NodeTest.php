<?php

use Aimeos\Nestedset\NestedSet;

class NodeTest extends NodeTestBase
{
    public function setUp(): void
    {
        $this->categoryData = new CategoryData();
        parent::setUp();
    }

    protected static function getTableName(): string
    {
        return 'categories';
    }

    protected static function getModelClass(): string
    {
        return Category::class;
    }

    protected static function createTable(\Illuminate\Database\Schema\Blueprint $table): void
    {
        $table->increments('id');
        $table->string('name');
        $table->softDeletes();

        NestedSet::columns($table);
        NestedSet::columnsDepth($table);
    }

    protected function seedTable(string $table, array $data): void
    {
        $driver = DB::connection()->getDriverName();
        $prefixedTable = DB::connection()->getTablePrefix() . $table;

        if ($driver === 'sqlsrv') {
            DB::unprepared("SET IDENTITY_INSERT [$prefixedTable] ON");
        }

        DB::table($table)->insert($data);

        if ($driver === 'sqlsrv') {
            DB::unprepared("SET IDENTITY_INSERT [$prefixedTable] OFF");
        } elseif ($driver === 'pgsql') {
            DB::statement("SELECT setval(pg_get_serial_sequence('$prefixedTable', 'id'), coalesce(max(id), 0) + 1, false) FROM \"$prefixedTable\"");
        }
    }
}

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

    public function testCountErrorsIgnoresGlobalScope()
    {
        // The global scope actually hides the node from normal queries.
        $this->assertNull(CategoryWithGlobalScope::find($this->ids[8]));

        // A healthy tree reports the same (zero) errors with and without the scope.
        $this->assertEquals(Category::countErrors(), CategoryWithGlobalScope::countErrors());

        // Corrupt the globally-hidden node (galaxy).
        Category::where('id', $this->ids[8])->update(['_lft' => 999]);

        // The error must still be detected even though the node is scoped out.
        $errors = CategoryWithGlobalScope::countErrors();
        $this->assertGreaterThanOrEqual(1, $errors['oddness']);
        $this->assertEquals(Category::countErrors(), $errors);
    }

    public function testFixTreeIgnoresGlobalScope()
    {
        Category::where('id', $this->ids[5])->update(['_lft' => 14]);

        $fixed = CategoryWithGlobalScope::fixTree();

        $this->assertTrue($fixed > 0);
        $this->assertFalse(Category::isBroken());

        // The globally-hidden node is still correctly positioned under its parent.
        $galaxy = Category::find($this->ids[8]);
        $samsung = Category::find($this->ids[7]);
        $this->assertTrue($galaxy->isDescendantOf($samsung));
    }

    public function testEventedDescendantDeletesAreChunked()
    {
        $node = ChunkedCategory::find($this->ids[5]);

        DB::flushQueryLog();

        $node->delete();

        $selects = array_filter(DB::connection()->getQueryLog(), function ($entry) {
            $query = strtolower($entry['query']);

            return str_starts_with($query, 'select')
                && str_contains($query, '_lft')
                && str_contains($query, 'order by');
        });

        $this->assertGreaterThanOrEqual(3, count($selects));
        $this->assertEquals(0, Category::whereIn('id', [
            $this->ids[5],
            $this->ids[6],
            $this->ids[7],
            $this->ids[8],
            $this->ids[9],
            $this->ids[10],
        ])->count());
    }
}

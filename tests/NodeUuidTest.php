<?php

use Aimeos\Nestedset\NestedSet;

class NodeUuidTest extends NodeTestBase
{
    public function setUp(): void
    {
        $this->categoryData = new CategoryData(true);
        parent::setUp();
    }

    protected static function getTableName(): string
    {
        return 'uuid_categories';
    }

    protected static function getModelClass(): string
    {
        return CategoryUuid::class;
    }

    protected static function createTable(\Illuminate\Database\Schema\Blueprint $table): void
    {
        $table->uuid('id');
        $table->string('name');
        $table->softDeletes();

        NestedSet::columns($table, 'id', 'uuid');
        NestedSet::columnsDepth($table);
    }
}

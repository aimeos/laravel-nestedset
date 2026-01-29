<?php

use Aimeos\Nestedset\NestedSet;

class NodeTest extends NodeTestBase
{
    public function __construct($name = null)
    {
        parent::__construct($name);
        $this->categoryData = new CategoryData();
    }

    protected function getTable(): string
    {
        return 'categories';
    }

    protected function getModelClass(): string
    {
        return Category::class;
    }

    protected function createTable(\Illuminate\Database\Schema\Blueprint $table): void
    {
        $table->increments('id');
        $table->string('name');
        $table->softDeletes();
        NestedSet::columns($table);
    }

    public function testSubtreeIsFixed()
    {
        $this->getModelClass()::where('id', '=', $this->ids[8])->update(['_lft' => 11]);

        $fixed = $this->getModelClass()::fixSubtree($this->getModelClass()::find($this->ids[5]));
        $this->assertEquals(1, $fixed);
        $this->assertTreeNotBroken();
        $this->assertEquals(12, $this->getModelClass()::find($this->ids[8])->getLft());
    }
}

<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class NodeTestBase extends \Orchestra\Testbench\TestCase
{
    abstract protected static function getTableName(): string;

    abstract protected static function getModelClass(): string;

    abstract protected static function createTable(\Illuminate\Database\Schema\Blueprint $table): void;

    protected array $ids = [];
    protected CategoryData $categoryData;

    protected function getPackageProviders($app)
    {
        return [\Aimeos\Nestedset\NestedSetServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => env('DB_DRIVER', 'sqlite'),
            'host'     => env('DB_HOST', ''),
            'port'     => env('DB_PORT', ''),
            'database' => env('DB_DATABASE', ':memory:'),
            'username' => env('DB_USERNAME', ''),
            'password' => env('DB_PASSWORD', ''),
            'prefix'   => 'prfx_',
        ]);
    }

    public function setUp(): void
    {
        parent::setUp();

        $table = static::getTableName();

        Schema::dropIfExists($table);
        Schema::create($table, function (\Illuminate\Database\Schema\Blueprint $table) {
            static::createTable($table);
        });

        DB::enableQueryLog();

        date_default_timezone_set('Europe/Berlin');

        $this->ids = $this->categoryData->getIds();
        $this->seedTable(static::getTableName(), $this->categoryData->getData());
        $this->ids = $this->refreshIds(static::getTableName(), $this->ids);

        DB::flushQueryLog();

        $modelClass = static::getModelClass();
        $modelClass::resetActionsPerformed();
    }

    public function tearDown(): void
    {
        DB::table(static::getTableName())->delete();

        parent::tearDown();
    }

    protected function refreshIds(string $table, array $ids): array
    {
        $dbIds = DB::table($table)->pluck('id')->all();
        $map = [];

        foreach ($dbIds as $dbId) {
            foreach ($ids as $key => $id) {
                if (strcasecmp((string) $id, (string) $dbId) === 0) {
                    $map[$key] = $dbId;
                    break;
                }
            }
        }

        return $map + $ids;
    }

    protected function assertNodeReceivesValidValues($node)
    {
        $lft = $node->getLft();
        $rgt = $node->getRgt();
        $nodeInDb = $this->findCategory($node->name);

        $this->assertEquals(
            [$nodeInDb->getLft(), $nodeInDb->getRgt()],
            [$lft, $rgt],
            'Node is not synced with database after save.'
        );
    }

    protected function assertTreeNotBroken($table = null)
    {
        $table = $table ?? static::getTableName();
        $checks = array();

        $connection = DB::connection();

        $table = $connection->getQueryGrammar()->wrapTable($table);

        // Check if lft and rgt values are ok
        $checks[] = "from $table where _lft >= _rgt or (_rgt - _lft) % 2 = 0";

        // Check if lft and rgt values are unique
        $checks[] = "from $table c1, $table c2 where c1.id <> c2.id and " .
            "(c1._lft=c2._lft or c1._rgt=c2._rgt or c1._lft=c2._rgt or c1._rgt=c2._lft)";

        // Check if parent_id is set correctly
        $checks[] = "from $table c, $table p, $table m where c.parent_id=p.id and m.id <> p.id and m.id <> c.id and " .
            "(c._lft not between p._lft and p._rgt or c._lft between m._lft and m._rgt and m._lft between p._lft and p._rgt)";

        foreach ($checks as $i => $check) {
            $checks[$i] = 'select 1 as error ' . $check;
        }

        $sql = 'select max(error) as errors from (' . implode(' union ', $checks) . ') _';

        $actual = $connection->selectOne($sql);

        $this->assertEquals(null, $actual->errors, "The tree structure of $table is broken!");
        $actual = (array)DB::connection()->selectOne($sql);

        $this->assertEquals(array('errors' => null), $actual, "The tree structure of $table is broken!");
    }

    /**
     * @param $name
     *
     * @return \Category
     */
    protected function findCategory($name, $withTrashed = false)
    {
        $modelClass = static::getModelClass();
        $q = new $modelClass;

        $q = $withTrashed ? $q->withTrashed() : $q->newQuery();

        return $q->whereName($name)->first();
    }

    protected function dumpTree($items = null)
    {
        if (!$items) $items = static::getModelClass()::withTrashed()->defaultOrder()->get();

        foreach ($items as $item) {
            echo PHP_EOL . ($item->trashed() ? '-' : '+') . ' ' . $item->name . " " . $item->getKey() . ' ' . $item->getLft() . " " . $item->getRgt() . ' ' . $item->getParentId();
        }
    }

    protected function nodeValues($node)
    {
        return array($node->_lft, $node->_rgt, $node->parent_id, $node->depth);
    }

    public function testTreeNotBroken()
    {
        $this->assertTreeNotBroken();
        $this->assertFalse(static::getModelClass()::isBroken());
    }

    public function testGetsNodeData()
    {
        $data = static::getModelClass()::getNodeData($this->ids[3]);

        $this->assertEquals(['_lft' => 3, '_rgt' => 4, 'depth' => 2], $data);
    }

    public function testGetsPlainNodeData()
    {
        $data = static::getModelClass()::getPlainNodeData($this->ids[3]);

        $this->assertEquals([3, 4], $data);
    }

    public function testReceivesValidValuesWhenAppendedTo()
    {
        $model = static::getModelClass();
        $node = new $model(['name' => 'test']);
        $root = static::getModelClass()::root();

        $accepted = array($root->_rgt, $root->_rgt + 1, $root->id, $root->depth + 1);

        $root->appendNode($node);

        $this->assertTrue($node->hasMoved());
        $this->assertEquals($accepted, $this->nodeValues($node));
        $this->assertTreeNotBroken();
        $this->assertFalse($node->isDirty());
        $this->assertTrue($node->isDescendantOf($root));
    }

    public function testReceivesValidValuesWhenPrependedTo()
    {
        $root = static::getModelClass()::root();
        $model = static::getModelClass();
        $node = new $model(['name' => 'test']);
        $root->prependNode($node);

        $this->assertTrue($node->hasMoved());
        $this->assertEquals(array($root->_lft + 1, $root->_lft + 2, $root->id, $root->depth + 1), $this->nodeValues($node));
        $this->assertTreeNotBroken();
        $this->assertTrue($node->isDescendantOf($root));
        $this->assertTrue($root->isAncestorOf($node));
        $this->assertTrue($node->isChildOf($root));
    }

    public function testReceivesValidValuesWhenInsertedAfter()
    {
        $target = $this->findCategory('apple');
        $model = static::getModelClass();
        $node = new $model(['name' => 'test']);
        $node->afterNode($target)->save();

        $this->assertTrue($node->hasMoved());
        $this->assertEquals(array($target->_rgt + 1, $target->_rgt + 2, $target->parent->id, $target->depth), $this->nodeValues($node));
        $this->assertTreeNotBroken();
        $this->assertFalse($node->isDirty());
        $this->assertTrue($node->isSiblingOf($target));
    }

    public function testReceivesValidValuesWhenInsertedBefore()
    {
        $target = $this->findCategory('apple');
        $model = static::getModelClass();
        $node = new $model(['name' => 'test']);
        $node->beforeNode($target)->save();

        $this->assertTrue($node->hasMoved());
        $this->assertEquals(array($target->_lft, $target->_lft + 1, $target->parent->id, $target->depth), $this->nodeValues($node));
        $this->assertTreeNotBroken();
    }

    public function testCategoryMoveLevelUp()
    {
        $node = $this->findCategory('galaxy');
        $target = $this->findCategory('notebooks');

        $this->assertEquals(1, $target->getDepth());
        $this->assertEquals(3, $node->getDepth());

        $target->appendNode($node);

        $this->assertTrue($node->hasMoved());
        $this->assertNodeReceivesValidValues($node);
        $this->assertTreeNotBroken();

        $this->assertEquals(1, $target->getDepth());
        $this->assertEquals(2, $node->getDepth());
    }

    public function testCategoryMoveLevelSame()
    {
        $node = $this->findCategory('apple');
        $target = $this->findCategory('notebooks');

        $this->assertEquals(1, $target->getDepth());
        $this->assertEquals(2, $node->getDepth());

        $target->appendNode($node);

        $this->assertTrue($node->hasMoved());
        $this->assertTreeNotBroken();
        $this->assertNodeReceivesValidValues($node);

        $this->assertEquals(1, $target->getDepth());
        $this->assertEquals(2, $node->getDepth());
    }

    public function testCategoryMoveLevelDown()
    {
        $node = $this->findCategory('apple');
        $target = $this->findCategory('samsung');

        $this->assertEquals(2, $target->getDepth());
        $this->assertEquals(2, $node->getDepth());

        $target->appendNode($node);

        $this->assertTrue($node->hasMoved());
        $this->assertTreeNotBroken();
        $this->assertNodeReceivesValidValues($node);

        $this->assertEquals(2, $target->getDepth());
        $this->assertEquals(3, $node->getDepth());
    }

    public function testCategoryMoveBeforeUp()
    {
        $node = $this->findCategory('galaxy');
        $target = $this->findCategory('apple');

        $this->assertEquals(2, $target->getDepth());
        $this->assertEquals(3, $node->getDepth());

        $node->insertBeforeNode($target);

        $this->assertTrue($node->hasMoved());
        $this->assertTreeNotBroken();
        $this->assertNodeReceivesValidValues($node);

        $this->assertEquals(2, $target->getDepth());
        $this->assertEquals(2, $node->getDepth());
    }

    public function testCategoryMoveBeforeSame()
    {
        $node = $this->findCategory('apple');
        $target = $this->findCategory('samsung');

        $this->assertEquals(2, $target->getDepth());
        $this->assertEquals(2, $node->getDepth());

        $node->insertBeforeNode($target);

        $this->assertTrue($node->hasMoved());
        $this->assertTreeNotBroken();
        $this->assertNodeReceivesValidValues($node);

        $this->assertEquals(2, $target->getDepth());
        $this->assertEquals(2, $node->getDepth());
    }

    public function testCategoryMoveBeforeDown()
    {
        $node = $this->findCategory('apple');
        $target = $this->findCategory('galaxy');

        $this->assertEquals(3, $target->getDepth());
        $this->assertEquals(2, $node->getDepth());

        $node->insertBeforeNode($target);

        $this->assertTrue($node->hasMoved());
        $this->assertTreeNotBroken();
        $this->assertNodeReceivesValidValues($node);

        $this->assertEquals(3, $target->getDepth());
        $this->assertEquals(3, $node->getDepth());
    }

    public function testFailsToInsertIntoChild()
    {
        $this->expectException(Exception::class);

        $node = $this->findCategory('notebooks');
        $target = $node->children()->first();

        $node->afterNode($target)->save();
    }

    public function testFailsToAppendIntoItself()
    {
        $this->expectException(Exception::class);

        $node = $this->findCategory('notebooks');

        $node->appendToNode($node)->save();
    }

    public function testFailsToPrependIntoItself()
    {
        $this->expectException(Exception::class);

        $node = $this->findCategory('notebooks');

        $node->prependTo($node)->save();
    }

    public function testWithoutRootWorks()
    {
        $result = static::getModelClass()::withoutRoot()->pluck('name');

        $this->assertNotEquals('store', $result);
    }

    public function testAncestorsReturnsAncestorsWithoutNodeItself()
    {
        $node = $this->findCategory('apple');
        $path = $node->ancestors()->pluck('name')->all();

        $this->assertEquals(array('store', 'notebooks'), $path);
    }

    public function testGetsAncestorsByStatic()
    {
        $path = static::getModelClass()::ancestorsOf($this->ids[3])->pluck('name')->all();

        $this->assertEquals(array('store', 'notebooks'), $path);
    }

    public function testGetsAncestorsDirect()
    {
        $path = static::getModelClass()::find($this->ids[8])->getAncestors()->pluck('id')->all();

        $this->assertEquals(array($this->ids[1], $this->ids[5], $this->ids[7]), $path);
    }

    public function testDescendants()
    {
        $node = $this->findCategory('mobile');
        $descendants = $node->descendants()->pluck('name')->all();
        $expected = array('nokia', 'samsung', 'galaxy', 'sony', 'lenovo');

        $this->assertEquals($expected, $descendants);

        $descendants = $node->getDescendants()->pluck('name')->all();

        $this->assertEquals(count($descendants), $node->getDescendantCount());
        $this->assertEquals($expected, $descendants);

        $descendants = static::getModelClass()::descendantsAndSelf($this->ids[7])->pluck('name')->all();
        $expected = ['samsung', 'galaxy'];

        $this->assertEquals($expected, $descendants);
    }

    public function testWithDepthWorks()
    {
        $nodes = static::getModelClass()::withDepth()->limit(4)->pluck('depth')->all();

        $this->assertEquals(array(0, 1, 2, 2), $nodes);
    }

    public function testWithDepthUsesStoredDepthColumnWhenAvailable()
    {
        $sql = strtolower(static::getModelClass()::withDepth()->toSql());

        $this->assertStringNotContainsString('count(1)', $sql);
        $this->assertStringContainsString('depth', $sql);
    }

    public function testWithDepthWithCustomKeyWorks()
    {
        $node = static::getModelClass()::whereIsRoot()->withDepth('level')->first();

        $this->assertTrue(isset($node['level']));
    }

    public function testWithDepthWorksAlongWithDefaultKeys()
    {
        $node = static::getModelClass()::withDepth()->first();

        $this->assertTrue(isset($node->name));
    }

    public function testParentIdAttributeAccessorAppendsNode()
    {
        $model = static::getModelClass();
        $node = new $model(array('name' => 'lg', 'parent_id' => $this->ids[5]));
        $node->save();

        $this->assertEquals($this->ids[5], $node->parent_id);
        $this->assertEquals($this->ids[5], $node->getParentId());

        $node->parent_id = null;
        $node->save();

        $node->refreshNode();

        $this->assertEquals(null, $node->parent_id);
        $this->assertTrue($node->isRoot());
    }

    public function testFailsToSaveNodeUntilNotInserted()
    {
        $this->expectException(Exception::class);

        $modelClass = static::getModelClass();
        $node = new $modelClass();
        $node->save();
    }

    public function testNodeIsDeletedWithDescendants()
    {
        $node = $this->findCategory('mobile');
        $node->forceDelete();

        $this->assertTreeNotBroken();

        $nodes = static::getModelClass()::whereIn('id', array($this->ids[5], $this->ids[6], $this->ids[7], $this->ids[8], $this->ids[9]))->count();
        $this->assertEquals(0, $nodes);

        $root = static::getModelClass()::root();
        $this->assertEquals(8, $root->getRgt());
    }

    public function testNodeIsSoftDeleted()
    {
        $root = static::getModelClass()::root();

        $samsung = $this->findCategory('samsung');
        $samsung->delete();

        $this->assertTreeNotBroken();

        $this->assertNull($this->findCategory('galaxy'));

        sleep(1);

        $node = $this->findCategory('mobile');
        $node->delete();

        $nodes = static::getModelClass()::whereIn('id', array($this->ids[5], $this->ids[6], $this->ids[7], $this->ids[8], $this->ids[9]))->count();
        $this->assertEquals(0, $nodes);

        $originalRgt = $root->getRgt();
        $root->refreshNode();

        $this->assertEquals($originalRgt, $root->getRgt());

        $node = $this->findCategory('mobile', true);

        $node->restore();

        $this->assertNull($this->findCategory('samsung'));
        $this->assertNotNull($this->findCategory('nokia'));
    }

    public function testSoftDeletedNodeisDeletedWhenParentIsDeleted()
    {
        $this->findCategory('samsung')->delete();

        $this->findCategory('mobile')->forceDelete();

        $this->assertTreeNotBroken();

        $this->assertNull($this->findCategory('samsung', true));
        $this->assertNull($this->findCategory('sony'));
    }

    public function testFailsToSaveNodeUntilParentIsSaved()
    {
        $this->expectException(Exception::class);

        $modelClass = static::getModelClass();
        $node = new $modelClass(array('title' => 'Node'));
        $parent = new $modelClass(array('title' => 'Parent'));

        $node->appendTo($parent)->save();
    }

    public function testSiblings()
    {
        $node = $this->findCategory('samsung');
        $siblings = $node->siblings()->pluck('id')->all();
        $next = $node->nextSiblings()->pluck('id')->all();
        $prev = $node->prevSiblings()->pluck('id')->all();

        $this->assertEquals(array($this->ids[6], $this->ids[9], $this->ids[10]), $siblings);
        $this->assertEquals(array($this->ids[9], $this->ids[10]), $next);
        $this->assertEquals(array($this->ids[6]), $prev);

        $siblings = $node->getSiblings()->pluck('id')->all();
        $next = $node->getNextSiblings()->pluck('id')->all();
        $prev = $node->getPrevSiblings()->pluck('id')->all();

        $this->assertEquals(array($this->ids[6], $this->ids[9], $this->ids[10]), $siblings);
        $this->assertEquals(array($this->ids[9], $this->ids[10]), $next);
        $this->assertEquals(array($this->ids[6]), $prev);

        $next = $node->getNextSibling();
        $prev = $node->getPrevSibling();

        $this->assertEquals($this->ids[9], $next->id);
        $this->assertEquals($this->ids[6], $prev->id);
    }

    public function testFetchesReversed()
    {
        $node = $this->findCategory('sony');
        $siblings = $node->prevSiblings()->reversed()->value('id');

        $this->assertEquals($this->ids[7], $siblings);
    }

    public function testToTreeBuildsWithDefaultOrder()
    {
        $tree = static::getModelClass()::whereBetween('_lft', array(8, 17))->defaultOrder()->get()->toTree();

        $this->assertEquals(1, count($tree));

        $root = $tree->first();
        $this->assertEquals('mobile', $root->name);
        $this->assertEquals(4, count($root->children));
    }

    public function testToTreeKeepsEmptyChildrenRelationOnLeaves()
    {
        $tree = static::getModelClass()::defaultOrder()->get()->toTree();
        $notebooks = $tree->first()->children->firstWhere('name', 'notebooks');
        $apple = $notebooks->children->firstWhere('name', 'apple');

        $this->assertTrue($apple->relationLoaded('children'));
        $this->assertEquals(0, $apple->children->count());
    }

    public function testToTreeBuildsWithCustomOrder()
    {
        $tree = static::getModelClass()::whereBetween('_lft', array(8, 17))
            ->orderBy('name')
            ->get()
            ->toTree();

        $this->assertEquals(1, count($tree));

        $root = $tree->first();
        $this->assertEquals('mobile', $root->name);
        $this->assertEquals(4, count($root->children));
        $this->assertEquals($root->getAttributes(), $root->children->first()->parent->getAttributes());
    }

    public function testLinkNodesKeepsCollectionOrderForChildren()
    {
        $nodes = static::getModelClass()::whereIn('id', [
            $this->ids[5],
            $this->ids[6],
            $this->ids[9],
            $this->ids[10],
        ])->orderBy('name')->get();

        $nodes->linkNodes();

        $mobile = $nodes->find($this->ids[5]);
        $lenovo = $nodes->find($this->ids[10]);

        $this->assertEquals(['lenovo', 'nokia', 'sony'], $mobile->children->pluck('name')->all());
        $this->assertEquals($mobile->getAttributes(), $lenovo->parent->getAttributes());
    }

    public function testToTreeWithSpecifiedRoot()
    {
        $node = $this->findCategory('mobile');
        $nodes = static::getModelClass()::whereBetween('_lft', array(8, 17))->get();

        $tree1 = \Aimeos\Nestedset\Collection::make($nodes)->toTree($this->ids[5]);
        $tree2 = \Aimeos\Nestedset\Collection::make($nodes)->toTree($node);

        $this->assertEquals(4, $tree1->count());
        $this->assertEquals(4, $tree2->count());
    }

    public function testToTreeBuildsWithDefaultOrderAndMultipleRootNodes()
    {
        $tree = static::getModelClass()::withoutRoot()->get()->toTree();

        $this->assertEquals(2, count($tree));
    }

    public function testToTreeBuildsWithRootItemIdProvided()
    {
        $tree = static::getModelClass()::whereBetween('_lft', array(8, 17))->get()->toTree($this->ids[5]);

        $this->assertEquals(4, count($tree));

        $root = $tree[1];
        $this->assertEquals('samsung', $root->name);
        $this->assertEquals(1, count($root->children));
    }

    public function testRetrievesNextNode()
    {
        $node = $this->findCategory('apple');
        $next = $node->nextNodes()->first();

        $this->assertEquals('lenovo', $next->name);
    }

    public function testRetrievesPrevNode()
    {
        $node = $this->findCategory('apple');
        $next = $node->getPrevNode();

        $this->assertEquals('notebooks', $next->name);
    }

    public function testMultipleAppendageWorks()
    {
        $parent = $this->findCategory('mobile');

        $model = static::getModelClass();
        $child = new $model(['name' => 'test']);

        $parent->appendNode($child);

        $model = static::getModelClass();
        $child->appendNode(new $model(['name' => 'sub']));

        $parent->appendNode(new $model(['name' => 'test2']));

        $this->assertTreeNotBroken();
    }

    public function testDefaultCategoryIsSavedAsRoot()
    {
        $model = static::getModelClass();
        $node = new $model(['name' => 'test']);
        $node->save();

        $this->assertEquals(23, $node->_lft);
        $this->assertTreeNotBroken();

        $this->assertTrue($node->isRoot());
    }

    public function testExistingCategorySavedAsRoot()
    {
        $node = $this->findCategory('apple');
        $node->saveAsRoot();

        $this->assertTreeNotBroken();
        $this->assertTrue($node->isRoot());
    }

    public function testNodeMovesDownSeveralPositions()
    {
        $node = $this->findCategory('nokia');

        $this->assertTrue($node->down(2));

        $this->assertEquals($node->_lft, 15);
    }

    public function testNodeMovesUpSeveralPositions()
    {
        $node = $this->findCategory('sony');

        $this->assertTrue($node->up(2));

        $this->assertEquals($node->_lft, 9);
    }

    public function testCountsTreeErrors()
    {
        $errors = static::getModelClass()::countErrors();

        $this->assertEquals(['oddness' => 0,
            'duplicates' => 0,
            'wrong_parent' => 0,
            'missing_parent' => 0], $errors);

        static::getModelClass()::where('id', '=', $this->ids[5])->update(['_lft' => 14]);
        static::getModelClass()::where('id', '=', $this->ids[8])->update(['parent_id' => $this->ids[2]]);
        static::getModelClass()::where('id', '=', $this->ids[11])->update(['_lft' => 20]);
        static::getModelClass()::where('id', '=', $this->ids[4])->update(['parent_id' => $this->ids[24]]);

        $errors = static::getModelClass()::countErrors();

        $this->assertEquals(1, $errors['oddness']);
        $this->assertEquals(2, $errors['duplicates']);
        $this->assertEquals(1, $errors['missing_parent']);
    }

    public function testCreatesNode()
    {
        $node = static::getModelClass()::create(['name' => 'test']);

        $this->assertEquals(23, $node->getLft());
    }

    public function testCreatesViaRelationship()
    {
        $node = $this->findCategory('apple');

        $child = $node->children()->create(['name' => 'test']);

        $this->assertTreeNotBroken();
    }

    public function testCreatesTree()
    {
        $node = static::getModelClass()::create(
            [
                'name' => 'test',
                'children' =>
                    [
                        ['name' => 'test2'],
                        ['name' => 'test3'],
                    ],
            ]);

        $this->assertTreeNotBroken();

        $this->assertTrue(isset($node->children));

        $node = $this->findCategory('test');

        $this->assertCount(2, $node->children);
        $this->assertEquals('test2', $node->children[0]->name);
    }

    public function testDescendantsOfNonExistingNode()
    {
        $modelClass = static::getModelClass();
        $node = new $modelClass();

        $this->assertTrue($node->getDescendants()->isEmpty());
    }

    public function testWhereDescendantsOf()
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        static::getModelClass()::whereDescendantOf($this->ids[124])->get();
    }

    public function testAncestorsByNode()
    {
        $category = $this->findCategory('apple');
        $ancestors = static::getModelClass()::whereAncestorOf($category)->pluck('id')->all();

        $this->assertEquals([$this->ids[1], $this->ids[2]], $ancestors);
    }

    public function testDescendantsByNode()
    {
        $category = $this->findCategory('notebooks');
        $res = static::getModelClass()::whereDescendantOf($category)->pluck('id')->all();

        $this->assertEquals([$this->ids[3], $this->ids[4]], $res);
    }

    public function testMultipleDeletionsDoNotBrakeTree()
    {
        $category = $this->findCategory('mobile');

        foreach ($category->children()->take(2)->get() as $child) {
            $child->forceDelete();
        }

        $this->assertTreeNotBroken();
    }

    public function testTreeIsFixed()
    {
        static::getModelClass()::where('id', '=', $this->ids[5])->update(['_lft' => 14]);
        static::getModelClass()::where('id', '=', $this->ids[8])->update(['parent_id' => $this->ids[2]]);
        static::getModelClass()::where('id', '=', $this->ids[11])->update(['_lft' => 20]);
        static::getModelClass()::where('id', '=', $this->ids[2])->update(['parent_id' => $this->ids[24]]);

        $fixed = static::getModelClass()::fixTree();

        $this->assertTrue($fixed > 0);
        $this->assertTreeNotBroken();

        $node = static::getModelClass()::find($this->ids[8]);

        $this->assertEquals($this->ids[2], $node->getParentId());

        $node = static::getModelClass()::find($this->ids[2]);

        $this->assertEquals(null, $node->getParentId());
    }

    public function testFixTreeRepairsBranchingParentBuckets()
    {
        static::getModelClass()::query()->update([
            '_lft' => 0,
            '_rgt' => 0,
            'depth' => 0,
        ]);

        $fixed = static::getModelClass()::fixTree();

        $this->assertEquals(11, $fixed);
        $this->assertTreeNotBroken();

        $galaxy = static::getModelClass()::find($this->ids[8]);

        $this->assertEquals(3, $galaxy->getDepth());
        $this->assertEquals([$this->ids[1], $this->ids[5], $this->ids[7]], $galaxy->getAncestors()->pluck('id')->all());
    }

    public function testSubtreeIsFixed()
    {
        static::getModelClass()::where('id', '=', $this->ids[8])->update(['_lft' => 11]);

        $fixed = static::getModelClass()::fixSubtree(static::getModelClass()::find($this->ids[5]));
        $this->assertEquals(1, $fixed);
        $this->assertTreeNotBroken();
        $this->assertEquals(12, static::getModelClass()::find($this->ids[8])->getLft());
    }

    public function testParentIdDirtiness()
    {
        $node = $this->findCategory('apple');
        $node->parent_id = $this->ids[5];

        $this->assertTrue($node->isDirty('parent_id'));

        $node = $this->findCategory('apple');
        $node->parent_id = null;

        $this->assertTrue($node->isDirty('parent_id'));
    }

    public function testIsDirtyMovement()
    {
        $node = $this->findCategory('apple');
        $otherNode = $this->findCategory('samsung');

        $this->assertFalse($node->isDirty());

        $node->afterNode($otherNode);

        $this->assertTrue($node->isDirty());

        $node = $this->findCategory('apple');
        $otherNode = $this->findCategory('samsung');

        $this->assertFalse($node->isDirty());

        $node->appendToNode($otherNode);

        $this->assertTrue($node->isDirty());
    }

    public function testRootNodesMoving()
    {
        $node = $this->findCategory('store');
        $node->down();

        $this->assertEquals(3, $node->getLft());
    }

    public function testDescendantsRelation()
    {
        $node = $this->findCategory('notebooks');
        $result = $node->descendants;

        $this->assertEquals(2, $result->count());
        $this->assertEquals('apple', $result->first()->name);
    }

    public function testDescendantsEagerlyLoaded()
    {
        $nodes = static::getModelClass()::whereIn('id', [$this->ids[2], $this->ids[5]])->get();

        $nodes->load('descendants');

        $this->assertEquals(2, $nodes->count());
        $this->assertTrue($nodes->first()->relationLoaded('descendants'));
    }

    public function testNestedDescendantEagerLoadConstraintsAreDeduplicated()
    {
        $nodes = static::getModelClass()::whereIn('id', [$this->ids[1], $this->ids[5]])
            ->defaultOrder()
            ->get();

        DB::flushQueryLog();

        $nodes->load('descendants');

        $queries = DB::connection()->getQueryLog();
        $relationQuery = strtolower(end($queries)['query']);

        $this->assertEquals(1, substr_count($relationQuery, ' between '));
        $this->assertEquals(9, $nodes->find($this->ids[1])->descendants->count());
        $this->assertEquals(5, $nodes->find($this->ids[5])->descendants->count());
        $this->assertTrue($nodes->find($this->ids[5])->descendants->contains('id', $this->ids[8]));
    }

    public function testDisjointDescendantEagerLoadConstraintsAreRetained()
    {
        $nodes = static::getModelClass()::whereIn('id', [$this->ids[2], $this->ids[5]])
            ->defaultOrder()
            ->get();

        DB::flushQueryLog();

        $nodes->load('descendants');

        $queries = DB::connection()->getQueryLog();
        $relationQuery = strtolower(end($queries)['query']);

        $this->assertEquals(2, substr_count($relationQuery, ' between '));
        $this->assertEquals([$this->ids[3], $this->ids[4]], $nodes->find($this->ids[2])->descendants->pluck('id')->all());
        $this->assertTrue($nodes->find($this->ids[5])->descendants->contains('id', $this->ids[8]));
    }

    public function testIndexedDescendantEagerMatchingPreservesResultOrder()
    {
        $nodes = static::getModelClass()::whereIn('id', [$this->ids[1], $this->ids[5]])
            ->defaultOrder()
            ->get();

        $nodes->load(['descendants' => function ($query) {
            $query->orderBy('name');
        }]);

        $this->assertEquals(
            ['galaxy', 'lenovo', 'nokia', 'samsung', 'sony'],
            $nodes->find($this->ids[5])->descendants->pluck('name')->all()
        );
    }

    public function testIndexedAncestorEagerMatchingPreservesResultOrder()
    {
        $nodes = static::getModelClass()::whereIn('id', [$this->ids[8]])
            ->defaultOrder()
            ->get();

        $nodes->load(['ancestors' => function ($query) {
            $query->reorder('name');
        }]);

        $this->assertEquals(
            ['mobile', 'samsung', 'store'],
            $nodes->find($this->ids[8])->ancestors->pluck('name')->all()
        );
    }

    public function testDescendantsRelationQuery()
    {
        $nodes = static::getModelClass()::has('descendants')->whereIn('id', [$this->ids[2], $this->ids[3]])->get();

        $this->assertEquals(1, $nodes->count());
        $this->assertEquals($this->ids[2], $nodes->first()->getKey());

        $nodes = static::getModelClass()::has('descendants', '>', 2)->get();

        $this->assertEquals(2, $nodes->count());
        $this->assertEquals($this->ids[1], $nodes[0]->getKey());
        $this->assertEquals($this->ids[5], $nodes[1]->getKey());
    }

    public function testParentRelationQuery()
    {
        $nodes = static::getModelClass()::has('parent')->whereIn('id', [$this->ids[1], $this->ids[2]]);

        $this->assertEquals(1, $nodes->count());
        $this->assertEquals($this->ids[2], $nodes->first()->getKey());
    }

    public function testSiblingsRelation()
    {
        $node = $this->findCategory('samsung');
        $result = $node->siblings;

        $this->assertEquals(3, $result->count());
        $this->assertEquals([$this->ids[6], $this->ids[9], $this->ids[10]], $result->pluck('id')->all());
    }

    public function testSiblingsAndSelfRelation()
    {
        $node = $this->findCategory('samsung');
        $result = $node->siblingsAndSelf;

        $this->assertEquals(4, $result->count());
        $this->assertEquals(
            [$this->ids[6], $this->ids[7], $this->ids[9], $this->ids[10]],
            $result->pluck('id')->all()
        );
    }

    public function testSiblingsEagerlyLoaded()
    {
        $nodes = static::getModelClass()::whereIn('id', [$this->ids[2], $this->ids[5]])->get();

        $nodes->load('siblings');

        $this->assertEquals(2, $nodes->count());
        $this->assertTrue($nodes->first()->relationLoaded('siblings'));
    }

    public function testSiblingEagerLoadUsesParentIdSetConstraint()
    {
        $nodes = static::getModelClass()::whereIn('id', [$this->ids[3], $this->ids[7]])
            ->defaultOrder()
            ->get();

        DB::flushQueryLog();

        $nodes->load('siblings');

        $queries = DB::connection()->getQueryLog();
        $relationQuery = strtolower(end($queries)['query']);

        $this->assertStringContainsString(' in ', $relationQuery);
        $this->assertStringNotContainsString(' or ', $relationQuery);
        $this->assertEquals([$this->ids[4]], $nodes->find($this->ids[3])->siblings->pluck('id')->all());
        $this->assertEquals([$this->ids[6], $this->ids[9], $this->ids[10]], $nodes->find($this->ids[7])->siblings->pluck('id')->all());
    }

    public function testRootSiblingEagerLoadUsesNullParentConstraint()
    {
        $nodes = static::getModelClass()::whereIn('id', [$this->ids[1], $this->ids[11]])
            ->defaultOrder()
            ->get();

        DB::flushQueryLog();

        $nodes->load('siblings');

        $queries = DB::connection()->getQueryLog();
        $relationQuery = strtolower(end($queries)['query']);

        $this->assertStringContainsString('is null', $relationQuery);
        $this->assertEquals([$this->ids[11]], $nodes->find($this->ids[1])->siblings->pluck('id')->all());
        $this->assertEquals([$this->ids[1]], $nodes->find($this->ids[11])->siblings->pluck('id')->all());
    }

    public function testSiblingsAndSelfEagerlyLoaded()
    {
        $nodes = static::getModelClass()::whereIn('id', [$this->ids[3], $this->ids[7]])->get();

        $nodes->load('siblingsAndSelf');

        $this->assertEquals(2, $nodes->count());
        $this->assertTrue($nodes->first()->relationLoaded('siblingsAndSelf'));

        // apple (id=3, parent=2) has sibling lenovo (id=4), plus itself = 2
        $this->assertEquals(2, $nodes->first()->siblingsAndSelf->count());
    }

    public function testIndexedSiblingEagerMatchingPreservesResultOrder()
    {
        $nodes = static::getModelClass()::whereIn('id', [
            $this->ids[6],
            $this->ids[7],
            $this->ids[9],
            $this->ids[10],
        ])->defaultOrder()->get();

        $nodes->load(['siblings' => function ($query) {
            $query->orderBy('name');
        }]);

        $this->assertEquals(
            ['lenovo', 'nokia', 'sony'],
            $nodes->find($this->ids[7])->siblings->pluck('name')->all()
        );
    }

    public function testSiblingsRelationQuery()
    {
        // apple (id=3) has 1 sibling, galaxy (id=8) has 0 siblings
        $nodes = static::getModelClass()::has('siblings')->whereIn('id', [$this->ids[3], $this->ids[8]])->get();

        $this->assertEquals(1, $nodes->count());
        $this->assertEquals($this->ids[3], $nodes->first()->getKey());

        // nodes with more than 2 siblings: nokia(6), samsung(7), sony(9), lenovo(10) each have 3 siblings
        $nodes = static::getModelClass()::has('siblings', '>', 2)->get();

        $this->assertEquals(4, $nodes->count());
    }

    public function testSiblingsOfRootNode()
    {
        $node = $this->findCategory('store');
        $result = $node->siblings;

        $this->assertEquals(1, $result->count());
        $this->assertEquals($this->ids[11], $result->first()->getKey());
    }

    public function testRebuildTree()
    {
        $fixed = static::getModelClass()::rebuildTree([
            [
                'id' => $this->ids[1],
                'children' => [
                    ['id' => $this->ids[10]],
                    ['id' => $this->ids[3], 'name' => 'apple v2', 'children' => [['name' => 'new node']]],
                    ['id' => $this->ids[2]],

                ]
            ]
        ]);

        $this->assertTrue($fixed > 0);
        $this->assertTreeNotBroken();

        $node = static::getModelClass()::find($this->ids[3]);

        $this->assertEquals($this->ids[1], $node->getParentId());
        $this->assertEquals('apple v2', $node->name);
        $this->assertEquals(4, $node->getLft());

        $node = $this->findCategory('new node');

        $this->assertNotNull($node);
        $this->assertEquals($this->ids[3], $node->getParentId());
    }

    public function testRebuildSubtree()
    {
        $fixed = static::getModelClass()::rebuildSubtree(static::getModelClass()::find($this->ids[7]), [
            ['name' => 'new node'],
            ['id' => strval($this->ids[8])],
        ]);

        $this->assertTrue($fixed > 0);
        $this->assertTreeNotBroken();

        $node = $this->findCategory('new node');

        $this->assertNotNull($node);
        $this->assertEquals($node->getLft(), 12);
    }

    public function testRebuildTreeWithDeletion()
    {
        static::getModelClass()::rebuildTree([['name' => 'all deleted']], true);

        $this->assertTreeNotBroken();

        $nodes = static::getModelClass()::get();

        $this->assertEquals(1, $nodes->count());
        $this->assertEquals('all deleted', $nodes->first()->name);

        $nodes = static::getModelClass()::withTrashed()->get();

        $this->assertTrue($nodes->count() > 1);
    }

    public function testRebuildFailsWithInvalidPK()
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        static::getModelClass()::rebuildTree([['id' => $this->ids[24]]]);
    }

    public function testFlatTree()
    {
        $node = $this->findCategory('mobile');
        $tree = $node->descendants()->orderBy('name')->get()->toFlatTree();

        $this->assertCount(5, $tree);
        $this->assertEquals('samsung', $tree[2]->name);
        $this->assertEquals('galaxy', $tree[3]->name);
    }

    public function testWhereIsLeaf()
    {
        $categories = static::getModelClass()::leaves();

        $this->assertEquals(7, $categories->count());
        $this->assertEquals('apple', $categories->first()->name);
        $this->assertTrue($categories->first()->isLeaf());

        $category = static::getModelClass()::whereIsRoot()->first();

        $this->assertFalse($category->isLeaf());
    }

    public function testEagerLoadAncestors()
    {
        $queryLogCount = count(DB::connection()->getQueryLog());
        $categories = static::getModelClass()::with('ancestors')->orderBy('name')->get();

        $this->assertEquals($queryLogCount + 2, count(DB::connection()->getQueryLog()));


        $expectedShape = [
            'apple (' . $this->ids[3] . ')}' => 'store (' . $this->ids[1] . ') > notebooks (' . $this->ids[2] . ')',
            'galaxy (' . $this->ids[8] . ')}' => 'store (' . $this->ids[1] . ') > mobile (' . $this->ids[5] . ') > samsung (' . $this->ids[7] . ')',
            'lenovo (' . $this->ids[4] . ')}' => 'store (' . $this->ids[1] . ') > notebooks (' . $this->ids[2] . ')',
            'lenovo (' . $this->ids[10] . ')}' => 'store (' . $this->ids[1] . ') > mobile (' . $this->ids[5] . ')',
            'mobile (' . $this->ids[5] . ')}' => 'store (' . $this->ids[1] . ')',
            'nokia (' . $this->ids[6] . ')}' => 'store (' . $this->ids[1] . ') > mobile (' . $this->ids[5] . ')',
            'notebooks (' . $this->ids[2] . ')}' => 'store (' . $this->ids[1] . ')',
            'samsung (' . $this->ids[7] . ')}' => 'store (' . $this->ids[1] . ') > mobile (' . $this->ids[5] . ')',
            'sony (' . $this->ids[9] . ')}' => 'store (' . $this->ids[1] . ') > mobile (' . $this->ids[5] . ')',
            'store (' . $this->ids[1] . ')}' => '',
            'store_2 (' . $this->ids[11] . ')}' => ''
        ];

        $output = [];

        foreach ($categories as $category) {
            $output["{$category->name} ({$category->id})}"] = $category->ancestors->count()
                ? implode(' > ', $category->ancestors->map(function ($cat) {
                    return "{$cat->name} ({$cat->id})";
                })->toArray())
                : '';
        }

        $this->assertEquals($expectedShape, $output);
    }

    public function testNestedAncestorEagerLoadConstraintsAreDeduplicated()
    {
        $nodes = static::getModelClass()::whereIn('id', [$this->ids[5], $this->ids[8]])
            ->defaultOrder()
            ->get();

        DB::flushQueryLog();

        $nodes->load('ancestors');

        $queries = DB::connection()->getQueryLog();
        $relationQuery = strtolower(end($queries)['query']);

        $this->assertEquals(1, substr_count($relationQuery, ' between '));
        $this->assertEquals(['store'], $nodes->find($this->ids[5])->ancestors->pluck('name')->all());
        $this->assertEquals(['store', 'mobile', 'samsung'], $nodes->find($this->ids[8])->ancestors->pluck('name')->all());
    }

    public function testDisjointAncestorEagerLoadConstraintsAreRetained()
    {
        $nodes = static::getModelClass()::whereIn('id', [$this->ids[3], $this->ids[8]])
            ->defaultOrder()
            ->get();

        DB::flushQueryLog();

        $nodes->load('ancestors');

        $queries = DB::connection()->getQueryLog();
        $relationQuery = strtolower(end($queries)['query']);

        $this->assertEquals(2, substr_count($relationQuery, ' between '));
        $this->assertEquals(['store', 'notebooks'], $nodes->find($this->ids[3])->ancestors->pluck('name')->all());
        $this->assertEquals(['store', 'mobile', 'samsung'], $nodes->find($this->ids[8])->ancestors->pluck('name')->all());
    }

    public function testLazyLoadAncestors()
    {
        $queryLogCount = count(DB::connection()->getQueryLog());
        $categories = static::getModelClass()::orderBy('name')->get();

        $this->assertEquals($queryLogCount + 1, count(DB::connection()->getQueryLog()));

        $expectedShape = [
            'apple (' . $this->ids[3] . ')}' => 'store (' . $this->ids[1] . ') > notebooks (' . $this->ids[2] . ')',
            'galaxy (' . $this->ids[8] . ')}' => 'store (' . $this->ids[1] . ') > mobile (' . $this->ids[5] . ') > samsung (' . $this->ids[7] . ')',
            'lenovo (' . $this->ids[4] . ')}' => 'store (' . $this->ids[1] . ') > notebooks (' . $this->ids[2] . ')',
            'lenovo (' . $this->ids[10] . ')}' => 'store (' . $this->ids[1] . ') > mobile (' . $this->ids[5] . ')',
            'mobile (' . $this->ids[5] . ')}' => 'store (' . $this->ids[1] . ')',
            'nokia (' . $this->ids[6] . ')}' => 'store (' . $this->ids[1] . ') > mobile (' . $this->ids[5] . ')',
            'notebooks (' . $this->ids[2] . ')}' => 'store (' . $this->ids[1] . ')',
            'samsung (' . $this->ids[7] . ')}' => 'store (' . $this->ids[1] . ') > mobile (' . $this->ids[5] . ')',
            'sony (' . $this->ids[9] . ')}' => 'store (' . $this->ids[1] . ') > mobile (' . $this->ids[5] . ')',
            'store (' . $this->ids[1] . ')}' => '',
            'store_2 (' . $this->ids[11] . ')}' => ''
        ];

        $output = [];

        foreach ($categories as $category) {
            $output["{$category->name} ({$category->id})}"] = $category->ancestors->count()
                ? implode(' > ', $category->ancestors->map(function ($cat) {
                    return "{$cat->name} ({$cat->id})";
                })->toArray())
                : '';
        }

        // assert that there is number of original query + 1 + number of rows to fulfill the relation
        $this->assertEquals($queryLogCount + 12, count(DB::connection()->getQueryLog()));

        $this->assertEquals($expectedShape, $output);
    }

    public function testWhereHasCountQueryForAncestors()
    {
        $categories = static::getModelClass()::has('ancestors', '>', 2)->pluck('name')->all();

        $this->assertEquals(['galaxy'], $categories);

        $categories = static::getModelClass()::whereHas('ancestors', function ($query) {
            $query->where('id', $this->ids[5]);
        })->pluck('name')->all();

        $this->assertEquals(['nokia', 'samsung', 'galaxy', 'sony', 'lenovo'], $categories);
    }

    public function testMultipleRootNodesAreSiblings()
    {
        $store = $this->findCategory('store');
        $store2 = $this->findCategory('store_2');

        $this->assertTrue($store->isSiblingOf($store2));
        $this->assertTrue($store2->isSiblingOf($store));
    }

    public function testMultipleRootNodesAreNotChildren()
    {
        $store = $this->findCategory('store');
        $store2 = $this->findCategory('store_2');

        $this->assertFalse($store->isChildOf($store2));
        $this->assertFalse($store2->isChildOf($store));
    }

    public function testMultipleRootNodesInToTree()
    {
        $tree = static::getModelClass()::defaultOrder()->get()->toTree();

        $this->assertEquals(2, $tree->count());
        $this->assertEquals('store', $tree->first()->name);
        $this->assertEquals('store_2', $tree->last()->name);
    }

    public function testMultipleRootNodesInToFlatTree()
    {
        $tree = static::getModelClass()::defaultOrder()->get()->toFlatTree();

        $this->assertEquals(11, $tree->count());
        $this->assertEquals('store', $tree->first()->name);
        $this->assertEquals('store_2', $tree->last()->name);
    }

    public function testToFlatTreeHandlesDeepTreeIteratively()
    {
        $modelClass = static::getModelClass();
        $nodes = [];
        $count = 75;

        for ($i = 1; $i <= $count; ++$i) {
            $node = new $modelClass;
            $node->setRawAttributes([
                'id' => $i,
                '_lft' => $i,
                '_rgt' => ($count * 2) - $i + 1,
                'parent_id' => $i === 1 ? null : $i - 1,
                'depth' => $i - 1,
            ], true);

            $nodes[] = $node;
        }

        $tree = \Aimeos\Nestedset\Collection::make($nodes)->toFlatTree();

        $this->assertEquals($count, $tree->count());
        $this->assertEquals(range(1, $count), $tree->pluck('id')->all());
    }

    public function testNewRootNodeIsSiblingOfExisting()
    {
        $model = static::getModelClass();
        $node = new $model(['name' => 'store_3']);
        $node->save();

        $this->assertTreeNotBroken();
        $this->assertTrue($node->isRoot());

        $store = $this->findCategory('store');
        $this->assertTrue($node->isSiblingOf($store));
        $this->assertTrue($store->isSiblingOf($node));
    }

    public function testSetParentIdToNullKeepsRoot()
    {
        $store = $this->findCategory('store');
        $store->parent_id = null;

        $this->assertTrue($store->isRoot());
        $this->assertTreeNotBroken();
    }

    public function testChildIsNotSiblingOfRoot()
    {
        $store = $this->findCategory('store');
        $notebooks = $this->findCategory('notebooks');

        $this->assertFalse($store->isSiblingOf($notebooks));
        $this->assertFalse($notebooks->isSiblingOf($store));
    }

    public function testReplication()
    {
        $category = $this->findCategory('nokia');
        $category = $category->replicate();
        $category->save();
        $category->refreshNode();

        $this->assertNull($category->getParentId());

        $category = $this->findCategory('nokia');
        $category = $category->replicate();
        $category->parent_id = $this->ids[1];
        $category->save();

        $category->refreshNode();

        $this->assertEquals($this->ids[1], $category->getParentId());
    }

    public function testWhereIsRootQualifiesParentId()
    {
        $model = new (static::getModelClass());
        $grammar = $model->getConnection()->getQueryGrammar();
        $expected = $grammar->wrap($model->getTable() . '.' . $model->getParentIdName());

        $rootSql = static::getModelClass()::query()->whereIsRoot()->toSql();
        $this->assertStringContainsString($expected, $rootSql);

        $withoutRootSql = static::getModelClass()::query()->withoutRoot()->toSql();
        $this->assertStringContainsString($expected, $withoutRootSql);
    }

    public function testGetsAncestorsInHierarchicalOrder()
    {
        $node = static::getModelClass()::find($this->ids[8]);

        // The relation query must be explicitly ordered by _lft (root first),
        // not left to incidental database order.
        $sql = strtolower($node->ancestors()->toSql());
        $this->assertStringContainsString('order by', $sql);
        $this->assertStringContainsString('_lft', $sql);

        // galaxy's ancestors are store (_lft 1), mobile (_lft 8), samsung (_lft 11)
        $ancestors = $node->getAncestors();
        $this->assertEquals([1, 8, 11], $ancestors->pluck('_lft')->all());
        $this->assertEquals(['store', 'mobile', 'samsung'], $ancestors->pluck('name')->all());
    }

    public function testEagerLoadsAncestorsInHierarchicalOrder()
    {
        DB::flushQueryLog();

        $galaxy = static::getModelClass()::with('ancestors')->find($this->ids[8]);

        // The eager-load query for ancestors must also be ordered by _lft.
        $ancestorQuery = collect(DB::connection()->getQueryLog())
            ->first(fn ($entry) => str_contains(strtolower($entry['query']), 'order by'));
        $this->assertNotNull($ancestorQuery, 'Eager ancestors query is not ordered.');
        $this->assertStringContainsString('_lft', strtolower($ancestorQuery['query']));

        $this->assertEquals([1, 8, 11], $galaxy->ancestors->pluck('_lft')->all());
        $this->assertEquals(['store', 'mobile', 'samsung'], $galaxy->ancestors->pluck('name')->all());
    }

    public function testLinkedTreeIsJsonSerializable()
    {
        $tree = static::getModelClass()::get()->toTree();

        // Must not throw / infinitely recurse over the child->parent back-reference.
        $json = $tree->toJson();
        $this->assertJson($json);

        $root = $tree->first();
        $child = $root->children->first();

        // The parent stub set on a child carries no further relations, so it
        // cannot re-enter the tree during serialization.
        $this->assertSame($root->getKey(), $child->parent->getKey());
        $this->assertEmpty($child->parent->getRelations());
    }
}

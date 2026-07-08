<?php

use PHPUnit\Framework\TestCase;
use Aimeos\Nestedset\NestedSet;

class NestedSetCacheTest extends TestCase
{
    public function testUsesSoftDeleteCheckIsCachedPerModelClass(): void
    {
        $categoryCache = new ReflectionProperty(Category::class, 'usesSoftDeleteCache');
        $categoryCache->setAccessible(true);
        $categoryCache->setValue(null, []);

        $menuItemCache = new ReflectionProperty(MenuItem::class, 'usesSoftDeleteCache');
        $menuItemCache->setAccessible(true);
        $menuItemCache->setValue(null, []);

        $this->assertTrue(Category::usesSoftDelete());
        $this->assertFalse(MenuItem::usesSoftDelete());

        $this->assertSame([Category::class => true], $categoryCache->getValue());
        $this->assertSame([MenuItem::class => false], $menuItemCache->getValue());
    }

    public function testNodeTraitCheckIsCachedPerClass(): void
    {
        $property = new ReflectionProperty(NestedSet::class, 'nodeClassCache');
        $property->setAccessible(true);
        $property->setValue(null, []);

        $this->assertTrue(NestedSet::isNode(new Category()));
        $this->assertTrue(NestedSet::isNode(new CategoryUuid()));
        $this->assertFalse(NestedSet::isNode(new stdClass()));
        $this->assertFalse(NestedSet::isNode(null));

        $cache = $property->getValue();

        $this->assertSame([
            Category::class => true,
            CategoryUuid::class => true,
            stdClass::class => false,
        ], array_intersect_key($cache, [
            Category::class => true,
            CategoryUuid::class => true,
            stdClass::class => true,
        ]));
    }
}

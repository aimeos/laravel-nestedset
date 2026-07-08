<?php

use PHPUnit\Framework\TestCase;

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
}

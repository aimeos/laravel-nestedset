<?php

namespace Aimeos\Nestedset;

use Illuminate\Database\Eloquent\Collection as BaseCollection;
use Illuminate\Database\Eloquent\Model;


class Collection extends BaseCollection
{
    /**
     * Fill `parent` and `children` relationships for every node in the collection.
     *
     * This will overwrite any previously set relations.
     *
     * @return $this
     */
    public function linkNodes(): self
    {
        if ($this->isEmpty()) return $this;

        [$groupedNodes] = $this->groupNodesByParent();

        return $this->linkNodesFromGroups($groupedNodes);
    }


    /**
     * Fill `parent` and `children` relationships from pre-grouped nodes.
     *
     * @param array $groupedNodes
     *
     * @return $this
     */
    protected function linkNodesFromGroups(array $groupedNodes): self
    {

        /** @var NodeTrait|Model $node */
        foreach ($this->items as $node) {
            if ($node->getParentId() === null) {
                $node->setRelation('parent', null);
            }

            $children = $groupedNodes[$this->groupKey($node->getKey())] ?? [ ];

            if ($children) {
                $parent = clone $node;

                // Detach relations from the parent stub so the child->parent
                // reference can't re-enter the tree and cause infinite
                // recursion when serializing (e.g. toJson() or Livewire).
                $parent->setRelations([ ]);

                /** @var Model|NodeTrait $child */
                foreach ($children as $child) {
                    $child->setRelation('parent', $parent);
                }
            }

            $node->setRelation('children', BaseCollection::make($children));
        }

        return $this;
    }


    /**
     * Build a list of nodes that retain the order that they were pulled from
     * the database.
     *
     * @param Model|int|string|bool $root
     *
     * @return self
     */
    public function toFlatTree(Model|int|string|bool $root = false): self
    {
        $result = new self;

        if ($this->isEmpty()) return $result;

        [$groupedNodes, $root] = $this->groupNodesByParent($root ?: null, true);

        return $result->flattenTree($groupedNodes, $root);
    }


    /**
     * Build a tree from a list of nodes. Each item will have set children relation.
     *
     * To successfully build tree "id", "_lft" and "parent_id" keys must present.
     *
     * If `$root` is provided, the tree will contain only descendants of that node.
     *
     * @param Model|int|string|bool $root
     *
     * @return Collection
     */
    public function toTree(Model|int|string|bool $root = false): self
    {
        if ($this->isEmpty()) {
            return new self;
        }

        [$groupedNodes, $root] = $this->groupNodesByParent($root ?: null, true);

        $this->linkNodesFromGroups($groupedNodes);

        return new self($groupedNodes[$this->groupKey($root)] ?? [ ]);
    }


    /**
     * @param Model|int|string|null $root
     * @param bool $findRoot
     *
     * @return array
     */
    protected function groupNodesByParent(Model|int|string|null $root = null, bool $findRoot = false): array
    {
        $groupedNodes = [ ];
        $rootIsKnown = false;
        $leastValue = null;

        if ($findRoot) {
            [$root, $rootIsKnown] = $this->normalizeRootNodeId($root);
        }

        /** @var Model|NodeTrait $node */
        foreach ($this->items as $node) {
            $parentId = $node->getParentId();
            $groupedNodes[$this->groupKey($parentId)][] = $node;

            if ($findRoot && ! $rootIsKnown && ($leastValue === null || $node->getLft() < $leastValue)) {
                $leastValue = $node->getLft();
                $root = $parentId;
            }
        }

        return [$groupedNodes, $root];
    }


    /**
     * @param Model|int|string|null $root
     *
     * @return int|string|null
     */
    protected function getRootNodeId(Model|int|string|null $root = null): int|string|null
    {
        [$root, $rootIsKnown] = $this->normalizeRootNodeId($root);

        // If root node is not specified we take parent id of node with
        // least lft value as root node id.
        if ($rootIsKnown) {
            return $root;
        }

        $leastValue = null;

        /** @var Model|NodeTrait $node */
        foreach ($this->items as $node) {
            if ($leastValue === null || $node->getLft() < $leastValue) {
                $leastValue = $node->getLft();
                $root = $node->getParentId();
            }
        }

        return $root;
    }


    /**
     * Flatten a tree into a non recursive array.
     *
     * @param array $groupedNodes
     * @param int|string|null $parentId
     *
     * @return $this
     */
    protected function flattenTree(array $groupedNodes, int|string|null $parentId): self
    {
        $stack = [ ];
        $children = $this->nodesForParent($groupedNodes, $parentId);

        for ($i = count($children) - 1; $i >= 0; --$i) {
            $stack[] = $children[$i];
        }

        while ($stack) {
            $node = array_pop($stack);
            $this->push($node);

            $children = $this->nodesForParent($groupedNodes, $node->getKey());

            for ($i = count($children) - 1; $i >= 0; --$i) {
                $stack[] = $children[$i];
            }
        }

        return $this;
    }


    /**
     * @param Model|int|string|null $root
     *
     * @return array
     */
    protected function normalizeRootNodeId(Model|int|string|null $root = null): array
    {
        if (NestedSet::isNode($root)) {
            return [$root->getKey(), true];
        }

        if ($root) {
            return [$root, true];
        }

        return [null, false];
    }


    /**
     * @param int|string|null $key
     *
     * @return int|string
     */
    protected function groupKey(int|string|null $key): int|string
    {
        return $key ?? '';
    }


    /**
     * @param array $groupedNodes
     * @param int|string|null $parentId
     *
     * @return array
     */
    protected function nodesForParent(array $groupedNodes, int|string|null $parentId): array
    {
        return $groupedNodes[$this->groupKey($parentId)] ?? [ ];
    }

}

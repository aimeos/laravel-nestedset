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

        $groupedNodes = $this->groupBy($this->first()->getParentIdName());

        /** @var NodeTrait|Model $node */
        foreach ($this->items as $node) {
            if ( ! $node->getParentId()) {
                $node->setRelation('parent', null);
            }

            $children = $groupedNodes->get($node->getKey(), [ ]);

            /** @var Model|NodeTrait $child */
            foreach ($children as $child) {
                $child->setRelation('parent', $node);
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

        $groupedNodes = $this->groupBy($this->first()->getParentIdName());

        return $result->flattenTree($groupedNodes, $this->getRootNodeId($root ?: null));
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

        $this->linkNodes();

        $items = [ ];

        $root = $this->getRootNodeId($root ?: null);

        /** @var Model|NodeTrait $node */
        foreach ($this->items as $node) {
            if ($node->getParentId() == $root) {
                $items[] = $node;
            }

            if($node->isLeaf()){
                unset($node->children);
            }
        }

        return new self($items);
    }


    /**
     * @param Model|int|string|null $root
     *
     * @return int|string|null
     */
    protected function getRootNodeId(Model|int|string|null $root = null): int|string|null
    {
        if (NestedSet::isNode($root)) {
            return $root->getKey();
        }

        if ($root) {
            return $root;
        }

        // If root node is not specified we take parent id of node with
        // least lft value as root node id.
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
     * @param Collection $groupedNodes
     * @param int|string|null $parentId
     *
     * @return $this
     */
    protected function flattenTree(self $groupedNodes, int|string|null $parentId): self
    {
        foreach ($groupedNodes->get($parentId, []) as $node) {
            $this->push($node);

            $this->flattenTree($groupedNodes, $node->getKey());
        }

        return $this;
    }

}
<?php

namespace Aimeos\Nestedset;

use Illuminate\Database\Eloquent\Model;


class AncestorsRelation extends BaseRelation
{
    /**
     * Set the base constraints on the relation query.
     *
     * @return void
     */
    public function addConstraints(): void
    {
        if ( ! static::$constraints) return;

        $this->query->whereAncestorOf($this->parent)
            ->applyNestedSetScope()
            ->defaultOrder();
    }


    /**
     * Set the constraints for an eager load of the relation.
     *
     * Ancestors are returned in hierarchical order (root first) for both lazy
     * and eager loading.
     *
     * @param array $models
     *
     * @return void
     */
    public function addEagerConstraints(array $models): void
    {
        parent::addEagerConstraints($models);

        $this->query->defaultOrder();
    }


    /**
     * @param QueryBuilder $query
     * @param Model $model
     *
     * @return void
     */
    protected function addEagerConstraint(QueryBuilder $query, Model $model): void
    {
        $query->orWhereAncestorOf($model);
    }


    /**
     * @param array $models
     *
     * @return array
     */
    protected function prepareEagerModels(array $models): array
    {
        $models = parent::prepareEagerModels($models);
        $result = [];

        foreach ($models as $model) {
            foreach ($models as $other) {
                if ($model !== $other && $other->isDescendantOf($model)) {
                    continue 2;
                }
            }

            $result[] = $model;
        }

        return $result;
    }


    /**
     * @param Model $model
     * @param Model $related
     *
     * @return bool
     */
    protected function matches(Model $model, Model $related): bool
    {
        return $related->isAncestorOf($model);
    }


    /**
     * @param string $hash
     * @param string $table
     * @param string $lft
     * @param string $rgt
     *
     * @return string
     */
    protected function relationExistenceCondition(string $hash, string $table, string $lft, string $rgt): string
    {
        $key = $this->getBaseQuery()->getGrammar()->wrap($this->parent->getKeyName());

        return "{$table}.{$rgt} between {$hash}.{$lft} and {$hash}.{$rgt} and $table.$key <> $hash.$key";
    }
}

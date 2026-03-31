<?php

namespace Aimeos\Nestedset;

use Illuminate\Database\Eloquent\Model;


class SiblingsRelation extends BaseRelation
{
    /**
     * Whether to include the parent node itself.
     *
     * @var bool
     */
    protected $andSelf;


    /**
     * @param QueryBuilder $builder
     * @param Model $model
     * @param bool $andSelf
     */
    public function __construct(QueryBuilder $builder, Model $model, bool $andSelf = false)
    {
        $this->andSelf = $andSelf;

        parent::__construct($builder, $model);
    }


    /**
     * Set the base constraints on the relation query.
     *
     * @return void
     */
    public function addConstraints(): void
    {
        if ( ! static::$constraints) return;

        $this->query
            ->where($this->parent->getParentIdName(), '=', $this->parent->getParentId())
            ->applyNestedSetScope();

        if ( ! $this->andSelf) {
            $this->query->where($this->parent->getKeyName(), '<>', $this->parent->getKey());
        }
    }


    /**
     * @param QueryBuilder $query
     * @param Model $model
     *
     * @return void
     */
    protected function addEagerConstraint(QueryBuilder $query, Model $model): void
    {
        $query->orWhere(function ($inner) use ($model) {
            $inner->where($model->getParentIdName(), '=', $model->getParentId());

            if ( ! $this->andSelf) {
                $inner->where($model->getKeyName(), '<>', $model->getKey());
            }
        });
    }


    /**
     * @param Model $model
     * @param Model $related
     *
     * @return bool
     */
    protected function matches(Model $model, Model $related): bool
    {
        $result = $related->getParentId() == $model->getParentId();

        if ( ! $this->andSelf) {
            $result = $result && $related->getKey() != $model->getKey();
        }

        return $result;
    }


    /**
     * @param \Illuminate\Database\Eloquent\Collection $results
     *
     * @return array|null
     */
    protected function indexResults(\Illuminate\Database\Eloquent\Collection $results): ?array
    {
        $index = [];

        foreach ($results as $related) {
            $index[$related->getParentId() ?? ''][] = $related;
        }

        return $index;
    }


    /**
     * @param Model $model
     * @param array $indexed
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function matchFromIndex(Model $model, array $indexed): \Illuminate\Database\Eloquent\Collection
    {
        $candidates = $indexed[$model->getParentId() ?? ''] ?? [];

        if ( ! $this->andSelf) {
            $candidates = array_filter($candidates, fn($r) => $r->getKey() != $model->getKey());
        }

        return $this->related->newCollection($candidates);
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
        $parentId = $this->getBaseQuery()->getGrammar()->wrap($this->parent->getParentIdName());
        $key = $this->getBaseQuery()->getGrammar()->wrap($this->parent->getKeyName());

        $condition = "{$hash}.{$parentId} = {$table}.{$parentId}";

        if ( ! $this->andSelf) {
            $condition .= " and {$hash}.{$key} <> {$table}.{$key}";
        }

        return $condition;
    }
}

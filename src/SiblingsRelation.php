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
     * Set the constraints for an eager load of the relation.
     *
     * @param  array $models
     *
     * @return void
     */
    public function addEagerConstraints(array $models): void
    {
        $models = $this->prepareEagerModels($models);

        if (empty($models)) {
            $this->query->whereRaw('0 = 1');

            return;
        }

        $parentIdName = $this->parent->getParentIdName();
        $parentIds = [];
        $hasRootParent = false;

        foreach ($models as $model) {
            if ($model->getParentId() === null) {
                $hasRootParent = true;
            } else {
                $parentIds[$model->getParentId()] = $model->getParentId();
            }
        }

        $this->query->where(function ($inner) use ($parentIdName, $parentIds, $hasRootParent) {
            if ($parentIds) {
                $inner->whereIn($parentIdName, array_values($parentIds));
            }

            if ($hasRootParent) {
                $parentIds
                    ? $inner->orWhereNull($parentIdName)
                    : $inner->whereNull($parentIdName);
            }
        });
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
            $key = $related->getParentId() ?? '';

            if ($this->andSelf) {
                $index[$key][] = $related;
            } else {
                $index[$key]['models'][] = $related;
                $index[$key]['keys'][] = $related->getKey();
            }
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
        $group = $indexed[$model->getParentId() ?? ''] ?? null;

        if ($group === null) {
            return $this->related->newCollection();
        }

        if ($this->andSelf) {
            return $this->related->newCollection($group);
        }

        $candidates = $group['models'];

        $key = $model->getKey();
        $matches = [];

        foreach ($candidates as $offset => $candidate) {
            if ($group['keys'][$offset] != $key) {
                $matches[] = $candidate;
            }
        }

        return $this->related->newCollection($matches);
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

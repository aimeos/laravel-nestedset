<?php

namespace Aimeos\Nestedset;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;


class DescendantsRelation extends BaseRelation
{
    /**
     * Set the base constraints on the relation query.
     *
     * @return void
     */
    public function addConstraints(): void
    {
        if ( ! static::$constraints) return;

        $this->query->whereDescendantOf($this->parent)->applyNestedSetScope();
    }


    /**
     * @param QueryBuilder $query
     * @param Model $model
     */
    protected function addEagerConstraint(QueryBuilder $query, Model $model): void
    {
        $query->orWhereDescendantOf($model);
    }


    /**
     * @param array $models
     *
     * @return array
     */
    protected function prepareEagerModels(array $models): array
    {
        $models = parent::prepareEagerModels($models);

        usort($models, function (Model $a, Model $b) {
            return [$a->getLft(), -$a->getRgt()] <=> [$b->getLft(), -$b->getRgt()];
        });

        $result = [];
        $active = [];

        foreach ($models as $model) {
            while ($active && end($active)->getRgt() < $model->getLft()) {
                array_pop($active);
            }

            for ($i = count($active) - 1; $i >= 0; --$i) {
                if ($model->isDescendantOf($active[$i])) {
                    continue 2;
                }
            }

            $result[] = $model;
            $active[] = $model;
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
        return $related->isDescendantOf($model);
    }


    /**
     * @param EloquentCollection $results
     *
     * @return array|null
     */
    protected function indexResults(EloquentCollection $results): ?array
    {
        $entries = [];

        foreach ($results as $position => $related) {
            if ($related->getLft() === null || $related->getRgt() === null) {
                return null;
            }

            $entries[] = [
                'lft' => $related->getLft(),
                'position' => $position,
                'related' => $related,
            ];
        }

        usort($entries, fn ($a, $b) => $a['lft'] <=> $b['lft']);

        return [
            'entries' => $entries,
            'lfts' => array_column($entries, 'lft'),
            'preserves_order' => $this->preservesResultOrder($entries),
        ];
    }


    /**
     * @param Model $model
     * @param array $indexed
     *
     * @return EloquentCollection
     */
    protected function matchFromIndex(Model $model, array $indexed): EloquentCollection
    {
        if ($model->getLft() === null || $model->getRgt() === null) {
            return $this->related->newCollection();
        }

        $matches = [];
        $start = self::lowerBound($indexed['lfts'], $model->getLft() + 1);

        for ($i = $start, $count = count($indexed['entries']); $i < $count; ++$i) {
            $entry = $indexed['entries'][$i];

            if ($entry['lft'] >= $model->getRgt()) {
                break;
            }

            if ($this->matches($model, $entry['related'])) {
                $matches[] = $entry;
            }
        }

        if ( ! $indexed['preserves_order']) {
            usort($matches, fn ($a, $b) => $a['position'] <=> $b['position']);
        }

        return $this->related->newCollection(array_column($matches, 'related'));
    }


    /**
     * @param array $entries
     *
     * @return bool
     */
    protected function preservesResultOrder(array $entries): bool
    {
        $last = -1;

        foreach ($entries as $entry) {
            if ($entry['position'] < $last) {
                return false;
            }

            $last = $entry['position'];
        }

        return true;
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
        return "{$hash}.{$lft} between {$table}.{$lft} + 1 and {$table}.{$rgt}";
    }
}

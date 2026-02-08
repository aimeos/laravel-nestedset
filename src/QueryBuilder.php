<?php

namespace Aimeos\Nestedset;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Arr;
use LogicException;


/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 * @extends \Illuminate\Database\Eloquent\Builder<TModel>
 *
 * @method static \Illuminate\Database\Eloquent\Builder<TModel> withTrashed(bool $withTrashed = true)
 */
class QueryBuilder extends EloquentBuilder
{
    /**
     * @var NodeTrait|Model
     */
    protected $model;


    /**
     * @param int|string $id
     * @param array $columns
     *
     * @return \Aimeos\Nestedset\Collection
     */
    public function ancestorsAndSelf(int|string $id, array $columns = ['*']): Collection
    {
        return $this->whereAncestorOf($id, true)->get($columns);
    }


    /**
     * Get ancestors of specified node.
     *
     * @since 2.0
     *
     * @param int|string $id
     * @param array $columns
     *
     * @return \Aimeos\Nestedset\Collection
     */
    public function ancestorsOf(int|string $id, array $columns = ['*']): Collection
    {
        return $this->whereAncestorOf($id)->get($columns);
    }


    /**
     * @param string|null $table
     *
     * @return self
     */
    public function applyNestedSetScope(?string $table = null): self
    {
        return $this->model->applyNestedSetScope($this, $table);
    }


    /**
     * Get statistics of errors of the tree.
     *
     * @since 2.0
     *
     * @return array
     */
    public function countErrors(): array
    {
        $checks = [];

        // Check if lft and rgt values are ok
        $checks['oddness'] = $this->getOdnessQuery();

        // Check if lft and rgt values are unique
        $checks['duplicates'] = $this->getDuplicatesQuery();

        // Check if parent_id is set correctly
        $checks['wrong_parent'] = $this->getWrongParentQuery();

        // Check for nodes that have missing parent
        $checks['missing_parent' ] = $this->getMissingParentQuery();

        $query = $this->query->newQuery();

        foreach ($checks as $key => $inner) {
            $inner->selectRaw('count(1)');

            $query->selectSub($inner, $key);
        }

        return (array)$query->first();
    }


    /**
     * Order by node position.
     *
     * @param string $dir
     *
     * @return self
     */
    public function defaultOrder(string $dir = 'asc'): self
    {
        $this->query->orders = null;
        $this->query->orderBy($this->model->getLftName(), $dir);

        return $this;
    }


    /**
     * @param int|string $id
     * @param array $columns
     *
     * @return Collection
     */
    public function descendantsAndSelf(int|string $id, array $columns = ['*']): Collection
    {
        return $this->descendantsOf($id, $columns, true);
    }


    /**
     * Get descendants of specified node.
     *
     * @since 2.0
     *
     * @param int|string $id
     * @param array $columns
     * @param bool $andSelf
     *
     * @return Collection
     */
    public function descendantsOf(int|string $id, array $columns = ['*'], bool $andSelf = false): Collection
    {
        try {
            return $this->whereDescendantOf($id, 'and', false, $andSelf)->get($columns);
        }

        catch (ModelNotFoundException $e) {
            return $this->model->newCollection();
        }
    }


    /**
     * @param NodeTrait|Model $root
     *
     * @return int
     */
    public function fixSubtree(?Model $root): int
    {
        return $this->fixTree($root);
    }


    /**
     * Fixes the tree based on parentage info.
     *
     * Nodes with invalid parent are saved as roots.
     *
     * @param null|NodeTrait|Model $root
     * @param array $extraColumns
     * @return int The number of changed nodes
     */
    public function fixTree(?Model $root = null, array $extraColumns = []): int
    {
        $columns = array_merge([
            $this->model->getKeyName(),
            $this->model->getParentIdName(),
            $this->model->getLftName(),
            $this->model->getRgtName(),
            $this->model->getDepthName(),
        ], $extraColumns);

        $dictionary = $this->model
            ->newNestedSetQuery()
            ->when($root, function (self $query) use ($root) {
                return $query->whereDescendantOf($root);
            })
            ->defaultOrder()
            ->get($columns)
            ->groupBy($this->model->getParentIdName())
            ->all();

        return $this->fixNodes($dictionary, $root);
    }


    /**
     * Get depth of the position in the tree.
     *
     * @param int $position
     * @return int Depth level
     */
    public function getDepth(int $position) : int
    {
        return (int) $this->model->newQuery()
            ->where($this->model->getLftName(), '<', $position)
            ->where($this->model->getRgtName(), '>=', $position)
            ->orderBy($this->model->getLftName(), 'desc')
            ->value($this->model->getDepthName());
    }


    /**
     * Get node's `lft` and `rgt` values.
     *
     * @since 2.0
     *
     * @param int|string $id
     * @param bool $required
     *
     * @return array
     */
    public function getNodeData(int|string $id, bool $required = false): array
    {
        $lftName = $this->model->getLftName();
        $rgtName = $this->model->getRgtName();
        $depthName = $this->model->getDepthName();

        $data = $this->toBase()
            ->where($this->model->getKeyName(), '=', $id)
            ->first([$lftName, $rgtName, $depthName]);

        if ( ! $data && $required) {
            throw new ModelNotFoundException;
        }

        return $data ? (array) $data : [];
    }

    /**
     * Get plain node data.
     *
     * @since 2.0
     *
     * @param int|string $id
     * @param bool $required
     *
     * @return array
     */
    public function getPlainNodeData(int|string $id, bool $required = false): array
    {
        $data = $this->getNodeData($id, $required);
        return [ $data[$this->model->getLftName()], $data[$this->model->getRgtName()] ];
    }


    /**
     * Get the number of total errors of the tree.
     *
     * @since 2.0
     *
     * @return int
     */
    public function getTotalErrors(): int
    {
        return array_sum($this->countErrors());
    }


    /**
     * Get only nodes that have children.
     *
     * @since 2.0
     * @deprecated since v4.1
     *
     * @return self
     */
    public function hasChildren(): self
    {
        list($lft, $rgt) = $this->wrappedColumns();

        $this->query->whereRaw("{$rgt} > {$lft} + 1");

        return $this;
    }


    /**
     * Equivalent of `withoutRoot`.
     *
     * @since 2.0
     * @deprecated since v4.1
     *
     * @return self
     */
    public function hasParent(): self
    {
        $this->query->whereNotNull($this->model->getParentIdName());

        return $this;
    }


    /**
     * Get whether the tree is broken.
     *
     * @since 2.0
     *
     * @return bool
     */
    public function isBroken(): bool
    {
        return $this->getTotalErrors() > 0;
    }


    /**
     * @param array $columns
     *
     * @return Collection
     */
    public function leaves(array $columns = [ '*']): Collection
    {
        return $this->whereIsLeaf()->get($columns);
    }


    /**
     * Make or remove gap in the tree. Negative height will remove gap.
     *
     * @since 2.0
     *
     * @param int $cut
     * @param int $height
     *
     * @return int
     */
    public function makeGap(int $cut, int $height): int
    {
        $params = compact('cut', 'height');

        $query = $this->toBase()->whereNested(function (Builder $inner) use ($cut) {
            $inner->where($this->model->getLftName(), '>=', $cut);
            $inner->orWhere($this->model->getRgtName(), '>=', $cut);
        });

        return $query->update($this->patch($params));
    }


    /**
     * Move a node to the new position.
     *
     * @param int|string $key
     * @param int $position
     *
     * @return int
     */
    public function moveNode(int|string $key, int $position): int
    {
        $data = $this->model->newNestedSetQuery()->getNodeData($key, true);
        $depth = $data[$this->model->getDepthName()];
        $lft = $data[$this->model->getLftName()];
        $rgt = $data[$this->model->getRgtName()];

        if ($lft < $position && $position <= $rgt) {
            throw new LogicException('Cannot move node into itself.');
        }

        // Get boundaries of nodes that should be moved to new position
        $from = min($lft, $position);
        $to = max($rgt, $position - 1);

        // The height of node that is being moved
        $height = $rgt - $lft + 1;

        // The distance that our node will travel to reach it's destination
        $distance = $to - $from + 1 - $height;

        // If no distance to travel, just return
        if ($distance === 0) {
            return 0;
        }

        if ($position > $lft) {
            $height *= -1;
        } else {
            $distance *= -1;
        }

        $depth = ($this->getDepth($position) + 1) - $depth;
        $params = compact('lft', 'rgt', 'from', 'to', 'height', 'distance', 'depth');
        $boundary = [ $from, $to ];

        $query = $this->toBase()->where(function (Builder $inner) use ($boundary) {
            $inner->whereBetween($this->model->getLftName(), $boundary);
            $inner->orWhereBetween($this->model->getRgtName(), $boundary);
        });

        return $query->update($this->patch($params));
    }


    /**
     * @param Model|int|string $id
     * @param bool $andSelf
     *
     * @return self
     */
    public function orWhereAncestorOf(Model|int|string $id, bool $andSelf = false): self
    {
        return $this->whereAncestorOf($id, $andSelf, 'or');
    }


    /**
     * @param Model|int|string $id
     *
     * @return self
     */
    public function orWhereDescendantOf(Model|int|string $id): self
    {
        return $this->whereDescendantOf($id, 'or');
    }


    /**
     * Add node selection statement between specified range joined with `or` operator.
     *
     * @since 2.0
     *
     * @param array $values
     *
     * @return self
     */
    public function orWhereNodeBetween(array $values): self
    {
        return $this->whereNodeBetween($values, 'or');
    }


    /**
     * @param Model|int|string $id
     *
     * @return self
     */
    public function orWhereNotDescendantOf(Model|int|string $id): self
    {
        return $this->whereDescendantOf($id, 'or', true);
    }


    /**
     * @param $root
     * @param array $data
     * @param bool $delete
     *
     * @return int
     */
    public function rebuildSubtree(Model $root, array $data, bool $delete = false): int
    {
        return $this->rebuildTree($data, $delete, $root);
    }


    /**
     * Rebuild the tree based on raw data.
     *
     * If item data does not contain primary key, new node will be created.
     *
     * @param array $data
     * @param bool $delete Whether to delete nodes that exists but not in the data array
     * @param Model|null $root
     *
     * @return int
     */
    public function rebuildTree(array $data, bool $delete = false, ?Model $root = null): int
    {
        if ($this->model->usesSoftDelete()) {
            $this->withTrashed();
        }

        $existing = $this
            ->when($root, function (self $query) use ($root) {
                return $query->whereDescendantOf($root);
            })
            ->get()
            ->getDictionary();

        $dictionary = [];
        $parentId = $root ? $root->getKey() : null;

        $this->buildRebuildDictionary($dictionary, $data, $existing, $parentId);

        /** @var Model|NodeTrait $model */
        if ( ! empty($existing)) {
            if ($delete && ! $this->model->usesSoftDelete()) {
                $this->model
                    ->newScopedQuery()
                    ->whereIn($this->model->getKeyName(), array_keys($existing))
                    ->delete();
            } else {
                foreach ($existing as $model) {
                    $dictionary[$model->getParentId()][] = $model;

                    if ($delete && $this->model->usesSoftDelete() &&
                        ! $model->{$model->getDeletedAtColumn()}
                    ) {
                        $time = $this->model->fromDateTime($this->model->freshTimestamp());

                        $model->{$model->getDeletedAtColumn()} = $time;
                    }
                }
            }
        }

        return $this->fixNodes($dictionary, $root);
    }


    /**
     * Order by reversed node position.
     *
     * @return self
     */
    public function reversed(): self
    {
        return $this->defaultOrder('desc');
    }


    /**
     * Get the root node.
     *
     * @param array $columns
     *
     * @return Model
     */
    public function root(array $columns = ['*']): Model
    {
        return $this->whereIsRoot()->first($columns);
    }



    /**
     * Limit results to ancestors of specified node.
     *
     * @since 2.0
     *
     * @param Model|int|string $id
     * @param bool $andSelf
     * @param string $boolean
     *
     * @return self
     */
    public function whereAncestorOf(Model|int|string $id, bool $andSelf = false, string $boolean = 'and'): self
    {
        $keyName = $this->model->getTable() . '.' . $this->model->getKeyName();
        $model = null;

        if (NestedSet::isNode($id)) {
            $model = $id;
            $value = '?';

            $this->query->addBinding($id->getRgt());

            $id = $id->getKey();
        } else {
            $valueQuery = $this->model
                ->newQuery()
                ->toBase()
                ->select("_.".$this->model->getRgtName())
                ->from($this->model->getTable().' as _')
                ->where($this->model->getKeyName(), '=', $id)
                ->limit(1);

            $this->query->mergeBindings($valueQuery);

            $value = '('.$valueQuery->toSql().')';
        }

        $this->query->whereNested(function ($inner) use ($model, $value, $andSelf, $id, $keyName) {
            list($lft, $rgt) = $this->wrappedColumns();
            $wrappedTable = $this->query->getGrammar()->wrapTable($this->model->getTable());

            $inner->whereRaw("{$value} between {$wrappedTable}.{$lft} and {$wrappedTable}.{$rgt}");

            if ( ! $andSelf) {
                $inner->where($keyName, '<>', $id);
            }
            if ($model !== null) {
                // we apply scope only when Node was passed as $id.
                // In other cases, according to docs, query should be scoped() before calling this method
                $model->applyNestedSetScope($inner);
            }
        }, $boolean);

        return $this;
    }


    /**
     * @param Model|int|string $id
     *
     * @return Builder
     */
    public function whereAncestorOrSelf(Model|int|string $id): self
    {
        return $this->whereAncestorOf($id, true);
    }


    /**
     * Add constraint statement to descendants of specified node.
     *
     * @since 2.0
     *
     * @param Model|int|string $id
     * @param string $boolean
     * @param bool $not
     * @param bool $andSelf
     *
     * @return self
     */
    public function whereDescendantOf(Model|int|string $id, string $boolean = 'and', bool $not = false, bool $andSelf = false): self
    {
        $this->query->whereNested(function (Builder $inner) use ($id, $andSelf, $not) {
            if (NestedSet::isNode($id)) {
                $id->applyNestedSetScope($inner);
                $data = $id->getBounds();
            } else {
                // we apply scope only when Node was passed as $id.
                // In other cases, according to docs, query should be scoped() before calling this method
                $data = $this->model->newNestedSetQuery()
                    ->getPlainNodeData($id, true);
            }

            // Don't include the node
            if (!$andSelf) {
                ++$data[0];
            }

            return $this->whereNodeBetween($data, 'and', $not, $inner);
        }, $boolean);

        return $this;
    }


    /**
     * @param Model|int|string $id
     * @param string $boolean
     * @param bool $not
     *
     * @return self
     */
    public function whereDescendantOrSelf(Model|int|string $id, string $boolean = 'and', bool $not = false): self
    {
        return $this->whereDescendantOf($id, $boolean, $not, true);
    }


    /**
     * Constraint nodes to those that are after specified node.
     *
     * @since 2.0
     *
     * @param Model|int|string $id
     * @param string $boolean
     *
     * @return self
     */
    public function whereIsAfter(Model|int|string $id, string $boolean = 'and'): self
    {
        return $this->whereIsBeforeOrAfter($id, '>', $boolean);
    }


    /**
     * Constraint nodes to those that are before specified node.
     *
     * @since 2.0
     *
     * @param Model|int|string $id
     * @param string $boolean
     *
     * @return self
     */
    public function whereIsBefore(Model|int|string $id, string $boolean = 'and'): self
    {
        return $this->whereIsBeforeOrAfter($id, '<', $boolean);
    }


    /**
     * @return self
     */
    public function whereIsLeaf(): self
    {
        list($lft, $rgt) = $this->wrappedColumns();

        return $this->whereRaw("$lft = $rgt - 1");
    }


    /**
     * Scope limits query to select just root node.
     *
     * @return self
     */
    public function whereIsRoot(): self
    {
        $this->query->whereNull($this->model->getParentIdName());

        return $this;
    }


    /**
     * Add node selection statement between specified range.
     *
     * @since 2.0
     *
     * @param array $values
     * @param string $boolean
     * @param bool $not
     * @param Builder $query
     *
     * @return self
     */
    public function whereNodeBetween(array $values, string $boolean = 'and', bool $not = false, ?Builder $query = null): self
    {
        ($query ?? $this->query)->whereBetween($this->model->getTable() . '.' . $this->model->getLftName(), $values, $boolean, $not);

        return $this;
    }


    /**
     * @param Model|int|string $id
     *
     * @return self
     */
    public function whereNotDescendantOf(Model|int|string $id): self
    {
        return $this->whereDescendantOf($id, 'and', true);
    }


    /**
     * Include depth level into the result.
     *
     * @param string $as
     *
     * @return self
     */
    public function withDepth(string $as = 'depth'): self
    {
        if ($this->query->columns === null) $this->query->columns = ['*'];

        $table = $this->wrappedTable();

        list($lft, $rgt) = $this->wrappedColumns();

        $alias = '_d';
        $wrappedAlias = $this->query->getGrammar()->wrapTable($alias);

        $query = $this->model
            ->newScopedQuery('_d')
            ->toBase()
            ->selectRaw('count(1) - 1')
            ->from($this->model->getTable().' as '.$alias)
            ->whereRaw("{$table}.{$lft} between {$wrappedAlias}.{$lft} and {$wrappedAlias}.{$rgt}");

        $this->query->selectSub($query, $as);

        return $this;
    }


    /**
     * Exclude root node from the result.
     *
     * @return self
     */
    public function withoutRoot(): self
    {
        $this->query->whereNotNull($this->model->getParentIdName());

        return $this;
    }


    /**
     * @param array $dictionary
     * @param array $data
     * @param array $existing
     * @param mixed $parentId
     */
    protected function buildRebuildDictionary(array &$dictionary, array $data, array &$existing, int|string|null $parentId = null): void
    {
        $keyName = $this->model->getKeyName();

        foreach ($data as $itemData) {
            /** @var NodeTrait|Model $model */

            if ( ! isset($itemData[$keyName])) {
                $model = $this->model->newInstance($this->model->getAttributes());

                // Set some values that will be fixed later
                $model->rawNode(0, 0, $parentId, 0);
            } else {
                if ( ! isset($existing[$key = $itemData[$keyName]])) {
                    throw new ModelNotFoundException;
                }

                $model = $existing[$key];

                // Disable any tree actions
                $model->rawNode($model->getLft(), $model->getRgt(), $parentId, $model->getDepth());
                unset($existing[$key]);
            }

            $model->fill(Arr::except($itemData, 'children'))->save();

            $dictionary[$parentId][] = $model;

            if ( ! isset($itemData['children'])) continue;

            $this->buildRebuildDictionary($dictionary, $itemData['children'], $existing, $model->getKey());
        }
    }


    /**
     * Get patch for single column.
     *
     * @since 2.0
     *
     * @param string $col
     * @param array $params
     *
     * @return Expression
     */
    protected function columnPatch(string $col, array $params): Expression
    {
        extract($params);

        /** @var int $height */
        if ($height >= 0) $height = '+'.$height;

        if (isset($cut)) {
            return new Expression("case when {$col} >= {$cut} then {$col}{$height} else {$col} end");
        }

        /** @var int $distance */
        /** @var int $lft */
        /** @var int $rgt */
        /** @var int $from */
        /** @var int $to */
        if ($distance >= 0) $distance = '+'.$distance;

        return new Expression( // first "when" moves the node, second "when" move other nodes
            "case
                when {$col} between {$lft} and {$rgt} then {$col}{$distance}
                when {$col} between {$from} and {$to} then {$col}{$height}
                else {$col}
            end"
        );
    }


    /**
     * Get depth column patch.
     *
     * @param string $col
     * @param array $params
     * @return Expression
     */
    protected function depthPatch(string $col, array $params): Expression
    {
        extract($params);

        /** @var int $lft */
        /** @var int $rgt */
        /** @var int $depth */
        if ($depth >= 0) {
            $depth = '+' . $depth;
        }

        return new Expression(
            "case
                when {$this->model->getLftName()} between {$lft} and {$rgt}
                then {$col}{$depth}
                else {$col}
            end"
        );
    }


    /**
     * @param array $dictionary
     * @param NodeTrait|Model|null $parent
     *
     * @return int
     */
    protected function fixNodes(array &$dictionary, ?Model $parent = null): int
    {
        $cut = $parent ? $parent->getLft() + 1 : 1;

        $updated = [];
        $moved = 0;

        $cut = self::reorderNodes($dictionary, $updated, $parent, $cut);

        // Save nodes that have invalid parent as roots
        while ( ! empty($dictionary)) {
            $dictionary[null] = reset($dictionary);

            unset($dictionary[key($dictionary)]);

            $cut = self::reorderNodes($dictionary, $updated, $parent, $cut);
        }

        if ($parent && ($grown = $cut - $parent->getRgt()) != 0) {
            $moved = $this->model->newScopedQuery()->makeGap($parent->getRgt() + 1, $grown);

            $updated[] = $parent->rawNode($parent->getLft(), $cut, $parent->getParentId(), $parent->getDepth());
        }

        foreach ($updated as $model) {
            $model->save();
        }

        return count($updated) + $moved;
    }


    /**
     * @return Builder
     */
    protected function getDuplicatesQuery(): Builder
    {
        $table = $this->wrappedTable();
        $keyName = $this->wrappedKey();

        $firstAlias = 'c1';
        $secondAlias = 'c2';

        $waFirst = $this->query->getGrammar()->wrapTable($firstAlias);
        $waSecond = $this->query->getGrammar()->wrapTable($secondAlias);

        $query = $this->model
            ->newNestedSetQuery($firstAlias)
            ->toBase()
            ->from($this->query->raw("{$table} as {$waFirst}, {$table} {$waSecond}"))
            ->whereRaw("{$waFirst}.{$keyName} < {$waSecond}.{$keyName}")
            ->whereNested(function (Builder $inner) use ($waFirst, $waSecond) {
                list($lft, $rgt) = $this->wrappedColumns();

                $inner->orWhereRaw("{$waFirst}.{$lft}={$waSecond}.{$lft}")
                    ->orWhereRaw("{$waFirst}.{$rgt}={$waSecond}.{$rgt}")
                    ->orWhereRaw("{$waFirst}.{$lft}={$waSecond}.{$rgt}")
                    ->orWhereRaw("{$waFirst}.{$rgt}={$waSecond}.{$lft}");
            });

        return $this->model->applyNestedSetScope($query, $secondAlias);
    }


    /**
     * @return Builder
     */
    protected function getMissingParentQuery(): Builder
    {
        return $this->model
            ->newNestedSetQuery()
            ->toBase()
            ->whereNested(function (Builder $inner) {
                $grammar = $this->query->getGrammar();

                $table = $this->wrappedTable();
                $keyName = $this->wrappedKey();
                $parentIdName = $grammar->wrap($this->model->getParentIdName());
                $alias = 'p';
                $wrappedAlias = $grammar->wrapTable($alias);

                $existsCheck = $this->model
                    ->newNestedSetQuery()
                    ->toBase()
                    ->selectRaw('1')
                    ->from($this->query->raw("{$table} as {$wrappedAlias}"))
                    ->whereRaw("{$table}.{$parentIdName} = {$wrappedAlias}.{$keyName}")
                    ->limit(1);

                $this->model->applyNestedSetScope($existsCheck, $alias);

                $inner->whereRaw("{$parentIdName} is not null")
                    ->addWhereExistsQuery($existsCheck, 'and', true);
            });
    }


    /**
     * @return Builder
     */
    protected function getOdnessQuery(): Builder
    {
        return $this->model
            ->newNestedSetQuery()
            ->toBase()
            ->whereNested(function (Builder $inner) {
                list($lft, $rgt) = $this->wrappedColumns();

                $inner->whereRaw("{$lft} >= {$rgt}")
                    ->orWhereRaw("({$rgt} - {$lft}) % 2 = 0");
            });
    }


    /**
     * @return Builder
     */
    protected function getWrongParentQuery(): Builder
    {
        $table = $this->wrappedTable();
        $keyName = $this->wrappedKey();

        $grammar = $this->query->getGrammar();

        $parentIdName = $grammar->wrap($this->model->getParentIdName());

        $parentAlias = 'p';
        $childAlias = 'c';
        $intermAlias = 'i';

        $waParent = $grammar->wrapTable($parentAlias);
        $waChild = $grammar->wrapTable($childAlias);
        $waInterm = $grammar->wrapTable($intermAlias);

        $query = $this->model
            ->newNestedSetQuery('c')
            ->toBase()
            ->from($this->query->raw("{$table} as {$waChild}, {$table} as {$waParent}, $table as {$waInterm}"))
            ->whereRaw("{$waChild}.{$parentIdName}={$waParent}.{$keyName}")
            ->whereRaw("{$waInterm}.{$keyName} <> {$waParent}.{$keyName}")
            ->whereRaw("{$waInterm}.{$keyName} <> {$waChild}.{$keyName}")
            ->whereNested(function (Builder $inner) use ($waInterm, $waChild, $waParent) {
                list($lft, $rgt) = $this->wrappedColumns();

                $inner->whereRaw("{$waChild}.{$lft} not between {$waParent}.{$lft} and {$waParent}.{$rgt}")
                    ->orWhereRaw("{$waChild}.{$lft} between {$waInterm}.{$lft} and {$waInterm}.{$rgt}")
                    ->whereRaw("{$waInterm}.{$lft} between {$waParent}.{$lft} and {$waParent}.{$rgt}");
            });

        $this->model->applyNestedSetScope($query, $parentAlias);
        $this->model->applyNestedSetScope($query, $intermAlias);

        return $query;
    }


    /**
     * Get patch for columns.
     *
     * @since 2.0
     *
     * @param array $params
     *
     * @return array
     */
    protected function patch(array $params): array
    {
        $columns = [];
        $grammar = $this->query->getGrammar();

        // depth update (only for moved subtree)
        if (($params['depth'] ?? 0) !== 0) {
            $col = $this->model->getDepthName();
            $columns[$col] = $this->depthPatch($grammar->wrap($col), $params);
        }

        foreach ([ $this->model->getLftName(), $this->model->getRgtName() ] as $col) {
            $columns[$col] = $this->columnPatch($grammar->wrap($col), $params);
        }

        return $columns;
    }


    /**
     * @param array $dictionary
     * @param array $updated
     * @param $parentId
     * @param int $cut
     *
     * @return int
     * @internal param int $fixed
     */
    protected static function reorderNodes( array &$dictionary, array &$updated, Model|null $parent = null, int $cut = 1): int
    {
        $parentId = $parent?->getKey();

        if ( ! isset($dictionary[$parentId])) {
            return $cut;
        }

        /** @var Model|NodeTrait $model */
        foreach ($dictionary[$parentId] as $model) {
            $lft = $cut;
            $depth = $parent ? $parent->getDepth() + 1 : 0;
            $cut = self::reorderNodes($dictionary, $updated, $model, $cut + 1);

            if ($model->rawNode($lft, $cut, $parentId, $depth)->isDirty()) {
                $updated[] = $model;
            }

            ++$cut;
        }

        unset($dictionary[$parentId]);

        return $cut;
    }


    /**
     * @param Model|int|string $id
     * @param string $operator
     * @param string $boolean
     *
     * @return self
     */
    protected function whereIsBeforeOrAfter(Model|int|string $id, string $operator, string $boolean): self
    {
        if (NestedSet::isNode($id)) {
            $value = '?';

            $this->query->addBinding($id->getLft());
        } else {
            $valueQuery = $this->model
                ->newQuery()
                ->toBase()
                ->select('_n.'.$this->model->getLftName())
                ->from($this->model->getTable().' as _n')
                ->where('_n.'.$this->model->getKeyName(), '=', $id);

            $this->query->mergeBindings($valueQuery);

            $value = '('.$valueQuery->toSql().')';
        }

        list($lft,) = $this->wrappedColumns();

        $this->query->whereRaw("{$lft} {$operator} {$value}", [], $boolean);

        return $this;
    }


    /**
     * Get wrapped `lft` and `rgt` column names.
     *
     * @since 2.0
     *
     * @return array
     */
    protected function wrappedColumns(): array
    {
        $grammar = $this->query->getGrammar();

        return [
            $grammar->wrap($this->model->getLftName()),
            $grammar->wrap($this->model->getRgtName()),
        ];
    }


    /**
     * Wrap model's key name.
     *
     * @since 2.0
     *
     * @return string
     */
    protected function wrappedKey(): string
    {
        return $this->query->getGrammar()->wrap($this->model->getKeyName());
    }


    /**
     * Get a wrapped table name.
     *
     * @since 2.0
     *
     * @return string
     */
    protected function wrappedTable(): string
    {
        return $this->query->getGrammar()->wrapTable($this->getQuery()->from);
    }
}

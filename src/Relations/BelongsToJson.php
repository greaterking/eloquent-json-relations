<?php

namespace Staudenmeir\EloquentJsonRelations\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Arr;

class BelongsToJson extends BelongsTo
{
    use InteractsWithPivotRecords, IsJsonRelation;

    /**
     * Get the results of the relationship.
     *
     * @return mixed
     */
    public function getResults()
    {
        return $this->get();
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @param  array  $columns
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function get($columns = ['*'])
    {
        $models = parent::get($columns);

        if ($this->key) {
            $this->hydratePivotRelation($models->all(), $this->parent);
        }

        return $models;
    }

    /**
     * Hydrate the pivot relationship on the models.
     *
     * @param  array  $models
     * @param  \Illuminate\Database\Eloquent\Model  $parent
     * @return void
     */
    protected function hydratePivotRelation(array $models, Model $parent)
    {
        foreach ($models as $model) {
            $model->setRelation('pivot', $this->pivotRelation($model, $parent));
        }
    }

    /**
     * Get the pivot relationship from the query.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  \Illuminate\Database\Eloquent\Model  $parent
     * @return \Illuminate\Database\Eloquent\Model
     */
    protected function pivotRelation(Model $model, Model $parent)
    {
        $attributes = $this->pivotAttributes($model, $parent);

        return Pivot::fromAttributes($model, $attributes, null, true);
    }

    /**
     * Get the pivot attributes from a model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  \Illuminate\Database\Eloquent\Model  $parent
     * @return array
     */
    protected function pivotAttributes(Model $model, Model $parent)
    {
        $record = collect($parent->{$this->path})
            ->where($this->key, $model->{$this->ownerKey})
            ->first();

        return ! is_null($record) ? Arr::except($record, $this->key) : [];
    }

    /**
     * Set the base constraints on the relation query.
     *
     * @return void
     */
    public function addConstraints()
    {
        if (static::$constraints) {
            $table = $this->related->getTable();

            $this->query->whereIn($table.'.'.$this->ownerKey, (array) $this->child->{$this->foreignKey});
        }
    }

    /**
     * Gather the keys from an array of related models.
     *
     * @param  array  $models
     * @return array
     */
    protected function getEagerModelKeys(array $models)
    {
        $keys = [];

        foreach ($models as $model) {
            $keys = array_merge($keys, (array) $model->{$this->foreignKey});
        }

        if (count($keys) === 0) {
            return [null];
        }

        sort($keys);

        return array_values(array_unique($keys));
    }

    /**
     * Match the eagerly loaded results to their parents.
     *
     * @param  array   $models
     * @param  \Illuminate\Database\Eloquent\Collection  $results
     * @param  string  $relation
     * @return array
     */
    public function match(array $models, Collection $results, $relation)
    {
        $foreign = $this->foreignKey;

        $dictionary = $this->buildDictionary($results);

        foreach ($models as $model) {
            $matches = [];

            foreach ((array) $model->$foreign as $id) {
                if (isset($dictionary[$id])) {
                    $matches[] = $dictionary[$id];
                }
            }

            $model->setRelation($relation, $this->related->newCollection($matches));

            if ($this->key) {
                $this->hydratePivotRelation($matches, $model);
            }
        }

        return $models;
    }

    /**
     * Build model dictionary keyed by the relation's foreign key.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $results
     * @return array
     */
    protected function buildDictionary(Collection $results)
    {
        $owner = $this->ownerKey;

        $dictionary = [];

        foreach ($results as $result) {
            $dictionary[$result->getAttribute($owner)] = $result;
        }

        return $dictionary;
    }

    /**
     * Add the constraints for a relationship query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \Illuminate\Database\Eloquent\Builder  $parentQuery
     * @param  array|mixed  $columns
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        if ($parentQuery->getQuery()->from == $query->getQuery()->from) {
            return $this->getRelationExistenceQueryForSelfRelation($query, $parentQuery, $columns);
        }

        $ownerKey = $this->relationExistenceQueryOwnerKey($query, $this->ownerKey);

        return $query->select($columns)->whereJsonContains(
            $this->getQualifiedPath(),
            $query->getQuery()->connection->raw($ownerKey)
        );
    }

    /**
     * Add the constraints for a relationship query on the same table.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \Illuminate\Database\Eloquent\Builder  $parentQuery
     * @param  array|mixed  $columns
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getRelationExistenceQueryForSelfRelation(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        $query->from($query->getModel()->getTable().' as '.$hash = $this->getRelationCountHash());

        $query->getModel()->setTable($hash);

        $ownerKey = $this->relationExistenceQueryOwnerKey($query, $hash.'.'.$this->ownerKey);

        return $query->select($columns)->whereJsonContains(
            $this->getQualifiedPath(),
            $query->getQuery()->connection->raw($ownerKey)
        );
    }

    /**
     * Get the owner key for the relationship query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $ownerKey
     * @return string
     */
    protected function relationExistenceQueryOwnerKey(Builder $query, $ownerKey)
    {
        if (! $this->key) {
            return $this->getJsonGrammar($query)->compileJsonArray($query->qualifyColumn($ownerKey));
        }

        $this->addBinding($this->key);

        return $this->getJsonGrammar($query)->compileJsonObject($query->qualifyColumn($ownerKey));
    }
}

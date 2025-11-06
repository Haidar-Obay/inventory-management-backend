<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * Compute the next sequential ID for a model's ID column.
     * Always returns max(id)+1, or 1 when the table is empty. Does not fill gaps.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     */
    protected function computeNextAvailableId(string $modelClass, string $idColumn = 'id'): int
    {
        /** @var \Illuminate\Database\Eloquent\Model $model */
        $model = new $modelClass;

        $maxId = $model->newQuery()->max($idColumn);
        if ($maxId === null) {
            return 1;
        }

        return ((int) $maxId) + 1;
    }
}

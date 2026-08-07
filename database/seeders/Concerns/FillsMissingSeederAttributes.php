<?php

namespace Database\Seeders\Concerns;

use Illuminate\Database\Eloquent\Model;

trait FillsMissingSeederAttributes
{
    /** @param array<string, mixed> $defaults */
    private function fillMissing(Model $model, array $defaults): Model
    {
        if (! $model->exists) {
            $model->fill($defaults);

            return $model;
        }

        foreach ($defaults as $field => $value) {
            $current = $model->getAttribute($field);

            if (($current === null || (is_string($current) && trim($current) === ''))
                && ! ($value === null || (is_string($value) && trim($value) === ''))) {
                $model->setAttribute($field, $value);
            }
        }

        return $model;
    }
}

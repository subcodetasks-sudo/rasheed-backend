<?php

namespace App\Support\Query\Filters;

use Illuminate\Database\Eloquent\Builder;

class FilterQuery
{
    protected Builder $query;
    protected array $allowedFilters;

    public function __construct(Builder $query, array $allowedFilters)
    {
        $this->query = $query;
        $this->allowedFilters = $allowedFilters;
    }

    public function apply(array $filters = [])
    {
        if (empty($filters)) {
            return $this->query;
        }

        foreach ($filters as $key => $value) {
            if (!in_array($key, $this->allowedFilters)) {
                continue;
            }

            if (str_contains($key, '.')) {
                [$relation, $column] = explode('.', $key, 2);

                $this->query->whereHas($relation, function ($q) use ($column, $value) {
                    if (is_array($value)) {
                        $q->whereIn($column, $value);
                    } else {
                        $q->where($column, $value);
                    }
                });
            } else {
                if (is_array($value)) {
                    $this->query->whereIn($key, $value);
                } else {
                    $this->query->where($key, $value);
                }
            }
        }

        return $this->query;
    }
}
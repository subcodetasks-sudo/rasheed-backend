<?php

namespace App\Support\Query\Filters;

use Illuminate\Database\Eloquent\Builder;

class SortQuery
{
    protected Builder $query;
    protected array $allowedSorts;

    public function __construct(Builder $query, array $allowedSorts)
    {
        $this->query = $query;
        $this->allowedSorts = $allowedSorts;
    }

    public function apply(?string $sort)
    {
        if (!$sort) return $this->query;

        $fields = explode(',', $sort);

        foreach ($fields as $field) {
            $direction = 'asc';
            if (str_starts_with($field, '-')) {
                $direction = 'desc';
                $field = ltrim($field, '-');
            }

            if (in_array($field, $this->allowedSorts)) {
                $this->query->orderBy($field, $direction);
            }
        }

        return $this->query;
    }
}
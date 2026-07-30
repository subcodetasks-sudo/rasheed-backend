<?php

namespace App\Support\Query\Filters;

use Illuminate\Database\Eloquent\Builder;

class SearchQuery
{
    protected Builder $query;
    protected array $allowedFields;

    public function __construct(Builder $query, array $allowedFields)
    {
        $this->query = $query;
        $this->allowedFields = $allowedFields;
    }

    public function apply(?string $keyword)
    {
        if (!$keyword || count($this->allowedFields) === 0) {
            return $this->query;
        }

        $this->query->where(function ($q) use ($keyword) {
            foreach ($this->allowedFields as $field) {
                $q->orWhere($field, 'like', "%$keyword%");
            }
        });

        return $this->query;
    }
}
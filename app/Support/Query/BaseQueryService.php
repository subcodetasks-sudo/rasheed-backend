<?php

namespace App\Support\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class BaseQueryService
{
    protected Builder $query;
    protected Request $request;
    protected array $allowedFilters = [];
    protected array $allowedSorts = [];
    protected array $allowedSearch = [];

    public function __construct(Builder $query, Request $request)
    {
        $this->query = $query;
        $this->request = $request;
    }

    public function allowedFilters(array $filters): self
    {
        $this->allowedFilters = $filters;
        return $this;
    }

    public function allowedSorts(array $sorts): self
    {
        $this->allowedSorts = $sorts;
        return $this;
    }

    public function allowedSearch(array $fields): self
    {
        $this->allowedSearch = $fields;
        return $this;
    }

    public function apply(): Builder
    {
        $this->applyFilters();
        $this->applySorts();
        $this->applySearch();

        return $this->query;
    }

    protected function applyFilters()
    {
        foreach ($this->request->get('filter', []) as $key => $value) {
            if (!in_array($key, $this->allowedFilters)) continue;

            if (str_contains($key, '.')) {
                [$relation, $field] = explode('.', $key, 2);
                $this->query->whereHas($relation, fn($q) => $q->where($field, $value));
            } else {
                $this->query->where($key, $value);
            }
        }
    }

    protected function applySorts()
    {
        if ($this->request->filled('sort')) {
            $sorts = explode(',', $this->request->sort);

            foreach ($sorts as $sort) {
                $direction = 'asc';
                if (str_starts_with($sort, '-')) {
                    $direction = 'desc';
                    $sort = ltrim($sort, '-');
                }

                if (in_array($sort, $this->allowedSorts)) {
                    $this->query->orderBy($sort, $direction);
                }
            }
        }
    }

    protected function applySearch()
    {
        if ($this->request->filled('search') && count($this->allowedSearch)) {
            $keywords = $this->request->search;

            if (!is_array($keywords)) {
                $keywords = [$keywords]; 
            }

            $this->query->where(function ($q) use ($keywords) {
                foreach ($keywords as $keyword) {
                    $q->orWhere(function ($q2) use ($keyword) {
                        foreach ($this->allowedSearch as $field) {
                            $q2->orWhere($field, 'like', "%$keyword%");
                        }
                    });
                }
            });
        }
    }
}
<?php

namespace Modules\Project\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CategoryDeleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $categoryId,
        public readonly string $categoryName,
    ) {}
}

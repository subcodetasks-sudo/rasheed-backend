<?php

namespace Modules\Project\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Project\Models\Category;

class CategoryUpdated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Category $category) {}
}

<?php

namespace Modules\User\app\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserDeleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $userUuid,
        public readonly string $userFullName,
    ) {}
}

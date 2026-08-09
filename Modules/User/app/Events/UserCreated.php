<?php

namespace Modules\User\app\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\User\app\Models\User;

class UserCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public User $user) {}
}

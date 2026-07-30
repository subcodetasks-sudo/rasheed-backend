<?php

namespace Modules\Project\Exceptions;

class ProjectCannotBeDeletedException extends BusinessException
{
    public function __construct(string $reason)
    {
        parent::__construct($reason, 422);
    }
}

<?php

namespace Modules\Project\Enums;

enum ProjectStatus: string
{
    case Active = 'active';
    case Stopped = 'stopped';
    case Archived = 'archived';
}

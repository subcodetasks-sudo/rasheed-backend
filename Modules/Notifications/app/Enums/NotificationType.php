<?php

namespace Modules\Notifications\Enums;

enum NotificationType: string
{
    case Activity = 'activity';
    case Success = 'success';
    case Warning = 'warning';
    case Danger = 'danger';
    case Info = 'info';
}

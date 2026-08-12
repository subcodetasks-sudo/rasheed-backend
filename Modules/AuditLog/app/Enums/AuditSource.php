<?php

namespace Modules\AuditLog\Enums;

enum AuditSource: string
{
    case User = 'user';
    case Api = 'api';
}

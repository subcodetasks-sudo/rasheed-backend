<?php

namespace Modules\AuditLog\Enums;

enum AuditAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Archived = 'archived';
    case Restored = 'restored';
    case Saved = 'saved';
    case Contribution = 'contribution';
    case Transfer = 'transfer';
    case Incoming = 'incoming';
    case Outgoing = 'outgoing';
    case Login = 'login';
    case Logout = 'logout';
    case Viewed = 'viewed';
    case Repaid = 'repaid';
    case CarriedForward = 'carried_forward';
    case StatusUpdated = 'status_updated';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

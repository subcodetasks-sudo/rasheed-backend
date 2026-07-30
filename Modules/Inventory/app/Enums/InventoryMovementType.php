<?php

namespace Modules\Inventory\Enums;

enum InventoryMovementType: string
{
    case Incoming = 'incoming';
    case Outgoing = 'outgoing';
}

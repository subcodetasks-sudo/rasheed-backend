<?php

namespace Modules\Inventory\Enums;

enum InventoryBatchSourceType: string
{
    case Opening = 'opening';
    case Incoming = 'incoming';
}

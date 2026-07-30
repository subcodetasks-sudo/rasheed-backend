<?php

namespace Modules\Inventory\Enums;

enum InventoryExpenseType: string
{
    case Operational = 'operational';
    case Administrative = 'administrative';
}

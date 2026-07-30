<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Str;
use Modules\Inventory\Models\InventoryItem;

class InventoryItemCodeGenerator
{
    public function generate(): string
    {
        do {
            $code = (string) Str::ulid();
        } while (InventoryItem::query()->where('code', $code)->exists());

        return $code;
    }
}

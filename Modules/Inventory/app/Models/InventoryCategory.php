<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventory\Database\Factories\InventoryCategoryFactory;

class InventoryCategory extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

    protected static function newFactory(): InventoryCategoryFactory
    {
        return InventoryCategoryFactory::new();
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }
}

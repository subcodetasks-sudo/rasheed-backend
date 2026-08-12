<?php

namespace Modules\Notifications\Rules;

use App\Support\ArabicLocale;
use Modules\Inventory\Models\InventoryItem;
use Modules\Notifications\Contracts\NotificationRule;
use Modules\Notifications\Services\NotificationService;

class InventoryStockNotificationRule implements NotificationRule
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function handles(string $context): bool
    {
        return $context === 'inventory.stock';
    }

    public function evaluate(object $subject, array $contextData = []): void
    {
        if (! $subject instanceof InventoryItem) {
            return;
        }

        $balance = (float) $subject->current_balance;
        $minimum = (float) $subject->minimum_stock_level;
        $previous = array_key_exists('previous_balance', $contextData)
            ? (float) $contextData['previous_balance']
            : null;

        $wasOut = $previous !== null && $previous <= 0;
        $wasLow = $previous !== null && $previous > 0 && $previous <= $minimum;
        $isOut = $balance <= 0;
        $isLow = $balance > 0 && $balance <= $minimum;

        if ($isOut && ! $wasOut) {
            $this->notificationService->notifyDanger(
                ArabicLocale::trans('messages.notification_out_of_stock_title'),
                ArabicLocale::trans('messages.notification_out_of_stock_message', ['name' => $subject->name, 'code' => $subject->code]),
                [
                    'inventory_item_id' => $subject->id,
                    'code' => $subject->code,
                    'current_balance' => $balance,
                    'minimum_stock_level' => $minimum,
                ],
                $subject,
            );

            return;
        }

        if ($isLow && ! $wasLow && ! $wasOut) {
            $this->notificationService->notifyWarning(
                ArabicLocale::trans('messages.notification_low_stock_title'),
                ArabicLocale::trans('messages.notification_low_stock_message', [
                    'name' => $subject->name,
                    'code' => $subject->code,
                    'balance' => $balance,
                    'minimum' => $minimum,
                ]),
                [
                    'inventory_item_id' => $subject->id,
                    'code' => $subject->code,
                    'current_balance' => $balance,
                    'minimum_stock_level' => $minimum,
                ],
                $subject,
            );
        }
    }
}

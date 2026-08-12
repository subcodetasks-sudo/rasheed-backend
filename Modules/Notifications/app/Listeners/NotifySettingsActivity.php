<?php

namespace Modules\Notifications\Listeners;

use App\Support\ArabicLocale;
use Modules\Notifications\Services\NotificationService;
use Modules\Settings\Events\MonthlyEmployeeSettingsUpdated;
use Modules\Settings\Events\SystemGeneralSettingsUpdated;

class NotifySettingsActivity
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function handle(SystemGeneralSettingsUpdated|MonthlyEmployeeSettingsUpdated $event): void
    {
        if ($event instanceof SystemGeneralSettingsUpdated) {
            $this->notificationService->notifyActivity(
                ArabicLocale::trans('messages.notification_general_settings_updated_title'),
                ArabicLocale::trans('messages.notification_general_settings_updated_message'),
                ['action' => 'general_settings_updated'],
            );

            return;
        }

        $this->notificationService->notifyActivity(
            ArabicLocale::trans('messages.notification_monthly_employee_settings_updated_title'),
            ArabicLocale::trans('messages.notification_monthly_employee_settings_updated_message', [
                'month' => $event->month,
                'year' => $event->year,
            ]),
            [
                'action' => 'monthly_employee_settings_updated',
                'month' => $event->month,
                'year' => $event->year,
            ],
        );
    }
}

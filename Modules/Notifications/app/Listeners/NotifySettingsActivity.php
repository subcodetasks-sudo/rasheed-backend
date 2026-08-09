<?php

namespace Modules\Notifications\Listeners;

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
                __('messages.notification_general_settings_updated_title'),
                __('messages.notification_general_settings_updated_message'),
                ['action' => 'general_settings_updated'],
            );

            return;
        }

        $this->notificationService->notifyActivity(
            __('messages.notification_monthly_employee_settings_updated_title'),
            __('messages.notification_monthly_employee_settings_updated_message', [
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

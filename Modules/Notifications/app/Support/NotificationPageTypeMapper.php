<?php

namespace Modules\Notifications\Support;

use Modules\Notifications\Enums\NotificationType;

class NotificationPageTypeMapper
{
    public const URGENT = 'urgent';

    public const NOTIFICATION = 'notification';

    public const INFORMATION = 'information';

    /**
     * @return list<string>
     */
    public static function pageTypes(): array
    {
        return [
            self::URGENT,
            self::NOTIFICATION,
            self::INFORMATION,
        ];
    }

    public static function toPageType(NotificationType|string|null $type): string
    {
        $value = $type instanceof NotificationType ? $type : NotificationType::tryFrom((string) $type);

        return match ($value) {
            NotificationType::Danger => self::URGENT,
            NotificationType::Info => self::INFORMATION,
            default => self::NOTIFICATION,
        };
    }

    /**
     * @return list<string>
     */
    public static function dbTypesForPageType(string $pageType): array
    {
        return match ($pageType) {
            self::URGENT => [NotificationType::Danger->value],
            self::INFORMATION => [NotificationType::Info->value],
            self::NOTIFICATION => [
                NotificationType::Activity->value,
                NotificationType::Success->value,
                NotificationType::Warning->value,
            ],
            default => [],
        };
    }
}

<?php

namespace Modules\Notifications\Support;

use Modules\Notifications\Enums\NotificationType;

class NotificationPageTypeMapper
{
    public const URGENT = 'urgent';

    public const WARNING = 'warning';

    public const INFO = 'info';

    /**
     * @return list<string>
     */
    public static function pageTypes(): array
    {
        return [
            self::URGENT,
            self::WARNING,
            self::INFO,
        ];
    }

    public static function toPageType(NotificationType|string|null $type): string
    {
        $value = $type instanceof NotificationType ? $type : NotificationType::tryFrom((string) $type);

        return match ($value) {
            NotificationType::Danger => self::URGENT,
            NotificationType::Info => self::INFO,
            default => self::WARNING,
        };
    }

    /**
     * @return list<string>
     */
    public static function dbTypesForPageType(string $pageType): array
    {
        return match ($pageType) {
            self::URGENT => [NotificationType::Danger->value],
            self::INFO => [NotificationType::Info->value],
            self::WARNING => [
                NotificationType::Activity->value,
                NotificationType::Success->value,
                NotificationType::Warning->value,
            ],
            default => [],
        };
    }
}

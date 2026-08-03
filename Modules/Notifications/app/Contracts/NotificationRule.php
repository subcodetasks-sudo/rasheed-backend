<?php

namespace Modules\Notifications\Contracts;

interface NotificationRule
{
    public function handles(string $context): bool;

    /**
     * @param  array<string, mixed>  $contextData
     */
    public function evaluate(object $subject, array $contextData = []): void;
}

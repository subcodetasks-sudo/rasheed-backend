<?php

namespace Modules\Notifications\Support;

use Modules\Notifications\Contracts\NotificationRule;

class NotificationRuleRegistry
{
    /**
     * @param  iterable<NotificationRule>  $rules
     */
    public function __construct(
        private readonly iterable $rules,
    ) {}

    /**
     * @param  array<string, mixed>  $contextData
     */
    public function evaluate(string $context, object $subject, array $contextData = []): void
    {
        foreach ($this->rules as $rule) {
            if ($rule->handles($context)) {
                $rule->evaluate($subject, $contextData);
            }
        }
    }
}

<?php

namespace Modules\AuditLog\Http\Middleware;

use App\Support\ArabicLocale;
use Closure;
use Illuminate\Http\Request;
use Modules\AuditLog\Actions\RecordAuditLogAction;
use Modules\AuditLog\Enums\AuditAction;
use Modules\AuditLog\Enums\AuditSource;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RecordViewedAuditLog
{
    /**
     * @var list<string>
     */
    private const ALLOWED_PREFIXES = [
        'projects',
        'categories',
        'daily-journals',
        'inventory',
        'cash-station',
        'cash-fund-expenses',
        'administrative-fund',
        'operational-fund',
        'operational-rate',
        'administration-rates',
        'monthly-summary',
        'reports-center',
        'advanced-reports',
        'dashboard',
        'settings',
        'users',
        'auth/users',
        'administrative-debt-settlements',
        'roles',
    ];

    /**
     * @var list<string>
     */
    private const EXCLUDED_PREFIXES = [
        'audit-logs',
        'my-activity-logs',
        'notifications',
        'media',
        'realtime',
        'auth/login',
        'auth/refresh',
        'auth/logout',
    ];

    public function __construct(
        private readonly RecordAuditLogAction $auditLog,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $request->isMethod('GET') || $response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return;
        }

        if ($request->user() === null) {
            return;
        }

        $rest = $this->pathAfterApiPrefix($request);
        if ($rest === null || $this->matchesPrefix($rest, self::EXCLUDED_PREFIXES)) {
            return;
        }

        if (! $this->matchesPrefix($rest, self::ALLOWED_PREFIXES)) {
            return;
        }

        try {
            $this->auditLog->execute(
                action: AuditAction::Viewed,
                description: $this->descriptionFor($rest),
                source: AuditSource::Api,
                causer: $request->user(),
                properties: [
                    'path' => $request->path(),
                ],
            );
        } catch (Throwable) {
            // Viewing must never fail because audit logging failed.
        }
    }

    private function pathAfterApiPrefix(Request $request): ?string
    {
        $path = trim($request->path(), '/');

        if (! str_starts_with($path, 'api/v1/')) {
            return null;
        }

        return substr($path, strlen('api/v1/'));
    }

    /**
     * @param  list<string>  $prefixes
     */
    private function matchesPrefix(string $rest, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($rest === $prefix || str_starts_with($rest, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    private function descriptionFor(string $rest): string
    {
        $resource = explode('/', $rest)[0];

        if (str_starts_with($rest, 'auth/users')) {
            $resource = 'users';
        }

        return ArabicLocale::trans('messages.audit_viewed', [
            'resource' => ArabicLocale::resource($resource),
        ]);
    }
}

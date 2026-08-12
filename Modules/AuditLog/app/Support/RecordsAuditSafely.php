<?php

namespace Modules\AuditLog\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Modules\AuditLog\Actions\RecordAuditLogAction;
use Modules\AuditLog\Enums\AuditAction;
use Modules\AuditLog\Enums\AuditSource;
use Throwable;

trait RecordsAuditSafely
{
    public function __construct(
        protected RecordAuditLogAction $auditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $properties
     */
    protected function record(
        AuditAction $action,
        string $description,
        AuditSource $source = AuditSource::Api,
        ?Model $subject = null,
        array $properties = [],
    ): void {
        try {
            $this->auditLog->execute(
                action: $action,
                description: $description,
                source: $source,
                subject: $subject,
                properties: $properties,
            );
        } catch (Throwable $e) {
            Log::error('Failed to record audit log from listener.', [
                'action' => $action->value,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    protected function isMutatingPath(string $suffix, array $methods = ['POST', 'PUT', 'PATCH', 'DELETE']): bool
    {
        $request = request();
        if ($request === null) {
            return false;
        }

        $path = trim($request->path(), '/');
        $method = strtoupper($request->method());

        if (! in_array($method, $methods, true)) {
            return false;
        }

        return $path === $suffix || str_starts_with($path, $suffix.'/');
    }

    protected function isExactMutatingPath(string $path, array $methods): bool
    {
        $request = request();
        if ($request === null) {
            return false;
        }

        return trim($request->path(), '/') === $path
            && in_array(strtoupper($request->method()), $methods, true);
    }
}

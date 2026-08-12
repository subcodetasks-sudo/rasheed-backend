<?php

namespace Modules\AuditLog\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\AuditLog\Enums\AuditAction;
use Modules\AuditLog\Enums\AuditSource;
use Throwable;

class RecordAuditLogAction
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function execute(
        AuditAction $action,
        string $description,
        AuditSource $source = AuditSource::Api,
        ?Authenticatable $causer = null,
        ?Model $subject = null,
        array $properties = [],
    ): void {
        $write = function () use ($action, $description, $source, $causer, $subject, $properties): void {
            try {
                $this->write($action, $description, $source, $causer, $subject, $properties);
            } catch (Throwable $e) {
                Log::error('Failed to record audit log.', [
                    'action' => $action->value,
                    'exception' => $e->getMessage(),
                ]);
            }
        };

        if (! app()->runningUnitTests() && DB::transactionLevel() > 0) {
            DB::afterCommit($write);

            return;
        }

        $write();
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function write(
        AuditAction $action,
        string $description,
        AuditSource $source,
        ?Authenticatable $causer,
        ?Model $subject,
        array $properties,
    ): void {
        $causer ??= Auth::user();
        $request = request();

        $payload = array_merge([
            'ip' => $request?->ip(),
            'source' => $source->value,
            'action' => $action->value,
            'actor_name' => $causer->full_name ?? $causer->name ?? null,
        ], $properties);

        $logger = activity(config('auditlog.log_name', 'audit'))
            ->event($action->value)
            ->withProperties($payload);

        if ($causer !== null) {
            $logger->causedBy($causer);
        }

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        $logger->log($description);
    }
}

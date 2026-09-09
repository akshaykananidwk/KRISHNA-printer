<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Session;

/**
 * Writes the audit trail. Every privileged or money-touching action goes
 * through here so there is one place that decides what an audit record looks
 * like — and one place that guarantees secrets never enter it.
 */
final class AuditService
{
    private ?string $actorType = null;

    private ?string $actorId = null;

    private ?string $actorLabel = null;

    private ?string $ip = null;

    private ?string $userAgent = null;

    public function __construct(private Database $db)
    {
    }

    /** Set once per request by the middleware, so callers need not pass it around. */
    public function setActor(string $type, ?string $id, ?string $label): void
    {
        $this->actorType = $type;
        $this->actorId = $id;
        $this->actorLabel = $label;
    }

    public function setRequestContext(string $ip, string $userAgent): void
    {
        $this->ip = $ip;
        $this->userAgent = substr($userAgent, 0, 255);
    }

    /** @param array<string,mixed> $context */
    public function log(
        string $action,
        string $description,
        ?string $subjectType = null,
        ?string $subjectId = null,
        string $severity = 'info',
        array $context = []
    ): void {
        try {
            $this->db->insert('activity_logs', [
                'actor_type' => $this->actorType ?? 'system',
                'actor_id' => $this->actorId,
                'actor_label' => $this->actorLabel,
                'action' => substr($action, 0, 80),
                'subject_type' => $subjectType === null ? null : substr($subjectType, 0, 60),
                'subject_id' => $subjectId === null ? null : substr($subjectId, 0, 64),
                'description' => substr($description, 0, 500),
                'ip_address' => $this->ip,
                'user_agent' => $this->userAgent,
                // Redaction happens here, not at the call site, so a caller
                // cannot accidentally persist a token by forgetting.
                'context' => $context === []
                    ? null
                    : json_encode(Logger::redact($context), JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                'severity' => in_array($severity, ['info', 'notice', 'warning', 'critical'], true) ? $severity : 'info',
            ]);
        } catch (\Throwable $e) {
            // An audit write must never break the operation it is recording,
            // but it must not vanish silently either.
            Logger::error('Failed to write audit log', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }

        if ($severity === 'critical' || $severity === 'warning') {
            Logger::log($severity === 'critical' ? Logger::CRITICAL : Logger::WARNING, $description, [
                'action' => $action,
                'subject' => $subjectType . ':' . $subjectId,
            ]);
        }
    }

    /** Convenience for security-relevant events. @param array<string,mixed> $context */
    public function security(string $action, string $description, array $context = []): void
    {
        $this->log($action, $description, 'security', null, 'warning', $context);
    }

    /** @param array<string,mixed> $context */
    public function critical(string $action, string $description, array $context = []): void
    {
        $this->log($action, $description, 'security', null, 'critical', $context);
    }

    /**
     * Record a field-level change set, with values redacted. Used by the
     * location/printer/settings editors so an operator can see what changed.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    public function logChanges(
        string $action,
        string $subjectType,
        string $subjectId,
        array $before,
        array $after,
        string $label
    ): void {
        $changes = [];
        foreach ($after as $field => $newValue) {
            $oldValue = $before[$field] ?? null;
            if ((string) $oldValue === (string) $newValue) {
                continue;
            }
            $changes[$field] = ['from' => $oldValue, 'to' => $newValue];
        }

        if ($changes === []) {
            return;
        }

        $this->log(
            $action,
            sprintf('Updated %s "%s": %s', $subjectType, $label, implode(', ', array_keys($changes))),
            $subjectType,
            $subjectId,
            'notice',
            ['changes' => $changes]
        );
    }

    /** Attach the current admin (if any) as the actor. */
    public function adoptSessionActor(): void
    {
        $adminId = Session::get('admin_id');
        if ($adminId !== null) {
            $this->setActor('admin', (string) $adminId, (string) Session::get('admin_name', 'Admin'));
        }
    }
}

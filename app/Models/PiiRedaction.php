<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One admin-triggered redaction of a document: the plan, the unredacted
 * values it replaced (kept indefinitely for revert), and the per-step log.
 */
class PiiRedaction extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_PARTIAL = 'partially_applied';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $guarded = [];

    protected $casts = [
        'plan' => 'array',
        'before' => 'array',
        'log' => 'array',
        'applied_at' => 'datetime',
        'reverted_at' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function assessment()
    {
        return $this->belongsTo(PiiAssessment::class, 'assessment_id');
    }

    public function isReverted(): bool
    {
        return $this->reverted_at !== null;
    }

    public function isActive(): bool
    {
        return !$this->isReverted() && $this->text_status === self::STATUS_APPLIED;
    }

    public function isInProgress(): bool
    {
        return !$this->isReverted() && ($this->text_status === self::STATUS_PENDING || $this->files_status === self::STATUS_PENDING);
    }

    public function appendLog(string $step, string $outcome, array $extra = []): void
    {
        $log = $this->log ?? [];
        $log[] = array_merge(['step' => $step, 'outcome' => $outcome, 'at' => now()->toIso8601String()], $extra);
        $this->log = $log;
    }

    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'ootel',
            self::STATUS_APPLIED => 'rakendatud',
            self::STATUS_PARTIAL => 'osaliselt rakendatud',
            self::STATUS_FAILED => 'ebaõnnestus',
            self::STATUS_SKIPPED => 'pole vaja',
            default => (string)$status,
        };
    }
}

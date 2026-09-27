<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One structured-extraction run of a document (per prompt version and model).
 * Written only by the pipeline; never affects what visitors see.
 */
class PiiExtraction extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_NEEDS_OCR = 'needs_ocr';
    public const STATUS_TOO_LARGE = 'too_large';

    public const REQUESTED_BY_WORKER = 'worker';
    public const REQUESTED_BY_ADMIN = 'admin';

    public const MAX_ATTEMPTS = 3;

    protected $guarded = [];

    protected $casts = [
        'flags' => 'array',
        'legal_entities' => 'array',
        'raw_response' => 'array',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function subjects()
    {
        return $this->hasMany(PiiSubject::class, 'extraction_id');
    }

    public function assessments()
    {
        return $this->hasMany(PiiAssessment::class, 'extraction_id');
    }

    public function scopeCurrentVersion(Builder $q): Builder
    {
        return $q->where('prompt_version', \App\Lib\Pii\Extractor::PROMPT_VERSION);
    }

    public function scopeDone(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_DONE);
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->flags ?? [], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'ootel',
            self::STATUS_DONE => 'tehtud',
            self::STATUS_FAILED => 'ebaõnnestus',
            self::STATUS_NEEDS_OCR => 'vajab OCR-i',
            self::STATUS_TOO_LARGE => 'liiga mahukas',
            default => $this->status,
        };
    }
}

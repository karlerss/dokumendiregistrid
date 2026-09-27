<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Rules-based legitimate-interest assessment of one extraction. Advisory:
 * the band and recommendation are shown to the admin, nothing acts on them.
 */
class PiiAssessment extends Model
{
    public const BAND_INFO = 'INFO';
    public const BAND_WARN = 'WARN';
    public const BAND_HIGH = 'HIGH';

    public const RECOMMEND_NONE = 'NONE';
    public const RECOMMEND_REDACT = 'REDACT';
    public const RECOMMEND_REVIEW_WITHHOLD = 'REVIEW_WITHHOLD';

    public const REVIEW_ACKNOWLEDGED = 'acknowledged';
    public const REVIEW_REDACTED = 'redacted';
    public const REVIEW_HIDDEN = 'hidden';
    public const REVIEW_IGNORED = 'ignored';

    protected $guarded = [];

    protected $casts = [
        'subject_actions' => 'array',
        'fired_rules' => 'array',
        'reviewed_at' => 'datetime',
        'computed_at' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function extraction()
    {
        return $this->belongsTo(PiiExtraction::class, 'extraction_id');
    }

    public function scopeUnreviewed(Builder $q): Builder
    {
        return $q->whereNull('reviewed_at');
    }

    public function scopeCurrentVersion(Builder $q): Builder
    {
        return $q->where('rules_version', \App\Lib\Pii\Rules::VERSION);
    }

    public function isReviewed(): bool
    {
        return $this->reviewed_at !== null;
    }

    public function review(string $action, string $by): void
    {
        $this->forceFill([
            'reviewed_at' => now(),
            'reviewed_by' => $by,
            'review_action' => $action,
        ])->save();
    }

    public function bandColor(): string
    {
        return match ($this->band) {
            self::BAND_HIGH => 'red',
            self::BAND_WARN => 'yellow',
            default => 'gray',
        };
    }

    public function bandLabel(): string
    {
        return match ($this->band) {
            self::BAND_HIGH => 'Kõrge',
            self::BAND_WARN => 'Hoiatus',
            default => 'Info',
        };
    }

    public function recommendationLabel(): string
    {
        return match ($this->recommendation) {
            self::RECOMMEND_REDACT => 'Soovitus: redigeeri',
            self::RECOMMEND_REVIEW_WITHHOLD => 'Soovitus: vaata üle, kaalu peitmist',
            default => 'Tegevust ei soovitata',
        };
    }

    /** @return array{name: string, personal_code: string, private_contacts: string, work_contacts: string, property_ids: string}|null */
    public function actionsFor(int $subjectId): ?array
    {
        return $this->subject_actions[(string)$subjectId] ?? $this->subject_actions[$subjectId] ?? null;
    }
}

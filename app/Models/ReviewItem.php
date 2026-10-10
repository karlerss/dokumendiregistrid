<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry in the compliance review queue: a document (or, rarely, a bare
 * R2 object) that something has given us reason to doubt. Flags from every
 * source accumulate on the single open item per document; a human or MCP
 * decision closes it with remove, redact or keep.
 */
class ReviewItem extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_DECIDED = 'decided';

    public const DECISION_REMOVE = 'remove';
    public const DECISION_REDACT = 'redact';
    public const DECISION_KEEP = 'keep';
    public const DECISIONS = [self::DECISION_REMOVE, self::DECISION_REDACT, self::DECISION_KEEP];

    public const SOURCE_LETTER = 'letter';
    public const SOURCE_RECHECK = 'recheck';
    public const SOURCE_TAKEDOWN = 'takedown';
    public const SOURCE_PII = 'pii';
    public const SOURCE_ADMIN = 'haldus'; // a decision taken directly in /haldus
    public const SOURCE_HIDDEN = 'peidetud'; // hidden earlier, files still public

    // Lower is more urgent.
    public const PRIORITY_NAMED = 1;         // personal-data restriction, letter, takedown
    public const PRIORITY_RESTRICTED = 2;
    public const PRIORITY_PII = 3;
    public const PRIORITY_GONE = 4;

    protected $guarded = [];

    protected $casts = [
        'flags' => 'array',
        'decided_at' => 'datetime',
        'first_flagged_at' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function actions()
    {
        return $this->hasMany(ComplianceAction::class)->orderBy('id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * A personal-data matter: some flag says so (a letter or takedown about
     * personal data, a restriction under AvTS § 35 lg 1 p 12), or the source
     * registry currently restricts the document on that basis.
     */
    public function isPersonalData(): bool
    {
        foreach ($this->flags ?? [] as $flag) {
            if (!empty($flag['personal_data'])) {
                return true;
            }
        }
        return (bool)$this->document?->remoteState?->personal_data_restriction;
    }

    /**
     * Who asked: true if any flag came from a public-sector body, false if
     * any came from a private person or company. Flags without a requester
     * (the re-check daemon) count for neither.
     *
     * @return array{public_sector: bool, private: bool}
     */
    public function requesters(): array
    {
        $flags = $this->flags ?? [];
        return [
            'public_sector' => in_array(true, array_column($flags, 'public_sector'), true),
            'private' => in_array(false, array_column($flags, 'public_sector'), true),
        ];
    }

    /** @return string[] */
    public function references(): array
    {
        return array_values(array_unique(array_filter(array_column($this->flags ?? [], 'reference'))));
    }
}

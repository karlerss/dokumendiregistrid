<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Last known state of a document at its source registry, maintained by
 * `app:recheck-daemon`. One row per document that was public at ingest.
 */
class DocumentRemoteState extends Model
{
    public const STATUS_PUBLIC = 'public';
    public const STATUS_RESTRICTED = 'restricted';
    public const STATUS_GONE = 'gone';

    protected $primaryKey = 'document_id';
    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'checked_at' => 'datetime',
        'next_check_at' => 'datetime',
        'personal_data_restriction' => 'boolean',
        'check_error_count' => 'integer',
        'last_http_status' => 'integer',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Never checked, or due again.
     */
    public function scopeDue(Builder $query, $now = null): Builder
    {
        $now ??= now();
        return $query->where(function (Builder $q) use ($now) {
            $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', $now);
        });
    }

    public function statusLabel(): string
    {
        return match ($this->remote_status) {
            self::STATUS_PUBLIC => 'avalik',
            self::STATUS_RESTRICTED => 'piiratud',
            self::STATUS_GONE => 'kadunud',
            default => 'kontrollimata',
        };
    }

    /**
     * Insert missing state rows for recheckable documents (new ingests, or a
     * migration that predates the table). Idempotent.
     */
    public static function reconcile(): int
    {
        return \Illuminate\Support\Facades\DB::affectingStatement(<<<SQL
            insert into document_remote_states (document_id, created_at, updated_at)
            select d.id, current_timestamp, current_timestamp
            from documents d
            left join document_remote_states s on s.document_id = d.id
            where d.restriction = 'Avalik' and s.document_id is null
        SQL);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TakedownRequest extends Model
{
    use HasFactory;

    public const STATUS_UNVERIFIED = 'unverified';
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DENIED = 'denied';

    public const STATUSES = [
        self::STATUS_UNVERIFIED,
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_DENIED,
    ];

    public const LEGAL_BASES = [
        'IKÜM (GDPR) Art 21.',
        'EKTÄKS § 5',
        'Muu (lisa selgitusse)',
    ];

    protected $guarded = [];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function isResolved(): bool
    {
        return in_array($this->status, [self::STATUS_ACCEPTED, self::STATUS_DENIED], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_UNVERIFIED => 'Kinnitamata',
            self::STATUS_PENDING => 'Ootel',
            self::STATUS_ACCEPTED => 'Rahuldatud',
            self::STATUS_DENIED => 'Tagasi lükatud',
            default => $this->status,
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_UNVERIFIED => 'gray',
            self::STATUS_PENDING => 'yellow',
            self::STATUS_ACCEPTED => 'green',
            self::STATUS_DENIED => 'red',
            default => 'gray',
        };
    }
}

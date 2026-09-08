<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DocumentStatusChange extends Model
{
    public const ACTION_HIDDEN = 'hidden';
    public const ACTION_UNHIDDEN = 'unhidden';
    public const ACTION_FILES_DELETED = 'files_deleted';
    public const ACTION_REFETCHED = 'refetched';
    public const ACTION_IGNORED = 'ignored';

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'personal_data' => 'boolean',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function scopeUnacknowledged(Builder $query): Builder
    {
        return $query->whereNull('acknowledged_at');
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }

    public function acknowledge(?string $action = null, ?string $note = null): void
    {
        $this->forceFill([
            'acknowledged_at' => now(),
            'action' => $action ?? $this->action,
            'note' => $note ?? $this->note,
        ])->save();
    }

    public function transitionLabel(): string
    {
        $from = $this->statusLabel($this->from_status);
        $to = $this->statusLabel($this->to_status);
        return "$from → $to";
    }

    public function statusLabel(?string $status): string
    {
        return match ($status) {
            'public' => 'avalik',
            'restricted' => 'piiratud',
            'gone' => 'kadunud',
            null => 'kontrollimata',
            default => $status,
        };
    }

    public function statusColor(): string
    {
        if ($this->personal_data) {
            return 'red';
        }
        return match ($this->to_status) {
            'restricted' => 'yellow',
            'gone' => 'gray',
            'public' => 'green',
            default => 'gray',
        };
    }
}

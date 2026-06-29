<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TakedownRequest extends Model
{
    use HasFactory;

    public const VERIFICATION_CODE_TTL_MINUTES = 30;

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
        'verified_at' => 'datetime',
        'verification_code_expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $takedownRequest) {
            if (empty($takedownRequest->token)) {
                $takedownRequest->token = self::generateToken();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    public static function generateToken(): string
    {
        do {
            $token = Str::random(40);
        } while (self::where('token', $token)->exists());

        return $token;
    }

    public function generateVerificationCode(): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $this->forceFill([
            'verification_code' => $code,
            'verification_code_expires_at' => now()->addMinutes(self::VERIFICATION_CODE_TTL_MINUTES),
        ])->save();

        return $code;
    }

    public function verificationCodeMatches(string $code): bool
    {
        if (empty($this->verification_code)) {
            return false;
        }

        if ($this->verification_code_expires_at && $this->verification_code_expires_at->isPast()) {
            return false;
        }

        return hash_equals($this->verification_code, trim($code));
    }

    public function markVerified(): void
    {
        $this->forceFill([
            'status' => self::STATUS_PENDING,
            'verified_at' => now(),
            'verification_code' => null,
            'verification_code_expires_at' => null,
        ])->save();
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

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

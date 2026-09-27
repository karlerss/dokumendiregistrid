<?php

namespace App\Models;

use App\Lib\Pii\Contexts;
use Illuminate\Database\Eloquent\Model;

/**
 * One natural person found in one extraction, with the exact strings that
 * identify them in the document (verified) and the admin's context override.
 */
class PiiSubject extends Model
{
    protected $guarded = [];

    protected $casts = [
        'surface_forms' => 'array',
        'unverified_forms' => 'array',
        'identifiers' => 'array',
        'subject_flags' => 'array',
        'overridden_at' => 'datetime',
    ];

    public function extraction()
    {
        return $this->belongsTo(PiiExtraction::class, 'extraction_id');
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    /** The context the assessment uses: the admin's override when set. */
    public function effectiveContext(): string
    {
        return $this->context_override ?: $this->context;
    }

    public function effectiveContextLabel(): string
    {
        return Contexts::label($this->effectiveContext());
    }

    public function isKeep(): bool
    {
        return Contexts::isKeep($this->effectiveContext());
    }

    /** Verified surface strings only (what redaction will replace). */
    public function verifiedForms(): array
    {
        return array_values(array_unique(array_map(fn($f) => $f['text'], $this->surface_forms ?? [])));
    }

    public function displayName(): string
    {
        return $this->name ?: ($this->personal_code ? 'isikukood ' . $this->personal_code : 'nimetu');
    }
}

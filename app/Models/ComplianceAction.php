<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only log of every effect a compliance decision had (an R2 object
 * deleted, text cleared, a status change acknowledged, ...). Together with
 * the decision on review_items this is the accountability record (GDPR
 * art 5(2)) AKI asks for. Rows deliberately have no foreign key to
 * documents so they outlive what they describe.
 */
class ComplianceAction extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'result' => 'array',
        'created_at' => 'datetime',
    ];

    public function reviewItem()
    {
        return $this->belongsTo(ReviewItem::class);
    }
}

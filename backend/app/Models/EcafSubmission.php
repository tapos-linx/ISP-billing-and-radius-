<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P1-12: Digital eKYC / eCAF (Electronic Customer Application Form) Model
 */
class EcafSubmission extends Model
{
    use HasFactory;

    protected $table = 'ecaf_submissions';

    protected $primaryKey = 'ecaf_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'ecaf_id',
        'tenant_id',
        'subscriber_id',
        'nid_number',
        'dob',
        'full_name_bangla',
        'full_name_english',
        'father_name',
        'mother_name',
        'permanent_address',
        'present_address',
        'nid_front_path',
        'nid_back_path',
        'live_selfie_path',
        'customer_signature_path',
        'verification_status',
        'btrc_verified_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'dob' => 'date',
            'btrc_verified_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class, 'subscriber_id', 'subscriber_id');
    }
}

<?php
// File: app/Models/Subscriber.php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriberStatus;
use App\Observers\SubscriberObserver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $nas_id
 * @property int $tariff_id
 * @property string $username
 * @property string $pppoe_password
 * @property string $full_name
 * @property string $phone
 * @property string|null $nid_number
 * @property string $pop_division
 * @property string|null $ont_serial
 * @property string $optical_rx_dbm
 * @property string|null $static_ipv4
 * @property int $cgnat_shard_id
 * @property SubscriberStatus $status
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $last_coa_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Nas|null $nas
 * @property-read Tariff $tariff
 * @property-read Collection<int, Invoice> $invoices
 */
#[ObservedBy([SubscriberObserver::class])]
class Subscriber extends Model
{
    use HasFactory;

    protected $table = 'subscribers';

    /** @var list<string> */
    protected $fillable = [
        'nas_id',
        'tariff_id',
        'username',
        'pppoe_password',
        'full_name',
        'phone',
        'nid_number',
        'pop_division',
        'ont_serial',
        'optical_rx_dbm',
        'static_ipv4',
        'cgnat_shard_id',
        'status',
        'expires_at',
        'last_coa_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nas_id'         => 'integer',
            'tariff_id'      => 'integer',
            'optical_rx_dbm' => 'decimal:2',
            'cgnat_shard_id' => 'integer',
            'status'         => SubscriberStatus::class,
            'expires_at'     => 'immutable_datetime',
            'last_coa_at'    => 'immutable_datetime',
            'created_at'     => 'immutable_datetime',
            'updated_at'     => 'immutable_datetime',
        ];
    }

    /**
     * Compute deterministic Citus 128-shard ID via unsigned CRC32 % 128.
     */
    public static function computeShardId(string $username): int
    {
        return (int) (abs(crc32($username)) % 128);
    }

    protected static function booted(): void
    {
        static::saving(function (Subscriber $subscriber): void {
            $subscriber->cgnat_shard_id = self::computeShardId($subscriber->username);
        });
    }

    /**
     * @return BelongsTo<Nas, $this>
     */
    public function nas(): BelongsTo
    {
        return $this->belongsTo(Nas::class, 'nas_id');
    }

    /**
     * @return BelongsTo<Tariff, $this>
     */
    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class, 'tariff_id');
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'subscriber_id');
    }

    public function isActive(): bool
    {
        return $this->status === SubscriberStatus::ACTIVE;
    }

    public function isSuspended(): bool
    {
        return $this->status === SubscriberStatus::SUSPENDED;
    }

    public function isExpired(): bool
    {
        return $this->status === SubscriberStatus::EXPIRED;
    }
}

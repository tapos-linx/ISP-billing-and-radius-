<?php

declare(strict_types=1);

namespace App\Services\Reseller;

use App\Models\ResellerTransaction;
use App\Models\ResellerWallet;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * P1-15: Multi-Tier Reseller Prepaid Wallet & Commission Revenue Split Service
 */
class ResellerWalletService
{
    /**
     * Retrieve or initialize a reseller prepaid wallet
     */
    public function getOrCreateWallet(int $tenantId, int $resellerId, float $commissionRate = 15.0): ResellerWallet
    {
        /** @var ResellerWallet $wallet */
        $wallet = ResellerWallet::withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'reseller_id' => $resellerId,
            ],
            [
                'wallet_id' => (string) Str::uuid(),
                'balance_bdt' => 0.00,
                'credit_limit_bdt' => 5000.00,
                'commission_rate_pct' => $commissionRate,
                'status' => 'active',
                'updated_at' => Carbon::now(),
            ]
        );

        return $wallet;
    }

    /**
     * Top-up reseller wallet funds via bank deposit or MFS collection
     */
    public function topupWallet(string $walletId, float $amountBdt, string $referenceId, string $description = 'Prepaid Wallet Top-up'): ResellerTransaction
    {
        return DB::transaction(function () use ($walletId, $amountBdt, $referenceId, $description): ResellerTransaction {
            /** @var ResellerWallet $wallet */
            $wallet = ResellerWallet::withoutGlobalScopes()->lockForUpdate()->findOrFail($walletId);

            $wallet->balance_bdt += $amountBdt;
            $wallet->updated_at = Carbon::now();
            $wallet->save();

            /** @var ResellerTransaction $tx */
            $tx = ResellerTransaction::withoutGlobalScopes()->create([
                'transaction_id' => (string) Str::uuid(),
                'wallet_id' => $wallet->wallet_id,
                'tenant_id' => $wallet->tenant_id,
                'type' => 'topup',
                'amount_bdt' => $amountBdt,
                'balance_after_bdt' => $wallet->balance_bdt,
                'reference_id' => $referenceId,
                'description' => $description,
                'created_at' => Carbon::now(),
            ]);

            return $tx;
        });
    }

    /**
     * Debit reseller wallet for subscriber package activation/renewal with net commission split
     */
    public function debitForActivation(string $walletId, float $planAmountBdt, string $subscriberId): ResellerTransaction
    {
        return DB::transaction(function () use ($walletId, $planAmountBdt, $subscriberId): ResellerTransaction {
            /** @var ResellerWallet $wallet */
            $wallet = ResellerWallet::withoutGlobalScopes()->lockForUpdate()->findOrFail($walletId);

            // Compute net debit after subtracting reseller commission
            $commissionAmount = round($planAmountBdt * ($wallet->commission_rate_pct / 100.0), 2);
            $netDebit = round($planAmountBdt - $commissionAmount, 2);

            $availableFunds = $wallet->balance_bdt + $wallet->credit_limit_bdt;
            if ($availableFunds < $netDebit) {
                throw new RuntimeException("Insufficient reseller wallet funds. Required: BDT {$netDebit}, Available: BDT {$availableFunds}");
            }

            $wallet->balance_bdt -= $netDebit;
            $wallet->updated_at = Carbon::now();
            $wallet->save();

            /** @var ResellerTransaction $tx */
            $tx = ResellerTransaction::withoutGlobalScopes()->create([
                'transaction_id' => (string) Str::uuid(),
                'wallet_id' => $wallet->wallet_id,
                'tenant_id' => $wallet->tenant_id,
                'type' => 'activation_debit',
                'amount_bdt' => $netDebit,
                'balance_after_bdt' => $wallet->balance_bdt,
                'reference_id' => $subscriberId,
                'description' => "Activation for Subscriber {$subscriberId} (Plan BDT {$planAmountBdt} less {$wallet->commission_rate_pct}% commission BDT {$commissionAmount})",
                'created_at' => Carbon::now(),
            ]);

            return $tx;
        });
    }

    /**
     * Query transaction ledger for a reseller wallet
     *
     * @return Collection<int, ResellerTransaction>
     */
    public function getTransactionHistory(string $walletId, int $limit = 50): Collection
    {
        return ResellerTransaction::withoutGlobalScopes()
            ->where('wallet_id', $walletId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}

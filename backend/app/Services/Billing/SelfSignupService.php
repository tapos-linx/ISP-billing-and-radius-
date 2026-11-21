<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Promotion;
use App\Models\Subscriber;
use App\Models\Tariff;
use Carbon\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * P1-16: Customer Self-Care Online Signup & Promotional Discount Engine
 */
class SelfSignupService
{
    /**
     * Validate and apply a promotional discount code
     *
     * @return array<string, mixed>
     */
    public function applyPromotion(string $promoCode, float $planAmountBdt, int $tenantId): array
    {
        /** @var Promotion|null $promo */
        $promo = Promotion::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('code', strtoupper(trim($promoCode)))
            ->where('active', true)
            ->first();

        if ($promo === null) {
            throw new InvalidArgumentException("Promotional code '{$promoCode}' is invalid or expired.");
        }

        $now = Carbon::now();
        if ($promo->valid_from > $now || $promo->valid_until < $now) {
            throw new InvalidArgumentException("Promotional code '{$promoCode}' is outside its validity window.");
        }

        if ($promo->usage_limit > 0 && $promo->used_count >= $promo->usage_limit) {
            throw new InvalidArgumentException("Promotional code '{$promoCode}' has reached its maximum usage limit.");
        }

        if ($planAmountBdt < $promo->min_plan_amount_bdt) {
            throw new InvalidArgumentException("Plan amount BDT {$planAmountBdt} is below the minimum required BDT {$promo->min_plan_amount_bdt} for this coupon.");
        }

        $discount = 0.0;
        if ($promo->discount_type === 'percentage') {
            $discount = round($planAmountBdt * ($promo->discount_value / 100.0), 2);
        } else {
            $discount = min($planAmountBdt, $promo->discount_value);
        }

        $payable = max(0.0, round($planAmountBdt - $discount, 2));

        return [
            'valid' => true,
            'promo_id' => $promo->promo_id,
            'code' => $promo->code,
            'discount_type' => $promo->discount_type,
            'discount_value' => $promo->discount_value,
            'discount_amount_bdt' => $discount,
            'original_amount_bdt' => $planAmountBdt,
            'payable_amount_bdt' => $payable,
        ];
    }

    /**
     * Complete an online customer self-registration with plan selection and optional coupon
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function registerSelfSubscriber(int $tenantId, array $data): array
    {
        $username = (string) $data['username'];
        $password = (string) $data['password'];
        $tariffId = (int) $data['tariff_id'];

        /** @var Tariff $tariff */
        $tariff = Tariff::withoutGlobalScopes()->findOrFail($tariffId);
        $planPrice = (float) ($tariff->price_bdt ?? 1000.0);

        $discountAmount = 0.0;
        $promoId = null;

        if (! empty($data['promo_code'])) {
            $promoResult = $this->applyPromotion((string) $data['promo_code'], $planPrice, $tenantId);
            $discountAmount = (float) $promoResult['discount_amount_bdt'];
            $promoId = $promoResult['promo_id'];

            // Increment promo usage counter
            Promotion::withoutGlobalScopes()->where('promo_id', $promoId)->increment('used_count');
        }

        $payableAmount = max(0.0, round($planPrice - $discountAmount, 2));

        /** @var Subscriber $subscriber */
        $subscriber = Subscriber::withoutGlobalScopes()->create([
            'subscriber_id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'username' => $username,
            'password' => $password,
            'tariff_id' => $tariffId,
            'status' => 'pending', // Pending initial online payment settlement
            'expiry_date' => Carbon::now()->addDays(30),
            'auto_pay_enabled' => false,
            'pro_rata_balance' => 0.0,
        ]);

        /** @var Invoice $invoice */
        $invoice = Invoice::withoutGlobalScopes()->create([
            'invoice_id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'subscriber_id' => $subscriber->subscriber_id,
            'amount_bdt' => $payableAmount,
            'subtotal_bdt' => $planPrice,
            'vat_rate_percent' => 5.0,
            'vat_amount_bdt' => round($payableAmount * 0.05, 2), // 5% Telecom VAT
            'total_bdt' => round($payableAmount * 1.05, 2),
            'status' => 'issued',
            'due_date' => Carbon::now()->addDays(3),
        ]);

        return [
            'status' => 'success',
            'subscriber_id' => $subscriber->subscriber_id,
            'username' => $subscriber->username,
            'invoice_id' => $invoice->invoice_id,
            'invoice_number' => $invoice->invoice_id,
            'total_payable_bdt' => (float) $invoice->total_bdt,
            'discount_applied_bdt' => $discountAmount,
        ];
    }
}

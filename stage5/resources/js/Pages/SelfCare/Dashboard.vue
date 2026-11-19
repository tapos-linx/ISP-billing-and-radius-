<template>
  <div class="min-h-screen bg-slate-950 text-slate-100 p-4 md:p-8">
    <header class="flex items-center justify-between border-b border-slate-800 pb-4 mb-6">
      <div>
        <h1 class="text-xl font-bold text-white">Subscriber Self-Care Portal</h1>
        <p class="text-sm text-slate-400">PPPoE User: {{ subscriber.username }} ({{ subscriber.status }})</p>
      </div>
      <span class="px-3 py-1 rounded-full text-xs font-semibold"
            :class="subscriber.status === 'active' ? 'bg-emerald-900 text-emerald-300' : 'bg-amber-900 text-amber-300'">
        {{ subscriber.bandwidth_profile }}
      </span>
    </header>

    <section class="grid gap-4 md:grid-cols-2 mb-6">
      <div class="rounded-xl bg-slate-900 border border-slate-800 p-5">
        <h2 class="text-sm font-semibold text-slate-400 uppercase mb-2">Current Invoice (NBR Mushak-6.3)</h2>
        <p class="text-2xl font-bold text-white">BDT {{ latestInvoice.total_bdt }}</p>
        <p class="text-xs text-slate-400 mt-1">
          Base: BDT {{ latestInvoice.subtotal_bdt }} + VAT ({{ latestInvoice.vat_rate_percent }}%): BDT {{ latestInvoice.vat_amount_bdt }}
        </p>
        <div class="mt-4 flex gap-3">
          <button @click="payWith('bkash')" class="px-4 py-2 rounded-lg bg-pink-600 hover:bg-pink-500 text-sm font-semibold">
            Pay with bKash
          </button>
          <button @click="payWith('nagad')" class="px-4 py-2 rounded-lg bg-orange-600 hover:bg-orange-500 text-sm font-semibold">
            Pay with Nagad
          </button>
        </div>
      </div>

      <div class="rounded-xl bg-slate-900 border border-slate-800 p-5">
        <h2 class="text-sm font-semibold text-slate-400 uppercase mb-2">Security &amp; 2FA</h2>
        <p class="text-sm text-slate-300">Two-Factor Authentication: <strong>{{ twoFactorEnabled ? 'Enabled (TOTP)' : 'Disabled' }}</strong></p>
        <button @click="$inertia.post('/self-care/2fa/toggle')" class="mt-4 px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-sm font-semibold">
          {{ twoFactorEnabled ? 'Manage 2FA' : 'Enable 2FA' }}
        </button>
      </div>
    </section>
  </div>
</template>

<script setup>
import { router } from '@inertiajs/vue3';

const props = defineProps({
  subscriber: Object,
  latestInvoice: Object,
  twoFactorEnabled: Boolean,
});

function payWith(gateway) {
  router.post(`/self-care/pay/${gateway}`, { invoice_id: props.latestInvoice.id });
}
</script>

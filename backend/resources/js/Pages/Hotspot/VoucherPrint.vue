<template>
  <div class="min-h-screen bg-slate-950 text-slate-100 p-4 sm:p-8 font-sans print:bg-white print:p-0 print:text-black">
    <!-- Non-Printable Header Actions -->
    <header class="max-w-4xl mx-auto mb-6 flex items-center justify-between print:hidden">
      <div>
        <h1 class="text-xl font-bold text-white flex items-center gap-2">
          <span>80mm POS Thermal Voucher Batch Print</span>
          <span class="text-xs font-mono bg-cyan-900/50 text-cyan-300 border border-cyan-700/60 px-2.5 py-0.5 rounded">
            Batch: {{ batchId }}
          </span>
        </h1>
        <p class="text-xs text-slate-400 mt-1">
          Showing {{ vouchers.length }} vouchers ready for thermal printing roll or retail card cutting.
        </p>
      </div>
      <div class="flex items-center gap-3">
        <a 
          href="/noc/dashboard" 
          class="px-3.5 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs font-semibold text-slate-300 transition-colors"
        >
          ← Back to NOC
        </a>
        <button 
          @click="triggerPrint"
          class="px-5 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-xs font-bold shadow-lg shadow-cyan-600/30 flex items-center gap-2 transition-all"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
          </svg>
          <span>Print Receipts (Ctrl + P)</span>
        </button>
      </div>
    </header>

    <!-- Voucher Cards Grid / Thermal Stream -->
    <div class="max-w-4xl mx-auto grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6 print:block print:max-w-none">
      <div 
        v-for="v in vouchers" 
        :key="v.voucher_id || v.code" 
        class="bg-white text-slate-900 p-5 rounded-xl border border-slate-300 shadow-sm font-mono text-center flex flex-col justify-between print:w-[72mm] print:border-b-2 print:border-dashed print:border-black print:rounded-none print:shadow-none print:p-3 print:my-2 print:break-inside-avoid"
      >
        <!-- Card Header -->
        <div class="border-b border-dashed border-slate-400 pb-2 mb-3">
          <div class="text-xs font-black tracking-wider uppercase">{{ hotspotName }}</div>
          <div class="text-[10px] text-slate-600">HIGH-SPEED BROADBAND PASS</div>
          <div class="text-[9px] text-slate-500">SSID: Carrier-WiFi-Hotspot</div>
        </div>

        <!-- Voucher Code Section -->
        <div class="my-2">
          <div class="text-[10px] uppercase font-bold text-slate-500">VOUCHER CODE / PIN</div>
          <div class="text-xl font-extrabold tracking-widest text-black bg-slate-100 py-1.5 px-2 my-1 rounded border border-slate-300">
            {{ v.code }}
          </div>
          <div v-if="v.pin" class="text-[11px] text-slate-700">
            PIN: <span class="font-bold">{{ v.pin }}</span>
          </div>
        </div>

        <!-- QR Code Placeholder Simulation -->
        <div class="my-2 flex justify-center">
          <div class="w-20 h-20 bg-slate-100 border border-slate-300 rounded flex flex-col items-center justify-center p-1">
            <svg class="w-12 h-12 text-slate-800" fill="currentColor" viewBox="0 0 24 24">
              <path d="M2 2h8v8H2V2zm2 2v4h4V4H4zm10-2h8v8h-8V2zm2 2v4h4V4h-4zM2 14h8v8H2v-8zm2 2v4h4v-4H4zm8-2h2v2h-2v-2zm4 0h2v2h-2v-2zm-4 4h2v2h-2v-2zm4 0h2v2h-2v-2zm2-2h2v2h-2v-2zm0 4h2v2h-2v-2zm2-2h2v2h-2v-2z" />
            </svg>
            <span class="text-[8px] font-bold text-slate-600">SCAN TO LOGIN</span>
          </div>
        </div>

        <!-- Limits & Price Breakdown -->
        <div class="border-t border-dashed border-slate-400 pt-2 my-2 text-[10px] space-y-1 text-left">
          <div class="flex justify-between">
            <span class="text-slate-600">Validity:</span>
            <span class="font-bold">{{ formatSeconds(v.time_limit_seconds) }}</span>
          </div>
          <div class="flex justify-between">
            <span class="text-slate-600">Data Limit:</span>
            <span class="font-bold">{{ formatBytes(v.data_limit_bytes) }}</span>
          </div>
          <div class="flex justify-between">
            <span class="text-slate-600">Speed (Up/Down):</span>
            <span class="font-bold">{{ (v.rate_limit_down_kbps / 1024).toFixed(0) }} Mbps</span>
          </div>
          <div class="flex justify-between border-t border-slate-200 pt-1 text-[11px]">
            <span class="font-bold">Total Price:</span>
            <span class="font-extrabold text-black">BDT {{ Number(v.price_bdt).toFixed(2) }}</span>
          </div>
          <div class="text-[9px] text-slate-500 text-center pt-0.5">
            (Includes 5% NBR Mushak-6.3 VAT)
          </div>
        </div>

        <!-- Instructions Footer -->
        <div class="border-t border-slate-300 pt-1.5 text-[8px] text-slate-500 text-center">
          <div>Connect Wi-Fi → Open Browser → Enter PIN</div>
          <div class="mt-0.5 font-bold">Helpline: {{ supportHelpline }}</div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  batchId: { type: String, default: 'DEFAULT' },
  vouchers: { type: Array, default: () => [] },
  count: { type: Number, default: 0 },
  hotspotName: { type: String, default: 'Carrier Gigabit Wi-Fi' },
  supportHelpline: { type: String, default: '16222' },
  vatRatePercent: { type: Number, default: 5.0 }
})

function formatBytes(bytes) {
  if (!bytes || bytes === 0) return 'Unlimited'
  const gb = bytes / (1024 * 1024 * 1024)
  if (gb >= 1) return `${gb.toFixed(1)} GB`
  const mb = bytes / (1024 * 1024)
  return `${mb.toFixed(0)} MB`
}

function formatSeconds(seconds) {
  if (!seconds || seconds === 0) return 'Unlimited'
  if (seconds >= 86400) return `${Math.floor(seconds / 86400)} Day(s)`
  if (seconds >= 3600) return `${Math.floor(seconds / 3600)} Hour(s)`
  return `${Math.floor(seconds / 60)} Mins`
}

function triggerPrint() {
  window.print()
}
</script>

<style scoped>
@media print {
  body {
    background-color: white !important;
  }
}
</style>

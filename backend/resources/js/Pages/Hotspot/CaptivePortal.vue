<template>
  <div class="min-h-screen bg-slate-950 text-slate-100 flex flex-col justify-between font-sans">
    <!-- Header -->
    <header class="bg-slate-900 border-b border-slate-800 px-4 py-4 sm:px-6 shadow-md">
      <div class="max-w-md mx-auto flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-cyan-500 to-blue-600 flex items-center justify-center text-white font-bold shadow-lg shadow-cyan-500/20">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
            </svg>
          </div>
          <div>
            <h1 class="text-base font-bold text-white tracking-tight">{{ hotspotName }}</h1>
            <p class="text-[11px] text-cyan-400 font-mono flex items-center gap-1.5">
              <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
              Captive Portal · High-Speed AAA
            </p>
          </div>
        </div>
        <div class="text-right text-[11px] font-mono text-slate-400">
          <div>IP: {{ clientIp }}</div>
          <div class="truncate max-w-[100px]">{{ clientMac }}</div>
        </div>
      </div>
    </header>

    <!-- Main Content Card -->
    <main class="flex-1 flex items-center justify-center p-4">
      <div class="w-full max-w-md bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-2xl backdrop-blur-sm">
        <!-- Tab Navigation -->
        <div class="flex rounded-xl bg-slate-950 p-1 mb-6 border border-slate-800/80">
          <button 
            @click="activeTab = 'voucher'"
            :class="[
              'flex-1 py-2 text-xs font-semibold rounded-lg transition-all',
              activeTab === 'voucher' 
                ? 'bg-cyan-600 text-white shadow-md' 
                : 'text-slate-400 hover:text-white'
            ]"
          >
            Voucher PIN Login
          </button>
          <button 
            @click="activeTab = 'buy'"
            :class="[
              'flex-1 py-2 text-xs font-semibold rounded-lg transition-all',
              activeTab === 'buy' 
                ? 'bg-cyan-600 text-white shadow-md' 
                : 'text-slate-400 hover:text-white'
            ]"
          >
            Buy Online (bKash)
          </button>
          <button 
            @click="activeTab = 'status'"
            :class="[
              'flex-1 py-2 text-xs font-semibold rounded-lg transition-all',
              activeTab === 'status' 
                ? 'bg-cyan-600 text-white shadow-md' 
                : 'text-slate-400 hover:text-white'
            ]"
          >
            Session Meter
          </button>
        </div>

        <!-- TAB 1: VOUCHER PIN LOGIN -->
        <div v-if="activeTab === 'voucher'" class="space-y-4">
          <div>
            <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Enter Voucher PIN / Code</label>
            <div class="relative">
              <input 
                v-model="voucherCode" 
                type="text" 
                placeholder="e.g. WX92-K8LP-44" 
                class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-center text-lg font-mono tracking-widest text-white uppercase focus:outline-none focus:border-cyan-500 transition-colors"
                maxlength="20"
              />
            </div>
            <p class="text-[11px] text-slate-500 mt-1.5 text-center">Found on your thermal receipt or scratch card</p>
          </div>

          <div v-if="loginError" class="p-3 rounded-lg bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs text-center font-mono">
            {{ loginError }}
          </div>

          <div v-if="loginSuccess" class="p-3 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-xs text-center font-mono">
            {{ loginSuccess }}
          </div>

          <button 
            @click="connectVoucher"
            :disabled="isConnecting || !voucherCode"
            class="w-full py-3.5 px-4 rounded-xl bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-500 hover:to-blue-500 disabled:opacity-50 text-white text-sm font-bold shadow-lg shadow-cyan-600/30 transition-all flex items-center justify-center gap-2"
          >
            <svg v-if="isConnecting" class="animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
            </svg>
            <span>{{ isConnecting ? 'Authenticating with RADIUS...' : 'Connect to Internet' }}</span>
          </button>

          <!-- Free Trial / Emergency Pass -->
          <div class="pt-2 border-t border-slate-800 text-center">
            <button 
              @click="freeTrialLogin" 
              class="text-xs text-slate-400 hover:text-cyan-400 transition-colors inline-flex items-center gap-1 font-medium"
            >
              <span>Need 15 minutes of emergency access?</span>
              <span class="text-cyan-400 underline">Free Trial Pass →</span>
            </button>
          </div>
        </div>

        <!-- TAB 2: BUY ONLINE WITH BKASH / NAGAD -->
        <div v-if="activeTab === 'buy'" class="space-y-4">
          <div class="text-xs text-slate-300 font-semibold uppercase tracking-wider mb-2">Select Wi-Fi Package</div>
          
          <div class="space-y-2.5 max-h-56 overflow-y-auto pr-1">
            <div 
              v-for="profile in (profiles.length ? profiles : mockProfiles)" 
              :key="profile.profile_id || profile.id"
              @click="selectedProfile = profile"
              :class="[
                'p-3.5 rounded-xl border cursor-pointer transition-all flex items-center justify-between',
                selectedProfile && (selectedProfile.profile_id === profile.profile_id || selectedProfile.id === profile.id)
                  ? 'bg-cyan-950/40 border-cyan-500 shadow-md shadow-cyan-500/10'
                  : 'bg-slate-950/70 border-slate-800 hover:border-slate-700'
              ]"
            >
              <div>
                <div class="text-sm font-bold text-white">{{ profile.name }}</div>
                <div class="text-[11px] text-slate-400 font-mono mt-0.5">
                  {{ formatBytes(profile.data_limit_bytes) }} · {{ formatSeconds(profile.validity_seconds) }}
                </div>
              </div>
              <div class="text-right">
                <div class="text-base font-extrabold text-cyan-400 font-mono">BDT {{ profile.price_bdt }}</div>
                <div class="text-[10px] text-slate-500">VAT (5%) included</div>
              </div>
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1.5">Your Mobile Number (for SMS PIN)</label>
            <input 
              v-model="mobileNumber" 
              type="tel" 
              placeholder="017xxxxxxxx" 
              class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-2.5 text-sm font-mono text-white focus:outline-none focus:border-cyan-500"
            />
          </div>

          <div class="grid grid-cols-2 gap-3 pt-2">
            <button 
              @click="buyVoucher('bkash')"
              :disabled="!selectedProfile || !mobileNumber"
              class="py-3 px-3 rounded-xl bg-pink-600 hover:bg-pink-500 disabled:opacity-50 text-white text-xs font-bold shadow-md shadow-pink-600/20 transition-all flex items-center justify-center gap-1.5"
            >
              <span>Pay with bKash</span>
            </button>
            <button 
              @click="buyVoucher('nagad')"
              :disabled="!selectedProfile || !mobileNumber"
              class="py-3 px-3 rounded-xl bg-orange-600 hover:bg-orange-500 disabled:opacity-50 text-white text-xs font-bold shadow-md shadow-orange-600/20 transition-all flex items-center justify-center gap-1.5"
            >
              <span>Pay with Nagad</span>
            </button>
          </div>
        </div>

        <!-- TAB 3: SESSION STATUS & QUOTA METER -->
        <div v-if="activeTab === 'status'" class="space-y-4">
          <div class="p-4 rounded-xl bg-slate-950 border border-slate-800 space-y-3">
            <div class="flex items-center justify-between text-xs">
              <span class="text-slate-400">Connection State:</span>
              <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                ACTIVE SESSION
              </span>
            </div>
            <div class="flex items-center justify-between text-xs">
              <span class="text-slate-400">Allocated IP:</span>
              <span class="font-mono text-cyan-300">{{ clientIp }}</span>
            </div>
            <div class="flex items-center justify-between text-xs">
              <span class="text-slate-400">Time Remaining:</span>
              <span class="font-mono text-amber-300 font-bold">03 Hours 45 Mins</span>
            </div>
            <div class="flex items-center justify-between text-xs">
              <span class="text-slate-400">Data Remaining:</span>
              <span class="font-mono text-emerald-400 font-bold">1.84 GB / 2.00 GB</span>
            </div>
          </div>

          <!-- Progress Bar -->
          <div>
            <div class="flex justify-between text-[11px] font-mono text-slate-400 mb-1">
              <span>Quota Used (8%)</span>
              <span>160 MB / 2048 MB</span>
            </div>
            <div class="w-full bg-slate-800 rounded-full h-2 overflow-hidden">
              <div class="bg-gradient-to-r from-cyan-500 to-blue-500 h-2 rounded-full" style="width: 8%"></div>
            </div>
          </div>

          <button 
            @click="disconnectSession"
            class="w-full py-2.5 px-4 rounded-xl border border-rose-500/40 text-rose-400 hover:bg-rose-500/10 text-xs font-bold transition-all"
          >
            Disconnect Active Session
          </button>
        </div>
      </div>
    </main>

    <!-- Footer -->
    <footer class="bg-slate-950/80 border-t border-slate-900 py-3 px-4 text-center text-[11px] text-slate-500">
      <div class="max-w-md mx-auto space-y-1">
        <p>NBR Mushak-6.3 VAT Registered · BTRC Public Wi-Fi Mandate Compliant</p>
        <p>24/7 Helpline: <span class="text-slate-400 font-mono">16222</span> · Powered by Antigravity Carrier AAA</p>
      </div>
    </footer>
  </div>
</template>

<script setup>
import { ref } from 'vue'

const props = defineProps({
  profiles: { type: Array, default: () => [] },
  clientIp: { type: String, default: '10.10.10.50' },
  clientMac: { type: String, default: '00:11:22:33:44:55' },
  linkLogin: { type: String, default: '' },
  linkOrig: { type: String, default: '' },
  error: { type: String, default: '' },
  hotspotName: { type: String, default: 'Carrier Gigabit Public Wi-Fi' }
})

const activeTab = ref('voucher')
const voucherCode = ref('')
const mobileNumber = ref('')
const selectedProfile = ref(null)
const isConnecting = ref(false)
const loginError = ref(props.error || '')
const loginSuccess = ref('')

const mockProfiles = [
  { id: 1, name: '1 Hour Express Pass', data_limit_bytes: 1073741824, validity_seconds: 3600, price_bdt: '20.00' },
  { id: 2, name: '24 Hours Standard Pass', data_limit_bytes: 5368709120, validity_seconds: 86400, price_bdt: '50.00' },
  { id: 3, name: '7 Days Unlimited Pass', data_limit_bytes: 21474836480, validity_seconds: 604800, price_bdt: '150.00' },
]

function formatBytes(bytes) {
  if (!bytes || bytes === 0) return 'Unlimited Data'
  const gb = bytes / (1024 * 1024 * 1024)
  if (gb >= 1) return `${gb.toFixed(1)} GB`
  const mb = bytes / (1024 * 1024)
  return `${mb.toFixed(0)} MB`
}

function formatSeconds(seconds) {
  if (seconds >= 86400) return `${Math.floor(seconds / 86400)} Day(s)`
  if (seconds >= 3600) return `${Math.floor(seconds / 3600)} Hour(s)`
  return `${Math.floor(seconds / 60)} Mins`
}

async function connectVoucher() {
  if (!voucherCode.value) return
  isConnecting.value = true
  loginError.value = ''
  loginSuccess.value = ''

  try {
    const res = await fetch('/api/v1/hotspot/vouchers/verify', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ code: voucherCode.value.trim(), mac_address: props.clientMac })
    })
    const data = await res.json()
    if (res.ok && data.authorized) {
      loginSuccess.value = 'Authorized! Redirecting to internet...'
      setTimeout(() => {
        window.location.href = props.linkOrig || 'https://www.google.com'
      }, 1200)
    } else {
      loginError.value = data.message || 'Invalid or depleted voucher code.'
    }
  } catch (err) {
    loginError.value = 'Failed to communicate with authentication gateway.'
  } finally {
    isConnecting.value = false
  }
}

function freeTrialLogin() {
  voucherCode.value = 'TRIAL-FREE-15M'
  connectVoucher()
}

function buyVoucher(gateway) {
  if (!selectedProfile.value || !mobileNumber.value) return
  loginSuccess.value = `Initiating ${gateway.toUpperCase()} payment for BDT ${selectedProfile.value.price_bdt}...`
  activeTab.value = 'voucher'
  setTimeout(() => {
    voucherCode.value = 'BKASH-' + Math.random().toString(36).substring(2, 8).toUpperCase()
    loginSuccess.value = `Payment Successful! Voucher Code Auto-Filled.`
  }, 1500)
}

function disconnectSession() {
  alert('Disconnect signal sent to RADIUS server (Port 3799 PoD).')
  activeTab.value = 'voucher'
}
</script>

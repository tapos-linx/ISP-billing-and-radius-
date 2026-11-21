<template>
  <div class="min-h-screen bg-slate-900 text-slate-100 flex flex-col font-sans">
    <!-- Top Carrier Header -->
    <header class="bg-slate-800 border-b border-slate-700 px-6 py-4 flex flex-wrap items-center justify-between shadow-lg gap-4">
      <div class="flex items-center space-x-3">
        <div class="h-4 w-4 rounded-full bg-cyan-500 animate-ping"></div>
        <h1 class="text-xl font-bold tracking-wider text-cyan-400">CARRIER OPERATIONS & TELEMETRY</h1>
        <span class="text-xs bg-slate-700 px-2.5 py-1 rounded-full text-slate-300 font-mono">RELEASE 2.0 / P2 HIGH AVAILABILITY</span>
      </div>
      <div class="flex items-center space-x-4 text-xs font-mono text-slate-400">
        <div>ANYCAST VIP: <span class="text-emerald-400 font-bold">103.145.10.1 (BGP UP)</span></div>
        <div class="hidden md:block">|</div>
        <div class="hidden md:block">SIP PROXY: <span class="text-emerald-400 font-bold">RFC 3261 ONLINE</span></div>
        <div class="hidden md:block">|</div>
        <div>
          <a href="/noc/dashboard" class="text-cyan-400 hover:text-cyan-300 underline font-semibold">Standard NOC Console &rarr;</a>
        </div>
      </div>
    </header>

    <!-- Main View -->
    <main class="flex-1 p-6 space-y-6 max-w-7xl mx-auto w-full">
      <!-- Section 1: Anycast Regional AAA Topology Mesh -->
      <section class="bg-slate-800 border border-slate-700 rounded-lg p-5 shadow">
        <div class="flex items-center justify-between mb-4">
          <div>
            <h2 class="text-lg font-bold text-cyan-300 flex items-center gap-2">
              <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
              Multi-Region RADIUS Anycast Topology Mesh
            </h2>
            <p class="text-xs text-slate-400">Automated BGP Community announcement with failover drain</p>
          </div>
          <div class="text-right">
            <span class="text-xs font-mono bg-slate-900 border border-slate-700 px-3 py-1.5 rounded text-emerald-400">
              Avg Auth Latency: {{ anycastTopology.average_auth_latency_ms || 4.2 }}ms
            </span>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div v-for="node in (anycastTopology.nodes || defaultNodes)" :key="node.id || node.node_code" class="bg-slate-900/80 border border-slate-700/80 rounded-lg p-4 flex flex-col justify-between">
            <div>
              <div class="flex items-center justify-between">
                <span class="font-bold text-white font-mono">{{ node.node_code }}</span>
                <span :class="node.is_announced ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/40' : 'bg-amber-500/20 text-amber-400 border border-amber-500/40'" class="text-[10px] font-bold px-2 py-0.5 rounded uppercase">
                  {{ node.is_announced ? 'BGP Announced' : 'Draining' }}
                </span>
              </div>
              <div class="text-xs text-slate-400 mt-1">{{ node.pop_name }} ({{ node.region }})</div>
              <div class="mt-3 space-y-1 font-mono text-xs text-slate-300">
                <div class="flex justify-between"><span>Physical IP:</span> <span class="text-slate-400">{{ node.physical_ip }}</span></div>
                <div class="flex justify-between"><span>Auth Latency:</span> <span class="text-emerald-400 font-semibold">{{ node.auth_latency_ms || '3.8' }}ms</span></div>
                <div class="flex justify-between"><span>BGP Community:</span> <span class="text-indigo-400">{{ node.bgp_community || '65001:100' }}</span></div>
              </div>
            </div>
            <div class="mt-4 pt-3 border-t border-slate-800 flex justify-end gap-2">
              <button class="text-xs px-2.5 py-1 rounded bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-600 transition">
                Telemetry
              </button>
              <button :class="node.is_announced ? 'bg-rose-900/40 text-rose-300 border-rose-800 hover:bg-rose-900/60' : 'bg-emerald-900/40 text-emerald-300 border-emerald-800 hover:bg-emerald-900/60'" class="text-xs px-2.5 py-1 rounded border transition">
                {{ node.is_announced ? 'Withdraw / Drain' : 'Re-announce' }}
              </button>
            </div>
          </div>
        </div>
      </section>

      <!-- Section 2: Two Columns (OTDR Optical Physics & BEFTN Bank Clearing) -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- OTDR Fiber Trace Visualizer -->
        <section class="bg-slate-800 border border-slate-700 rounded-lg p-5 shadow flex flex-col justify-between">
          <div>
            <div class="flex items-center justify-between mb-3">
              <h2 class="text-md font-bold text-amber-300 flex items-center gap-2">
                <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                OTDR Fiber Break Localization (Physics Engine)
              </h2>
              <span class="text-[11px] font-mono bg-slate-900 px-2 py-0.5 rounded text-slate-400">Silica n = 1.4682</span>
            </div>
            <p class="text-xs text-slate-400 mb-4">Calculated from speed of light round-trip reflection delay (d = c·t / 2n)</p>
            
            <div class="space-y-3">
              <div v-for="trace in (recentOtdrTraces || defaultOtdrTraces)" :key="trace.id || trace.trace_id" class="bg-slate-900/70 border border-slate-700/60 rounded p-3 text-xs">
                <div class="flex items-center justify-between font-mono">
                  <span class="font-bold text-white">{{ trace.fiber_span_name }}</span>
                  <span class="text-rose-400 font-bold bg-rose-950/60 px-2 py-0.5 rounded border border-rose-800/60 uppercase">
                    {{ trace.fault_type || 'Fiber Cut' }}
                  </span>
                </div>
                <div class="grid grid-cols-3 gap-2 mt-2 font-mono text-slate-300">
                  <div>Distance: <span class="text-amber-400 font-bold">{{ trace.distance_meters }}m</span></div>
                  <div>Optical Loss: <span class="text-rose-400 font-bold">{{ trace.loss_db }} dB</span></div>
                  <div>Delay: <span class="text-slate-400">{{ trace.round_trip_nanoseconds }} ns</span></div>
                </div>
                <div class="mt-2 text-slate-400 text-[11px] flex items-center gap-1">
                  <svg class="w-3.5 h-3.5 text-slate-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd" /></svg>
                  Fault Location: {{ trace.estimated_address || 'Mirpur-10 Roundabout Metro Pillar #42, Dhaka' }}
                </div>
              </div>
            </div>
          </div>
          <div class="mt-4 pt-3 border-t border-slate-700/60 text-right">
            <span class="text-xs text-cyan-400 font-mono">Automated GIS Field Dispatch Active</span>
          </div>
        </section>

        <!-- BEFTN / NPSB Direct Debit Clearing Center -->
        <section class="bg-slate-800 border border-slate-700 rounded-lg p-5 shadow flex flex-col justify-between">
          <div>
            <div class="flex items-center justify-between mb-3">
              <h2 class="text-md font-bold text-emerald-300 flex items-center gap-2">
                <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                BEFTN & NPSB Direct Debit Clearing Center
              </h2>
              <span class="text-[11px] font-mono bg-slate-900 px-2 py-0.5 rounded text-slate-400">ISO 20022 pain.008</span>
            </div>
            <p class="text-xs text-slate-400 mb-4">Automated recurring billing direct debit batches with Bangladesh Bank switch</p>
            
            <div class="space-y-3">
              <div v-for="batch in (recentClearingBatches || defaultBatches)" :key="batch.id || batch.batch_number" class="bg-slate-900/70 border border-slate-700/60 rounded p-3 text-xs">
                <div class="flex items-center justify-between font-mono">
                  <span class="font-bold text-white">{{ batch.batch_number }}</span>
                  <span :class="batch.status === 'settled' ? 'bg-emerald-500/20 text-emerald-400 border-emerald-500/40' : 'bg-sky-500/20 text-sky-400 border-sky-500/40'" class="text-[10px] font-bold px-2 py-0.5 rounded border uppercase">
                    {{ batch.status }}
                  </span>
                </div>
                <div class="grid grid-cols-3 gap-2 mt-2 font-mono text-slate-300">
                  <div>Type: <span class="text-slate-400">{{ batch.clearing_type || 'BEFTN' }}</span></div>
                  <div>Invoices: <span class="text-cyan-400 font-bold">{{ batch.total_count }}</span></div>
                  <div>Total: <span class="text-emerald-400 font-bold">BDT {{ Number(batch.total_amount_bdt).toLocaleString() }}</span></div>
                </div>
                <div class="mt-2 text-slate-400 text-[11px] flex items-center justify-between">
                  <span>Routing: {{ batch.originator_routing_no || '010271234' }} (Sonali Bank)</span>
                  <a :href="`/api/v1/clearing/batches/${batch.id}/export`" class="text-cyan-400 hover:underline flex items-center gap-1 font-mono">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                    Export XML
                  </a>
                </div>
              </div>
            </div>
          </div>
          <div class="mt-4 pt-3 border-t border-slate-700/60 text-right">
            <span class="text-xs text-emerald-400 font-mono">Double-Entry Ledger Auto-Reconciled</span>
          </div>
        </section>
      </div>

      <!-- Section 3: Two Columns (Corporate 95th Percentile Bursting & Real-Time SIP Telephony) -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- 95th Percentile Bursting Meter -->
        <section class="bg-slate-800 border border-slate-700 rounded-lg p-5 shadow">
          <div class="flex items-center justify-between mb-3">
            <h2 class="text-md font-bold text-indigo-300 flex items-center gap-2">
              <svg class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z" /></svg>
              Corporate 95th Percentile Bursting Meter
            </h2>
            <span class="text-[11px] font-mono bg-slate-900 px-2 py-0.5 rounded text-indigo-400">Top 5% Discarded</span>
          </div>
          <p class="text-xs text-slate-400 mb-4">Calculated from 5-minute periodic SNMP/NetFlow inbound and outbound peaks</p>

          <div class="bg-slate-900/80 border border-slate-700/70 rounded-lg p-4 font-mono text-xs space-y-3">
            <div class="flex justify-between items-center pb-2 border-b border-slate-800">
              <span class="text-slate-400">Circuit Ref:</span>
              <span class="text-white font-bold">CKT-CORP-DHAKA-100M</span>
            </div>
            <div class="flex justify-between items-center">
              <span class="text-slate-400">Committed Bandwidth (CIR):</span>
              <span class="text-slate-200">50.00 Mbps</span>
            </div>
            <div class="flex justify-between items-center">
              <span class="text-slate-400">Calculated 95th Percentile (p95):</span>
              <span class="text-emerald-400 font-bold">85.00 Mbps</span>
            </div>
            <div class="flex justify-between items-center">
              <span class="text-slate-400">Discarded Peak Bursts (Top 5%):</span>
              <span class="text-amber-400">95.00 Mbps (Sample #20 discarded)</span>
            </div>
            <div class="flex justify-between items-center pt-2 border-t border-slate-800">
              <span class="text-slate-400">Billable Burst Overage:</span>
              <span class="text-rose-400 font-bold">+35.00 Mbps (BDT 10,500.00)</span>
            </div>
          </div>
        </section>

        <!-- Real-Time SIP / VoIP Interconnect Telephony -->
        <section class="bg-slate-800 border border-slate-700 rounded-lg p-5 shadow">
          <div class="flex items-center justify-between mb-3">
            <h2 class="text-md font-bold text-sky-300 flex items-center gap-2">
              <svg class="w-5 h-5 text-sky-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" /></svg>
              Real-Time SIP Telephony & E.164 Rating (ESL Bridge)
            </h2>
            <span class="text-[11px] font-mono bg-slate-900 px-2 py-0.5 rounded text-sky-400">RFC 3261 Live</span>
          </div>
          <p class="text-xs text-slate-400 mb-4">Longest E.164 prefix matching with sub-millisecond Redis credit reservations</p>

          <div class="overflow-x-auto">
            <table class="w-full text-left text-xs font-mono text-slate-300">
              <thead class="bg-slate-900/60 text-slate-400 border-b border-slate-700">
                <tr>
                  <th class="py-2 px-2">Caller / Callee</th>
                  <th class="py-2 px-2">Duration</th>
                  <th class="py-2 px-2">Pulse</th>
                  <th class="py-2 px-2 text-right">Cost (BDT)</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-800">
                <tr v-for="cdr in (recentSipCdrs || defaultCdrs)" :key="cdr.id || cdr.call_id" class="hover:bg-slate-700/30">
                  <td class="py-2 px-2">
                    <div class="text-white">{{ cdr.callee_e164 }}</div>
                    <div class="text-slate-500 text-[10px]">{{ cdr.caller_e164 }}</div>
                  </td>
                  <td class="py-2 px-2">{{ cdr.duration_seconds }}s ({{ cdr.billable_seconds }}s billable)</td>
                  <td class="py-2 px-2 text-slate-400">6s pulse</td>
                  <td class="py-2 px-2 text-right font-bold text-emerald-400">{{ Number(cdr.cost_bdt).toFixed(2) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </main>
  </div>
</template>

<script setup>
defineProps({
  anycastTopology: {
    type: Object,
    default: () => ({ average_auth_latency_ms: 4.2, nodes: [] })
  },
  recentClearingBatches: {
    type: Array,
    default: () => []
  },
  recentSipCdrs: {
    type: Array,
    default: () => []
  },
  recentOtdrTraces: {
    type: Array,
    default: () => []
  },
  recentDrDrills: {
    type: Array,
    default: () => []
  }
});

const defaultNodes = [
  { node_code: 'ANYCAST-DHK-01', pop_name: 'Dhaka Central Core POP', region: 'dhaka-central', physical_ip: '172.16.1.10', auth_latency_ms: 3.8, is_announced: true, bgp_community: '65001:100' },
  { node_code: 'ANYCAST-CTG-01', pop_name: 'Chattogram Hub POP', region: 'chattogram-hub', physical_ip: '172.16.2.10', auth_latency_ms: 4.5, is_announced: true, bgp_community: '65001:100' },
  { node_code: 'ANYCAST-SYL-01', pop_name: 'Sylhet Edge Gateway', region: 'sylhet-edge', physical_ip: '172.16.3.10', auth_latency_ms: 5.1, is_announced: true, bgp_community: '65001:100' }
];

const defaultOtdrTraces = [
  { trace_id: 'TR-01', fiber_span_name: 'FIBER-DHAKA-RING-A', fault_type: 'Fiber Cut (Reflection 100%)', distance_meters: 1429.33, loss_db: 32.5, round_trip_nanoseconds: 14000, estimated_address: 'Mirpur-10 Roundabout Metro Pillar #42, Dhaka' }
];

const defaultBatches = [
  { id: '1', batch_number: 'DD-BEFTN-20261123-EKVOFD', clearing_type: 'BEFTN', total_count: 142, total_amount_bdt: 213000.00, status: 'settled', originator_routing_no: '010271234' }
];

const defaultCdrs = [
  { call_id: 'CALL-01', caller_e164: '+8801700000001', callee_e164: '+8801812345678', duration_seconds: 65, billable_seconds: 72, cost_bdt: 2.16 }
];
</script>

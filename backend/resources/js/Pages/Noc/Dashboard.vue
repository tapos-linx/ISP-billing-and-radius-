<template>
  <div class="min-h-screen bg-slate-900 text-slate-100 flex flex-col font-sans">
    <!-- NOC Top Bar -->
    <header class="bg-slate-800 border-b border-slate-700 px-6 py-4 flex items-center justify-between shadow-lg">
      <div class="flex items-center space-x-3">
        <div class="h-4 w-4 rounded-full bg-emerald-500 animate-pulse"></div>
        <h1 class="text-xl font-bold tracking-wider text-emerald-400">TAPOS LINX NOC CONSOLE</h1>
        <span class="text-xs bg-slate-700 px-2.5 py-1 rounded-full text-slate-300 font-mono">BSS/OSS CARRIER ENGINE</span>
      </div>
      <div class="text-sm font-mono text-slate-400">
        RADIUS AAA: <span class="text-emerald-400 font-bold">ONLINE</span> | CITUS: <span class="text-emerald-400 font-bold">128 SHARDS</span>
      </div>
    </header>

    <!-- Main NOC View -->
    <main class="flex-1 p-6 space-y-6">
      <!-- KPI Metric Cards Grid -->
      <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        <div class="bg-slate-800 border border-slate-700 rounded-lg p-4 shadow">
          <div class="text-xs text-slate-400 uppercase font-semibold">Total Subscribers</div>
          <div class="text-2xl font-bold text-white mt-1">{{ stats.total_subscribers }}</div>
          <div class="text-xs text-emerald-400 mt-1">Active: {{ stats.active_subscribers }}</div>
        </div>

        <div class="bg-slate-800 border border-slate-700 rounded-lg p-4 shadow">
          <div class="text-xs text-slate-400 uppercase font-semibold">Expired / Dunning</div>
          <div class="text-2xl font-bold text-amber-400 mt-1">{{ stats.expired_subscribers }}</div>
          <div class="text-xs text-slate-400 mt-1">Walled Garden Pool</div>
        </div>

        <div class="bg-slate-800 border border-slate-700 rounded-lg p-4 shadow">
          <div class="text-xs text-slate-400 uppercase font-semibold">Unpaid Invoices</div>
          <div class="text-2xl font-bold text-rose-400 mt-1">BDT {{ Number(stats.unpaid_invoices_bdt).toLocaleString() }}</div>
          <div class="text-xs text-slate-400 mt-1">Accounts Receivable</div>
        </div>

        <div class="bg-slate-800 border border-slate-700 rounded-lg p-4 shadow">
          <div class="text-xs text-slate-400 uppercase font-semibold">Helpdesk Open</div>
          <div class="text-2xl font-bold text-sky-400 mt-1">{{ stats.open_tickets }}</div>
          <div class="text-xs text-slate-400 mt-1">SLA Timers Active</div>
        </div>

        <div class="bg-slate-800 border border-slate-700 rounded-lg p-4 shadow">
          <div class="text-xs text-slate-400 uppercase font-semibold">GPON / Topology</div>
          <div class="text-2xl font-bold text-indigo-400 mt-1">{{ stats.provisioned_onus }} ONUs</div>
          <div class="text-xs text-slate-400 mt-1">{{ stats.online_olts }} Online OLTs</div>
        </div>
      </div>

      <!-- Recent NOC Feeds (Subscribers & Support Tickets) -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Subscribers Feed -->
        <div class="bg-slate-800 border border-slate-700 rounded-lg p-5">
          <h2 class="text-md font-semibold text-slate-200 mb-4 flex items-center justify-between">
            <span>Recent Subscriber Connections</span>
            <span class="text-xs bg-slate-700 text-slate-300 px-2 py-0.5 rounded">Live FreeRADIUS</span>
          </h2>
          <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-300">
              <thead class="text-xs uppercase bg-slate-900/50 text-slate-400 border-b border-slate-700">
                <tr>
                  <th class="py-2 px-3">Username</th>
                  <th class="py-2 px-3">Profile</th>
                  <th class="py-2 px-3">Status</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-700/50">
                <tr v-for="sub in recentSubscribers" :key="sub.subscriber_id" class="hover:bg-slate-700/30">
                  <td class="py-2.5 px-3 font-mono font-medium">{{ sub.username }}</td>
                  <td class="py-2.5 px-3 text-xs">{{ sub.bandwidth_profile || 'Default' }}</td>
                  <td class="py-2.5 px-3">
                    <span :class="sub.status === 'active' ? 'text-emerald-400 bg-emerald-950/60 border border-emerald-800' : 'text-amber-400 bg-amber-950/60 border border-amber-800'" class="text-xs px-2 py-0.5 rounded">
                      {{ sub.status }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Support Tickets Feed -->
        <div class="bg-slate-800 border border-slate-700 rounded-lg p-5">
          <h2 class="text-md font-semibold text-slate-200 mb-4 flex items-center justify-between">
            <span>Active Helpdesk Tickets</span>
            <span class="text-xs bg-slate-700 text-slate-300 px-2 py-0.5 rounded">SLA Enforcement</span>
          </h2>
          <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-300">
              <thead class="text-xs uppercase bg-slate-900/50 text-slate-400 border-b border-slate-700">
                <tr>
                  <th class="py-2 px-3">Ticket #</th>
                  <th class="py-2 px-3">Subject</th>
                  <th class="py-2 px-3">Priority</th>
                  <th class="py-2 px-3">Status</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-700/50">
                <tr v-for="tkt in recentTickets" :key="tkt.ticket_id" class="hover:bg-slate-700/30">
                  <td class="py-2.5 px-3 font-mono text-xs text-sky-400">{{ tkt.ticket_number }}</td>
                  <td class="py-2.5 px-3 text-xs truncate max-w-[180px]">{{ tkt.subject }}</td>
                  <td class="py-2.5 px-3">
                    <span :class="tkt.priority === 'critical' ? 'text-rose-400' : tkt.priority === 'high' ? 'text-amber-400' : 'text-slate-400'" class="text-xs uppercase font-bold">
                      {{ tkt.priority }}
                    </span>
                  </td>
                  <td class="py-2.5 px-3 text-xs capitalize">{{ tkt.status }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </main>
  </div>
</template>

<script setup>
defineProps({
  stats: { type: Object, required: true },
  recentSubscribers: { type: Array, default: () => [] },
  recentTickets: { type: Array, default: () => [] },
});
</script>

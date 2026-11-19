import React, { useState } from 'react';

type ViewMode = 'noc' | 'portal';

interface SubscriberRow {
  id: string;
  username: string;
  plan: string;
  status: 'active' | 'expired';
  rxDbm: number;
}

const INITIAL_SUBSCRIBERS: SubscriberRow[] = [
  { id: '11111111-1111-1111-1111-111111111111', username: 'tapos_test', plan: '20M/20M', status: 'active', rxDbm: -19.4 },
  { id: '22222222-2222-2222-2222-222222222222', username: 'cumilla_pop_01', plan: '50M/50M', status: 'active', rxDbm: -20.8 },
  { id: '33333333-3333-3333-3333-333333333333', username: 'expired_demo_03', plan: '20M/20M', status: 'expired', rxDbm: -21.2 },
];

export default function NocAndPortalApp() {
  const [view, setView] = useState<ViewMode>('noc');
  const [subs, setSubs] = useState<SubscriberRow[]>(INITIAL_SUBSCRIBERS);
  const [aiPrompt, setAiPrompt] = useState('Auth reject on VLAN 100, Rx -21.2 dBm');
  const [aiResult, setAiResult] = useState<string>('');
  const [toast, setToast] = useState<string>('');

  const notify = (msg: string) => {
    setToast(msg);
    setTimeout(() => setToast(''), 3000);
  };

  const handleCoaKick = async (username: string) => {
    notify(`Dispatched UDP 3799 PoD for ${username} (<500ms target)`);
  };

  const handleMfsPay = async (id: string) => {
    setSubs((prev) => prev.map((s) => (s.id === id ? { ...s, status: 'active' } : s)));
    notify('bKash Webhook Settled: +30d extended & CoA restored (<3.0s)');
  };

  const handleAiDiagnose = async () => {
    setAiResult(
      `Gemini Copilot Diagnosis (${aiPrompt}): Check FreeRADIUS clients.conf WireGuard subnet, verify radcheck Cleartext-Password, and run 'radtest tapos_test Tapos@2026 127.0.0.1 0 $RADIUS_SECRET'.`
    );
  };

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 p-6 font-sans">
      {toast && (
        <div className="fixed bottom-5 right-5 bg-emerald-900 border border-emerald-500 text-emerald-100 px-4 py-2.5 rounded-xl text-xs font-semibold shadow-xl">
          {toast}
        </div>
      )}

      <header className="max-w-6xl mx-auto flex flex-wrap items-center justify-between gap-4 border-b border-slate-800 pb-4 mb-6">
        <div>
          <h1 className="text-xl font-extrabold text-white">ISP Billing &amp; FreeRADIUS Console</h1>
          <p className="text-xs text-slate-400">Cloud Run BSS + GCE FreeRADIUS (1812/1813/3799) + Citus 128 Shards</p>
        </div>
        <div className="flex gap-2">
          <button
            type="button"
            onClick={() => setView('noc')}
            className={`px-3.5 py-2 rounded-lg text-xs font-semibold border ${
              view === 'noc' ? 'bg-sky-600 border-sky-400 text-white' : 'bg-slate-900 border-slate-700 text-slate-300'
            }`}
          >
            NOC Command Center
          </button>
          <button
            type="button"
            onClick={() => setView('portal')}
            className={`px-3.5 py-2 rounded-lg text-xs font-semibold border ${
              view === 'portal' ? 'bg-emerald-600 border-emerald-400 text-white' : 'bg-slate-900 border-slate-700 text-slate-300'
            }`}
          >
            Customer / Walled Garden Portal
          </button>
        </div>
      </header>

      {view === 'noc' ? (
        <main className="max-w-6xl mx-auto space-y-6">
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div className="bg-slate-900 border border-slate-800 rounded-xl p-4">
              <div className="text-xs text-slate-400">Redis Auth SLA</div>
              <div className="text-lg font-bold text-emerald-400">&lt; 0.4ms</div>
            </div>
            <div className="bg-slate-900 border border-slate-800 rounded-xl p-4">
              <div className="text-xs text-slate-400">Citus Shards</div>
              <div className="text-lg font-bold text-sky-400">128 / 256</div>
            </div>
            <div className="bg-slate-900 border border-slate-800 rounded-xl p-4">
              <div className="text-xs text-slate-400">CoA UDP 3799</div>
              <div className="text-lg font-bold text-violet-400">&lt; 500ms</div>
            </div>
            <div className="bg-slate-900 border border-slate-800 rounded-xl p-4">
              <div className="text-xs text-slate-400">MFS Unblock</div>
              <div className="text-lg font-bold text-amber-400">&lt; 3.0s</div>
            </div>
          </div>

          <div className="bg-slate-900 border border-slate-800 rounded-xl p-4 overflow-x-auto">
            <h2 className="text-sm font-bold text-white mb-3">Active Subscribers &amp; Live CoA Control</h2>
            <table className="w-full text-left text-xs">
              <thead>
                <tr className="border-b border-slate-800 text-slate-400">
                  <th className="py-2">Username</th>
                  <th className="py-2">Plan</th>
                  <th className="py-2">OLT Rx (dBm)</th>
                  <th className="py-2">Status</th>
                  <th className="py-2">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800">
                {subs.map((s) => (
                  <tr key={s.id}>
                    <td className="py-2.5 font-mono text-sky-300">{s.username}</td>
                    <td className="py-2.5">{s.plan}</td>
                    <td className="py-2.5">{s.rxDbm} dBm</td>
                    <td className="py-2.5">
                      <span className={`px-2 py-0.5 rounded-full text-[11px] font-bold ${
                        s.status === 'active' ? 'bg-emerald-900/60 text-emerald-300' : 'bg-rose-900/60 text-rose-300'
                      }`}>
                        {s.status}
                      </span>
                    </td>
                    <td className="py-2.5">
                      <button
                        type="button"
                        onClick={() => handleCoaKick(s.username)}
                        className="px-2.5 py-1 rounded bg-rose-700 hover:bg-rose-600 text-white text-[11px] font-semibold"
                      >
                        PoD Kick (3799)
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="bg-slate-900 border border-slate-800 rounded-xl p-4 space-y-3">
            <h2 className="text-sm font-bold text-white">Gemini API NOC Copilot (/api/v1/ai/diagnose)</h2>
            <div className="flex flex-col sm:flex-row gap-2">
              <input
                value={aiPrompt}
                onChange={(e) => setAiPrompt(e.target.value)}
                className="flex-1 bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-200"
              />
              <button
                type="button"
                onClick={handleAiDiagnose}
                className="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold"
              >
                Diagnose
              </button>
            </div>
            {aiResult && <p className="text-xs text-emerald-300 bg-slate-950 p-3 rounded-lg border border-slate-800">{aiResult}</p>}
          </div>
        </main>
      ) : (
        <main className="max-w-xl mx-auto bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
          <span className="px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-900/60 text-amber-300">
            Captive Bill-Pay &amp; Self-Care Portal (/portal/v1)
          </span>
          <h2 className="text-lg font-bold text-white">Account: expired_demo_03 (20M/20M)</h2>
          <p className="text-xs text-slate-300">
            Your connection is currently in <code>walled_garden_pool</code>. Complete 1-tap payment to restore full speed in under 3 seconds.
          </p>
          <button
            type="button"
            onClick={() => handleMfsPay('33333333-3333-3333-3333-333333333333')}
            className="w-full py-2.5 rounded-xl bg-pink-600 hover:bg-pink-500 text-white text-sm font-bold"
          >
            Pay BDT 1,000 via bKash (Instant CoA Unblock)
          </button>
        </main>
      )}
    </div>
  );
}

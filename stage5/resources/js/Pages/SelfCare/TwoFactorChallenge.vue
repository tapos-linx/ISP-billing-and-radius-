<template>
  <div class="min-h-screen flex items-center justify-center bg-slate-950 text-slate-100 p-4">
    <form @submit.prevent="submit" class="w-full max-w-sm rounded-xl bg-slate-900 border border-slate-800 p-6 space-y-4">
      <h1 class="text-lg font-bold text-white">2FA Verification</h1>
      <p class="text-xs text-slate-400">Enter the 6-digit authenticator code to access your ISP billing account.</p>
      <input v-model="form.code" type="text" inputmode="numeric" maxlength="6" required
             class="w-full rounded-lg bg-slate-950 border border-slate-700 px-3 py-2 text-center tracking-widest text-lg"
             placeholder="000000" />
      <button type="submit" :disabled="form.processing"
              class="w-full rounded-lg bg-indigo-600 hover:bg-indigo-500 py-2 font-semibold text-sm">
        Verify &amp; Continue
      </button>
    </form>
  </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';

const form = useForm({ code: '' });
const submit = () => form.post('/two-factor-challenge');
</script>

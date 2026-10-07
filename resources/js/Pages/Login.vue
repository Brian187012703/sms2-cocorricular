<script setup>
import { Head, useForm } from '@inertiajs/vue3';
const form = useForm({ username: '', password: '', remember: false });
const submit = () => form.post('/login', { onFinish: () => form.reset('password') });
</script>
<template>
  <Head title="Sign in" />
  <main class="min-h-screen bg-slate-950 text-slate-100 flex items-center justify-center p-6">
    <form @submit.prevent="submit" class="w-full max-w-md bg-slate-900 border border-slate-700 rounded-2xl p-8 space-y-5">
      <h1 class="text-2xl font-bold">Co-Curricular Portal</h1>
      <p class="text-slate-400">Sign in with your institutional account.</p>
      <div>
        <label for="username" class="block mb-2">Username or email</label>
        <input id="username" v-model="form.username" required autocomplete="username" class="w-full rounded-lg bg-slate-800 p-3" />
        <p v-if="form.errors.username" role="alert" class="text-red-400">{{ form.errors.username }}</p>
      </div>
      <div>
        <label for="password" class="block mb-2">Password</label>
        <input id="password" v-model="form.password" type="password" required autocomplete="current-password" class="w-full rounded-lg bg-slate-800 p-3" />
        <p v-if="form.errors.password" role="alert" class="text-red-400">{{ form.errors.password }}</p>
      </div>
      <label class="flex gap-2"><input v-model="form.remember" type="checkbox" /> Remember me</label>
      <button :disabled="form.processing" class="w-full rounded-lg bg-blue-600 p-3 font-semibold disabled:opacity-50">{{ form.processing ? 'Signing in...' : 'Sign in' }}</button>
    </form>
  </main>
</template>

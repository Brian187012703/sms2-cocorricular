<template>
  <div class="min-h-screen bg-slate-950 text-slate-100 flex font-sans">
    <!-- Sidebar -->
    <aside class="w-64 bg-slate-900 border-r border-slate-800 flex flex-col justify-between p-4">
      <div>
        <div class="flex items-center space-x-3 px-2 py-4 mb-6 border-b border-slate-800">
          <div class="h-10 w-10 rounded-xl bg-gradient-to-tr from-blue-600 to-indigo-500 flex items-center justify-center font-bold text-xl shadow-lg shadow-blue-500/30">
            S
          </div>
          <div>
            <h1 class="font-bold text-lg leading-none tracking-tight">BCP SMS</h1>
            <span class="text-xs text-blue-400 font-medium">Laravel + Vue Stack</span>
          </div>
        </div>

        <nav class="space-y-1">
          <a href="#" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg bg-blue-600/20 text-blue-400 font-medium border border-blue-500/30 transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 00-1-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            <span>Dashboard</span>
          </a>
          <a href="#" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg text-slate-400 hover:bg-slate-800 hover:text-slate-200 font-medium transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            <span>Student Roster</span>
          </a>
          <a href="#" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg text-slate-400 hover:bg-slate-800 hover:text-slate-200 font-medium transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
            <span>Clubs & Societies</span>
          </a>
          <a href="#" class="flex items-center space-x-3 px-3 py-2.5 rounded-lg text-slate-400 hover:bg-slate-800 hover:text-slate-200 font-medium transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg>
            <span>Achievements</span>
          </a>
        </nav>
      </div>

      <!-- Security Shield Badge -->
      <div class="p-3 bg-slate-950/60 rounded-xl border border-emerald-500/20 text-xs">
        <div class="flex items-center space-x-2 text-emerald-400 font-semibold mb-1">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
          <span>Security Active</span>
        </div>
        <p class="text-slate-400 leading-tight">Argon2id Hashing + Malware Scanner Shield</p>
      </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 overflow-y-auto p-8 space-y-8">
      <!-- Top Header -->
      <header class="flex justify-between items-center pb-6 border-b border-slate-800">
        <div>
          <h2 class="text-2xl font-extrabold text-white tracking-tight">Co-Curricular Management Overview</h2>
          <p class="text-slate-400 text-sm mt-1">Student Management System & System Security Analytics</p>
        </div>
        <div class="flex items-center space-x-4">
          <span class="px-3 py-1 rounded-full bg-emerald-500/10 text-emerald-400 text-xs font-semibold border border-emerald-500/20">
            REST API Ready
          </span>
          <div class="h-9 w-9 rounded-full bg-gradient-to-tr from-blue-500 to-indigo-600 flex items-center justify-center font-bold text-white shadow-md">
            A
          </div>
        </div>
      </header>

      <!-- Stat Cards -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-5 backdrop-blur-xl hover:border-blue-500/30 transition">
          <div class="text-slate-400 text-xs font-medium uppercase tracking-wider">Total Enrolled Students</div>
          <div class="text-3xl font-extrabold text-white mt-2">1,248</div>
          <div class="text-emerald-400 text-xs mt-2 font-medium">↑ 12% from last term</div>
        </div>
        <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-5 backdrop-blur-xl hover:border-blue-500/30 transition">
          <div class="text-slate-400 text-xs font-medium uppercase tracking-wider">Active Recognized Clubs</div>
          <div class="text-3xl font-extrabold text-white mt-2">18</div>
          <div class="text-blue-400 text-xs mt-2 font-medium">4 Academic, 3 Advocacy</div>
        </div>
        <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-5 backdrop-blur-xl hover:border-blue-500/30 transition">
          <div class="text-slate-400 text-xs font-medium uppercase tracking-wider">Scanned Uploads</div>
          <div class="text-3xl font-extrabold text-emerald-400 mt-2">342</div>
          <div class="text-emerald-400 text-xs mt-2 font-medium">100% Malware Free</div>
        </div>
        <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-5 backdrop-blur-xl hover:border-blue-500/30 transition">
          <div class="text-slate-400 text-xs font-medium uppercase tracking-wider">System Auth Status</div>
          <div class="text-3xl font-extrabold text-indigo-400 mt-2">Sanctum</div>
          <div class="text-indigo-400 text-xs mt-2 font-medium">Argon2id + JWT Active</div>
        </div>
      </div>

      <!-- Quick Actions & Tech Stack Banner -->
      <div class="bg-gradient-to-r from-blue-900/40 via-indigo-900/30 to-slate-900 border border-blue-500/20 rounded-2xl p-6 relative overflow-hidden">
        <div class="relative z-10 max-w-2xl">
          <h3 class="text-xl font-bold text-white mb-2">Modernized Technology Stack Online</h3>
          <p class="text-slate-300 text-sm leading-relaxed mb-4">
            Your system has been corrected and upgraded to Laravel 11+, Inertia.js, Vue 3, Tailwind CSS v4, Sanctum API tokens, Python data adapters, and anti-malware security defenses.
          </p>
          <div class="flex flex-wrap gap-2 text-xs font-mono">
            <span class="px-2.5 py-1 rounded-md bg-blue-500/20 text-blue-300 border border-blue-400/30">PHP 8.2 / Laravel</span>
            <span class="px-2.5 py-1 rounded-md bg-emerald-500/20 text-emerald-300 border border-emerald-400/30">Vue 3 + Inertia</span>
            <span class="px-2.5 py-1 rounded-md bg-cyan-500/20 text-cyan-300 border border-cyan-400/30">Tailwind CSS v4</span>
            <span class="px-2.5 py-1 rounded-md bg-purple-500/20 text-purple-300 border border-purple-400/30">Argon2id Hash</span>
            <span class="px-2.5 py-1 rounded-md bg-amber-500/20 text-amber-300 border border-amber-400/30">Python API Bridge</span>
          </div>
        </div>
      </div>
    </main>
  </div>
</template>

<script setup>
import { Head } from '@inertiajs/vue3';
</script>

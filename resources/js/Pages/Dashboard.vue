<template>
  <AppLayout>
    <div class="space-y-8">
      <!-- Welcome Hero Banner -->
      <div class="relative overflow-hidden rounded-3xl bg-gradient-to-r from-blue-900/60 via-indigo-900/40 to-slate-900/80 border border-slate-800 p-6 sm:p-8 backdrop-blur-xl shadow-2xl">
        <div class="relative z-10 max-w-2xl">
          <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-full bg-blue-500/10 text-blue-400 border border-blue-500/20 text-xs font-semibold uppercase tracking-wider mb-3">
            <span>{{ roleTitle }}</span>
          </div>
          <h1 class="text-2xl sm:text-3xl font-black text-white tracking-tight">
            Welcome back, {{ userName }}!
          </h1>
          <p class="text-sm text-slate-300 mt-2 leading-relaxed">
            Bestlink College of the Philippines Co-Curricular Management System. Monitor campus organizations, track participation credentials, and manage workflow approvals.
          </p>

          <div class="mt-5 flex flex-wrap items-center gap-3">
            <Link
              href="/events"
              class="px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-semibold text-xs transition shadow-lg shadow-blue-500/25 active:scale-95"
            >
              Browse Events
            </Link>
            <Link
              v-if="userRole === 'student'"
              href="/roster"
              class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 font-semibold text-xs transition"
            >
              My Club Applications
            </Link>
            <Link
              v-if="userRole !== 'student'"
              href="/attendance/scanner"
              class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 font-semibold text-xs transition"
            >
              Launch QR Scanner
            </Link>
          </div>
        </div>

        <div class="absolute -right-10 -bottom-10 w-64 h-64 bg-blue-600/10 rounded-full blur-3xl pointer-events-none"></div>
      </div>

      <!-- Live Metric Counter Cards (Dynamically Scoped Per User Role) -->
      <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
        <div class="p-5 rounded-2xl bg-slate-900/70 border border-slate-800">
          <div class="text-xs font-semibold text-slate-400 uppercase tracking-wider truncate">
            {{ metrics?.primary_label || 'Enrolled Students' }}
          </div>
          <div class="mt-2 text-2xl font-black text-white font-mono">
            {{ metrics?.primary_metric || 0 }}
          </div>
        </div>
        <div class="p-5 rounded-2xl bg-slate-900/70 border border-slate-800">
          <div class="text-xs font-semibold text-blue-400 uppercase tracking-wider truncate">
            {{ metrics?.secondary_label || 'Active Clubs' }}
          </div>
          <div class="mt-2 text-2xl font-black text-blue-400 font-mono">
            {{ metrics?.secondary_metric || 0 }}
          </div>
        </div>
        <div class="p-5 rounded-2xl bg-slate-900/70 border border-slate-800">
          <div class="text-xs font-semibold text-emerald-400 uppercase tracking-wider truncate">
            Upcoming Events
          </div>
          <div class="mt-2 text-2xl font-black text-emerald-400 font-mono">
            {{ metrics?.upcoming_events || 0 }}
          </div>
        </div>
        <div class="p-5 rounded-2xl bg-slate-900/70 border border-slate-800">
          <div class="text-xs font-semibold text-amber-400 uppercase tracking-wider truncate">
            {{ metrics?.pending_action_label || 'Pending Actions' }}
          </div>
          <div class="mt-2 text-2xl font-black text-amber-400 font-mono">
            {{ metrics?.pending_action || 0 }}
          </div>
        </div>
        <div v-if="userRole !== 'student'" class="p-5 rounded-2xl bg-slate-900/70 border border-slate-800">
          <div class="text-xs font-semibold text-purple-400 uppercase tracking-wider truncate">
            Verified Awards
          </div>
          <div class="mt-2 text-2xl font-black text-purple-400 font-mono">
            {{ metrics?.verified_achievements || 0 }}
          </div>
        </div>
      </div>

      <!-- Two-Column Grid: Recent Events & Announcements -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Recent Events Section -->
        <div class="bg-slate-900/80 border border-slate-800 rounded-3xl p-6 space-y-4 shadow-xl">
          <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h2 class="text-base font-bold text-white tracking-tight flex items-center space-x-2">
              <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
              <span>Upcoming Scheduled Activities</span>
            </h2>
            <Link href="/events" class="text-xs text-blue-400 hover:text-blue-300 font-semibold">View All &rarr;</Link>
          </div>

          <div v-if="recentEvents && recentEvents.length > 0" class="space-y-3">
            <div
              v-for="ev in recentEvents"
              :key="ev.id"
              class="p-3.5 rounded-xl bg-slate-950/60 border border-slate-800/80 flex items-center justify-between"
            >
              <div class="truncate mr-3">
                <div class="font-bold text-white text-xs truncate flex items-center space-x-2">
                  <span>{{ ev.title }}</span>
                  <span
                    v-if="ev.audience_type"
                    :class="[
                      'text-[9px] font-bold px-1.5 py-0.2 rounded border',
                      ev.audience_type === 'Exclusive' ? 'text-purple-400 border-purple-500/30 bg-purple-500/10' : 'text-cyan-400 border-cyan-500/30 bg-cyan-500/10'
                    ]"
                  >
                    {{ ev.audience_type }}
                  </span>
                </div>
                <div class="text-[11px] text-slate-400 mt-0.5">{{ ev.venue }} • {{ formatSchedule(ev.event_date, ev.end_time) }}</div>
              </div>
              <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 shrink-0">
                {{ ev.status }}
              </span>
            </div>
          </div>
          <div v-else class="text-xs text-slate-500 py-6 text-center">
            No upcoming events posted on calendar.
          </div>
        </div>

        <!-- Latest Announcements Section -->
        <div class="bg-slate-900/80 border border-slate-800 rounded-3xl p-6 space-y-4 shadow-xl">
          <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h2 class="text-base font-bold text-white tracking-tight flex items-center space-x-2">
              <svg class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>
              <span>Campus Bulletins</span>
            </h2>
            <Link href="/announcements" class="text-xs text-blue-400 hover:text-blue-300 font-semibold">View All &rarr;</Link>
          </div>

          <div v-if="announcements && announcements.length > 0" class="space-y-3">
            <div
              v-for="an in announcements"
              :key="an.id"
              class="p-3.5 rounded-xl bg-slate-950/60 border border-slate-800/80 space-y-1"
            >
              <div class="flex items-center justify-between">
                <span class="font-bold text-white text-xs">{{ an.title }}</span>
                <span class="text-[10px] text-slate-500 font-mono">{{ formatDate(an.created_at) }}</span>
              </div>
              <p class="text-[11px] text-slate-400 line-clamp-2 leading-relaxed">{{ an.content }}</p>
            </div>
          </div>
          <div v-else class="text-xs text-slate-500 py-6 text-center">
            No bulletins currently posted.
          </div>
        </div>
      </div>

      <!-- Featured Campus Organizations -->
      <div class="bg-slate-900/80 border border-slate-800 rounded-3xl p-6 space-y-4 shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
          <h2 class="text-base font-bold text-white tracking-tight">Active Student Organizations</h2>
          <Link href="/clubs" class="text-xs text-blue-400 hover:text-blue-300 font-semibold">Browse Directory &rarr;</Link>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <div
            v-for="c in featuredClubs"
            :key="c.id"
            class="p-4 rounded-2xl bg-slate-950/60 border border-slate-800 space-y-2"
          >
            <div class="flex items-center justify-between">
              <span class="text-[10px] font-mono font-bold px-2 py-0.5 rounded-md bg-blue-500/10 text-blue-400 border border-blue-500/20">
                {{ c.code }}
              </span>
              <span class="text-[10px] text-slate-400">{{ c.category }}</span>
            </div>
            <h3 class="font-bold text-white text-sm leading-snug">{{ c.name }}</h3>
            <div class="text-[11px] text-slate-400 truncate">Adviser: {{ c.adviser_name || 'Not assigned' }}</div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
  metrics: Object,
  recentEvents: Array,
  recentAchievements: Array,
  featuredClubs: Array,
  announcements: Array,
});

const page = usePage();
const currentUser = computed(() => page.props.auth?.user || {});
const userRole = computed(() => currentUser.value.role || 'student');
const userName = computed(() => {
  if (!currentUser.value.first_name) return 'User';
  return `${currentUser.value.first_name} ${currentUser.value.last_name || ''}`;
});

const roleTitle = computed(() => {
  switch (userRole.value) {
    case 'admin': return 'System Administrator Portal';
    case 'ssc': return 'Supreme Student Council Executive Console';
    case 'club_adviser': return 'Faculty Organization Adviser Portal';
    default: return 'Student Co-Curricular Dashboard';
  }
});

const formatDate = (dateStr) => {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  return d.toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
  });
};

const formatSchedule = (startStr, endStr) => {
  if (!startStr) return '';
  const dStart = new Date(startStr);
  const dateFormatted = dStart.toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
  });
  const startTime = dStart.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });

  if (endStr) {
    const dEnd = new Date(endStr);
    const endTime = dEnd.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
    return `${dateFormatted} • ${startTime} – ${endTime}`;
  }
  return `${dateFormatted} • ${startTime}`;
};
</script>

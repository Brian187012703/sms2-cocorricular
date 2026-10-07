<template>
  <div class="min-h-screen bg-slate-950 text-slate-100 flex flex-col md:flex-row font-sans selection:bg-blue-500 selection:text-white antialiased overflow-x-hidden">
    <!-- Mobile Header -->
    <header class="md:hidden flex items-center justify-between px-4 py-3 bg-slate-900/90 backdrop-blur-xl border-b border-slate-800 sticky top-0 z-40">
      <div class="flex items-center space-x-3">
        <button
          type="button"
          aria-label="Toggle Navigation Menu"
          class="p-2 rounded-lg bg-slate-800 text-slate-200 hover:bg-slate-700 transition focus:outline-none focus:ring-2 focus:ring-blue-500"
          @click="mobileNavOpen = !mobileNavOpen"
        >
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path v-if="!mobileNavOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
        <div class="flex items-center space-x-2">
          <div class="h-8 w-8 rounded-lg bg-gradient-to-tr from-blue-600 to-indigo-500 flex items-center justify-center font-black text-sm text-white shadow-md shadow-blue-500/30">
            B
          </div>
          <span class="font-bold text-base text-white tracking-tight">BCP SMS</span>
        </div>
      </div>

      <div class="flex items-center space-x-2">
        <span class="text-xs px-2.5 py-1 rounded-full bg-blue-500/10 text-blue-400 font-semibold border border-blue-500/20 capitalize">
          {{ userRole }}
        </span>
      </div>
    </header>

    <!-- Mobile Drawer Backdrop -->
    <div
      v-if="mobileNavOpen"
      class="fixed inset-0 bg-black/60 backdrop-blur-sm z-40 md:hidden transition-opacity"
      @click="mobileNavOpen = false"
    ></div>

    <!-- Sidebar (Desktop Fixed & Mobile Drawer) -->
    <aside
      :class="[
        'fixed md:sticky top-0 h-screen w-64 bg-slate-900/95 md:bg-slate-900/80 backdrop-blur-2xl border-r border-slate-800 flex flex-col justify-between p-4 shrink-0 z-50 transition-transform duration-300 ease-in-out md:translate-x-0',
        mobileNavOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0'
      ]"
    >
      <div class="flex flex-col h-full overflow-hidden">
        <!-- Logo Header -->
        <div class="flex items-center justify-between px-2 py-3 mb-4 border-b border-slate-800/80 shrink-0">
          <div class="flex items-center space-x-3">
            <div class="h-10 w-10 rounded-xl bg-gradient-to-tr from-blue-600 via-indigo-600 to-cyan-500 flex items-center justify-center font-black text-lg shadow-lg shadow-blue-500/25 text-white">
              B
            </div>
            <div>
              <h1 class="font-bold text-base leading-none tracking-tight text-white">Bestlink SMS</h1>
              <span class="text-[11px] text-blue-400 font-medium tracking-wide">Co-Curricular Portal</span>
            </div>
          </div>
          <button
            class="md:hidden text-slate-400 hover:text-white p-1"
            aria-label="Close Menu"
            @click="mobileNavOpen = false"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>

        <!-- Navigation Links (Scrollable if tall) -->
        <nav class="flex-1 overflow-y-auto space-y-1 pr-1 custom-scrollbar">
          <div class="px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400">
            Core Modules
          </div>

          <Link
            href="/dashboard"
            :class="navClass('/dashboard')"
          >
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 00-1-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            <span>Dashboard</span>
          </Link>

          <Link
            href="/events"
            :class="navClass('/events')"
          >
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            <span>Events &amp; Activities</span>
          </Link>

          <Link
            href="/clubs"
            :class="navClass('/clubs')"
          >
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
            <span>Clubs &amp; Orgs</span>
          </Link>

          <Link
            href="/roster"
            :class="navClass('/roster')"
          >
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            <span>{{ userRole === 'student' ? 'My Memberships' : 'Organization Roster' }}</span>
          </Link>

          <Link
            href="/elections"
            :class="navClass('/elections')"
          >
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
            <span>Elections &amp; Voting</span>
          </Link>

          <Link
            v-if="userRole !== 'student'"
            href="/attendance"
            :class="navClass('/attendance')"
          >
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>
            <span>Attendance &amp; Tracking</span>
          </Link>

          <!-- Governance Section -->
          <div v-if="userRole !== 'student'" class="pt-3 px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400">
            Governance &amp; Finance
          </div>

          <Link
            v-if="userRole !== 'student'"
            href="/budgets"
            :class="navClass('/budgets')"
          >
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>Budget Requisitions</span>
          </Link>

          <Link
            v-if="userRole !== 'student'"
            href="/attendance/scanner"
            :class="navClass('/attendance/scanner')"
          >
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            <span>Live QR Scanner</span>
          </Link>

          <Link
            href="/announcements"
            :class="navClass('/announcements')"
          >
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>
            <span>Announcements</span>
          </Link>

          <Link
            v-if="userRole !== 'student'"
            href="/achievements"
            :class="navClass('/achievements')"
          >
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg>
            <span>Achievements</span>
          </Link>

          <!-- Institutional Document Templates Shortcut -->
          <button
            type="button"
            class="w-full flex items-center space-x-3 px-3 py-2.5 rounded-xl text-sm font-medium text-slate-400 hover:bg-slate-800/60 hover:text-slate-200 transition text-left"
            @click="showTemplatesModal = true"
          >
            <svg class="w-5 h-5 shrink-0 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            <span>Document Templates</span>
          </button>

          <!-- Admin Central Suite -->
          <div v-if="userRole === 'admin'" class="pt-3 px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400">
            System Administration
          </div>

          <Link
            v-if="userRole === 'admin'"
            href="/admin/users"
            :class="navClass('/admin/users')"
          >
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            <span>User Management</span>
          </Link>

          <Link
            v-if="userRole === 'admin'"
            href="/admin/audit"
            :class="navClass('/admin/audit')"
          >
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            <span>Audit Trail</span>
          </Link>
        </nav>

        <!-- User Profile Card -->
        <div class="pt-3 border-t border-slate-800/80 shrink-0">
          <div class="p-3 bg-slate-950/60 rounded-xl border border-slate-800 flex items-center justify-between">
            <div class="flex items-center space-x-2.5 truncate">
              <div class="h-9 w-9 rounded-lg bg-blue-600/20 text-blue-400 font-bold flex items-center justify-center text-sm shrink-0 border border-blue-500/30">
                {{ userInitial }}
              </div>
              <div class="truncate text-xs">
                <div class="font-bold text-white truncate">{{ userName }}</div>
                <div class="text-[10px] text-slate-400 capitalize font-mono">{{ userRole }}</div>
              </div>
            </div>
            <button
              type="button"
              title="Logout"
              aria-label="Logout"
              class="text-slate-400 hover:text-red-400 p-1.5 rounded-lg hover:bg-red-500/10 transition"
              @click="logout"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            </button>
          </div>
        </div>
      </div>
    </aside>

    <!-- Main Content Area -->
    <main class="flex-1 flex flex-col min-w-0 min-h-screen">
      <!-- Flash Alert Banner -->
      <div v-if="$page.props.flash?.success" class="bg-emerald-950/80 border-b border-emerald-800 text-emerald-200 px-4 py-3 text-xs flex items-center justify-between z-30">
        <div class="flex items-center space-x-2">
          <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
          <span class="font-medium">{{ $page.props.flash.success }}</span>
        </div>
        <button class="text-emerald-400 hover:text-white" @click="$page.props.flash.success = null">&times;</button>
      </div>

      <div v-if="$page.props.flash?.error" class="bg-red-950/80 border-b border-red-800 text-red-200 px-4 py-3 text-xs flex items-center justify-between z-30">
        <div class="flex items-center space-x-2">
          <svg class="w-4 h-4 text-red-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
          <span class="font-medium">{{ $page.props.flash.error }}</span>
        </div>
        <button class="text-red-400 hover:text-white" @click="$page.props.flash.error = null">&times;</button>
      </div>

      <div v-if="$page.props.flash?.info" class="bg-blue-950/80 border-b border-blue-800 text-blue-200 px-4 py-3 text-xs flex items-center justify-between z-30">
        <div class="flex items-center space-x-2">
          <svg class="w-4 h-4 text-blue-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          <span class="font-medium">{{ $page.props.flash.info }}</span>
        </div>
        <button class="text-blue-400 hover:text-white" @click="$page.props.flash.info = null">&times;</button>
      </div>

      <!-- Page Slot -->
      <div class="flex-1 p-4 md:p-8 max-w-7xl w-full mx-auto">
        <!-- NDRRMC-Style Upcoming Event Broadcast Notification -->
        <NdrrmcAlertBanner :event="$page.props.upcomingAlert" />

        <div v-if="Object.keys(page.props.errors || {}).length" role="alert" class="mb-4 rounded-xl border border-red-700 bg-red-950 p-4 text-red-200"><p v-for="(message, field) in page.props.errors" :key="field">{{ message }}</p></div>
        <slot />
      </div>
    </main>

    <!-- Global Document Templates Modal -->
    <DocumentTemplatesModal v-model="showTemplatesModal" />
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import NdrrmcAlertBanner from '@/Components/NdrrmcAlertBanner.vue';
import DocumentTemplatesModal from '@/Components/DocumentTemplatesModal.vue';

const page = usePage();
const mobileNavOpen = ref(false);
const showTemplatesModal = ref(false);

const currentUser = computed(() => page.props.auth?.user || {});
const userName = computed(() => {
  if (!currentUser.value.first_name) return 'User';
  return `${currentUser.value.first_name} ${currentUser.value.last_name || ''}`;
});
const userRole = computed(() => currentUser.value.role || 'Guest');
const userInitial = computed(() => (currentUser.value.first_name || 'U').charAt(0).toUpperCase());

const currentPath = computed(() => page.url);

const navClass = (routePath) => {
  const isActive = currentPath.value === routePath || (routePath !== '/dashboard' && currentPath.value.startsWith(routePath));
  return [
    'flex items-center space-x-3 px-3 py-2.5 rounded-xl text-sm font-medium transition duration-150',
    isActive
      ? 'bg-blue-600/20 text-blue-400 border border-blue-500/30 shadow-sm'
      : 'text-slate-400 hover:bg-slate-800/60 hover:text-slate-200'
  ];
};

const logout = () => {
  router.post('/logout');
};
</script>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
  width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background: rgba(148, 163, 184, 0.2);
  border-radius: 4px;
}
</style>

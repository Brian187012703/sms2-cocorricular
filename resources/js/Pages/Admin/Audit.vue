<template>
  <AppLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 class="text-2xl font-extrabold text-white tracking-tight">Security &amp; Workflow Audit Trail</h1>
          <p class="text-sm text-slate-400 mt-1">
            Immutable log of system events, role actions, access events, and state transitions.
          </p>
        </div>
      </div>

      <!-- Filters -->
      <div class="p-4 rounded-2xl bg-slate-900/60 border border-slate-800 flex flex-col sm:flex-row gap-3 items-center justify-between">
        <div class="w-full sm:w-80 relative">
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Search action, details, or IP address..."
            class="w-full pl-9 pr-4 py-2 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
            @keyup.enter="applyFilters"
          />
          <svg class="w-4 h-4 text-slate-500 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        </div>
      </div>

      <!-- Audit Table -->
      <div class="bg-slate-900/80 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs text-slate-300">
            <thead class="bg-slate-950/60 text-slate-400 uppercase tracking-wider text-[10px] border-b border-slate-800">
              <tr>
                <th class="px-5 py-3.5">Timestamp</th>
                <th class="px-5 py-3.5">Actor</th>
                <th class="px-5 py-3.5">Action Code</th>
                <th class="px-5 py-3.5">Details</th>
                <th class="px-5 py-3.5">IP Address</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/60">
              <tr v-for="l in logs.data" :key="l.id" class="hover:bg-slate-800/40 transition">
                <td class="px-5 py-4 font-mono text-slate-400 text-[11px] whitespace-nowrap">
                  {{ formatDate(l.created_at) }}
                </td>
                <td class="px-5 py-4">
                  <div class="font-bold text-white">{{ l.user ? `${l.user.first_name} ${l.user.last_name}` : 'System' }}</div>
                  <div class="text-[10px] text-slate-400 font-mono">@{{ l.user ? l.user.username : 'system' }}</div>
                </td>
                <td class="px-5 py-4">
                  <span class="text-[10px] font-mono font-bold px-2 py-0.5 rounded-md bg-blue-500/10 text-blue-400 border border-blue-500/20">
                    {{ l.action }}
                  </span>
                </td>
                <td class="px-5 py-4 text-slate-300 max-w-md break-words">
                  {{ l.details }}
                </td>
                <td class="px-5 py-4 font-mono text-slate-500 text-[11px]">
                  {{ l.ip_address || '127.0.0.1' }}
                </td>
              </tr>
              <tr v-if="!logs.data || logs.data.length === 0">
                <td colspan="5" class="px-5 py-12 text-center text-slate-500">
                  No audit logs found matching your query.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <Pagination :page="logs" />
  </AppLayout>
</template>

<script setup>
import Pagination from '@/Components/Pagination.vue';
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
  logs: Object,
  filters: Object,
});

const searchQuery = ref(props.filters?.search || '');

const applyFilters = () => {
  router.get('/admin/audit', {
    search: searchQuery.value,
  }, { preserveState: true, replace: true });
};

const formatDate = (dateStr) => {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  return d.toLocaleString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  });
};
</script>

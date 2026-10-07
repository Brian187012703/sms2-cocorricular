<template>
  <AppLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 class="text-2xl font-extrabold text-white tracking-tight">Institutional Honors &amp; Awards</h1>
          <p class="text-sm text-slate-400 mt-1">
            Registry of verified student achievements, competition awards, and leadership citations.
          </p>
        </div>
      </div>

      <!-- Filters -->
      <div class="p-4 rounded-2xl bg-slate-900/60 border border-slate-800 flex flex-col sm:flex-row gap-3 items-center justify-between">
        <div class="w-full sm:w-80 relative">
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Search award title..."
            class="w-full pl-9 pr-4 py-2 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
            @keyup.enter="handleSearch"
          />
          <svg class="w-4 h-4 text-slate-500 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        </div>

        <div class="flex items-center space-x-2 w-full sm:w-auto">
          <select
            v-model="statusFilter"
            class="w-full sm:w-auto px-3 py-2 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
            @change="handleSearch"
          >
<option value="">All Statuses</option>
            <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
          </select>
        </div>
      </div>

      <!-- Achievements Cards Grid -->
      <div v-if="achievements.data && achievements.data.length > 0" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        <div
          v-for="a in achievements.data"
          :key="a.id"
          class="p-5 rounded-2xl bg-slate-900/80 border border-slate-800 hover:border-slate-700 transition flex flex-col justify-between space-y-4 shadow-lg"
        >
          <div>
            <div class="flex items-center justify-between">
              <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-amber-500/10 text-amber-400 border border-amber-500/20 uppercase">
                {{ a.competition || 'Competition not recorded' }}
              </span>
              <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 uppercase">
                {{ a.status }}
              </span>
            </div>

            <h3 class="text-base font-bold text-white mt-2 leading-snug">{{ a.title }}</h3>
            <p class="text-xs text-slate-400 mt-1 line-clamp-2">{{ a.notes || '' }}</p>

            <div class="mt-4 space-y-1.5 text-xs text-slate-300">
              <div class="flex items-center justify-between">
                <span class="text-slate-400">Awardee:</span>
                <span class="font-bold text-white">{{ a.submitter?.student ? `${a.submitter?.student.first_name} ${a.submitter?.student.last_name}` : 'Not recorded' }}</span>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-slate-400">Student ID:</span>
                <span class="font-mono text-blue-400">{{ a.submitter?.student ? a.submitter?.student.student_number : 'N/A' }}</span>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-slate-400">Awarded Date:</span>
                <span class="font-mono text-slate-400">{{ formatDate(a.award_date) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Empty State -->
      <div v-else class="p-12 text-center rounded-2xl bg-slate-900/40 border border-slate-800">
        <svg class="w-12 h-12 mx-auto text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg>
        <h3 class="mt-4 text-base font-bold text-white">No Achievements Recorded</h3>
        <p class="text-xs text-slate-400 mt-1 max-w-sm mx-auto">No honors match the search criteria.</p>
      </div>
    </div>
    <Pagination :page="achievements" />
  </AppLayout>
</template>

<script setup>
import Pagination from '@/Components/Pagination.vue';
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
  achievements: Object,
  filters: Object,
  statuses: Array,
});

const searchQuery = ref(props.filters?.search || '');
const statusFilter = ref(props.filters?.status || '');

const handleSearch = () => {
  router.get('/achievements', {
    search: searchQuery.value,
    status: statusFilter.value,
  }, { preserveState: true, replace: true });
};

const formatDate = (dateStr) => {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  return d.toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
};
</script>

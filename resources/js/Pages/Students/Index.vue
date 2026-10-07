<template>
  <AppLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 class="text-2xl font-extrabold text-white tracking-tight">Institutional Student Registry</h1>
          <p class="text-sm text-slate-400 mt-1">
            Official student records from database across all 16 undergraduate academic programs.
          </p>
        </div>
      </div>

      <!-- Search & Filters -->
      <div class="p-4 rounded-2xl bg-slate-900/60 border border-slate-800 flex flex-col sm:flex-row gap-3 items-center justify-between">
        <div class="w-full sm:w-80 relative">
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Search by student number, name, or course..."
            class="w-full pl-9 pr-4 py-2 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
            @keyup.enter="handleSearch"
          />
          <svg class="w-4 h-4 text-slate-500 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        </div>

        <div class="flex items-center space-x-2 w-full sm:w-auto">
          <select
            v-model="courseFilter"
            class="w-full sm:w-auto px-3 py-2 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
            @change="handleSearch"
          >
            <option value="">All Academic Programs</option>
            <option v-for="c in courses" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>
      </div>

      <!-- Table Container -->
      <div class="bg-slate-900/80 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs text-slate-300">
            <thead class="bg-slate-950/60 text-slate-400 uppercase tracking-wider text-[10px] border-b border-slate-800">
              <tr>
                <th class="px-5 py-3.5">Student ID &amp; Name</th>
                <th class="px-5 py-3.5">Program / Course</th>
                <th class="px-5 py-3.5">Year &amp; Section</th>
                <th class="px-5 py-3.5">Contact / Email</th>
                <th class="px-5 py-3.5">Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/60">
              <tr v-for="s in students.data" :key="s.id" class="hover:bg-slate-800/40 transition">
                <td class="px-5 py-4">
                  <div class="font-bold text-white text-sm">{{ s.first_name }} {{ s.last_name }}</div>
                  <div class="text-blue-400 font-mono text-[11px] mt-0.5">{{ s.student_number }}</div>
                </td>
                <td class="px-5 py-4">
                  <div class="font-semibold text-white">{{ s.course }}</div>
                </td>
                <td class="px-5 py-4 font-mono text-slate-300">
                  {{ s.year_level }} {{ s.section ? '(' + s.section + ')' : '' }}
                </td>
                <td class="px-5 py-4 font-mono text-slate-400 text-[11px]">
                  <div>{{ s.user?.email || 'N/A' }}</div>
                  <div class="text-[10px] text-slate-500">{{ s.phone || 'No phone recorded' }}</div>
                </td>
                <td class="px-5 py-4">
                  <span
                    :class="[
                      'text-[10px] font-bold px-2 py-0.5 rounded-md border uppercase',
                      s.status === 'Active' ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-red-500/10 text-red-400 border-red-500/20'
                    ]"
                  >
                    {{ s.status }}
                  </span>
                </td>
              </tr>
              <tr v-if="!students.data || students.data.length === 0">
                <td colspan="5" class="px-5 py-12 text-center text-slate-500">
                  No student records found matching the search criteria.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <Pagination :page="students" />
  </AppLayout>
</template>

<script setup>
import Pagination from '@/Components/Pagination.vue';
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
  students: Object,
  filters: Object,
  courses: Array,
});

const searchQuery = ref(props.filters?.search || '');
const courseFilter = ref(props.filters?.course || '');

const handleSearch = () => {
  router.get('/students', {
    search: searchQuery.value,
    course: courseFilter.value,
  }, { preserveState: true, replace: true });
};
</script>

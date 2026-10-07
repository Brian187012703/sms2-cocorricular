<template>
  <AppLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 class="text-2xl font-extrabold text-white tracking-tight">Recognized Student Organizations</h1>
          <p class="text-sm text-slate-400 mt-1">
            Directory of campus academic societies, special interest clubs, and cultural organizations.
          </p>
        </div>
      </div>

      <!-- Filters -->
      <div class="p-4 rounded-2xl bg-slate-900/60 border border-slate-800 flex flex-col sm:flex-row gap-3 items-center justify-between">
        <div class="w-full sm:w-80 relative">
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Search organization by name or code..."
            class="w-full pl-9 pr-4 py-2 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
            @keyup.enter="handleSearch"
          />
          <svg class="w-4 h-4 text-slate-500 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        </div>

        <div class="flex items-center space-x-2 w-full sm:w-auto">
          <select
            v-model="categoryFilter"
            class="w-full sm:w-auto px-3 py-2 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
            @change="handleSearch"
          >
            <option value="">All Categories</option>
            <option v-for="cat in categories" :key="cat" :value="cat">{{ cat }}</option>
          </select>
        </div>
      </div>

      <!-- Clubs Grid -->
      <div v-if="clubs.data && clubs.data.length > 0" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        <div
          v-for="c in clubs.data"
          :key="c.id"
          class="p-5 rounded-2xl bg-slate-900/80 border border-slate-800 hover:border-slate-700 transition flex flex-col justify-between space-y-4 shadow-lg"
        >
          <div>
            <div class="flex items-center justify-between">
              <span class="text-[10px] font-mono font-bold px-2 py-0.5 rounded-md bg-blue-500/10 text-blue-400 border border-blue-500/20">
                {{ c.code }}
              </span>
              <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                {{ c.status }}
              </span>
            </div>

            <h3 class="text-base font-bold text-white mt-2 leading-snug">{{ c.name }}</h3>
            <p class="text-xs text-slate-400 mt-1 line-clamp-2">{{ c.description || 'Active BCP accredited student organization.' }}</p>

            <div class="mt-4 space-y-1.5 text-xs text-slate-300">
              <div class="flex items-center justify-between">
                <span class="text-slate-400">Category:</span>
                <span class="font-medium text-white">{{ c.category }}</span>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-slate-400">Program / Department:</span>
                <span class="font-medium text-white">{{ c.program || 'Institutional' }}</span>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-slate-400">Faculty Adviser:</span>
                <span class="font-medium text-blue-400 truncate max-w-[160px]">{{ c.adviser_name || 'Not assigned' }}</span>
              </div>
            </div>
          </div>

          <div class="pt-3 border-t border-slate-800 flex items-center justify-between text-xs">
            <span class="text-slate-500 font-mono text-[11px]">{{ c.memberships_count || 0 }} Members</span>
            <Link
              v-if="userRole === 'student'"
              href="/roster"
              class="text-blue-400 hover:text-blue-300 font-semibold"
            >
              Apply to Join &rarr;
            </Link>
          </div>
        </div>
      </div>

      <!-- Empty State -->
      <div v-else class="p-12 text-center rounded-2xl bg-slate-900/40 border border-slate-800">
        <svg class="w-12 h-12 mx-auto text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
        <h3 class="mt-4 text-base font-bold text-white">No Organizations Found</h3>
        <p class="text-xs text-slate-400 mt-1 max-w-sm mx-auto">No student clubs match the selected filters.</p>
      </div>
    </div>
    <Pagination :page="clubs" />
  </AppLayout>
</template>

<script setup>
import Pagination from '@/Components/Pagination.vue';
import { ref, computed } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
  clubs: Object,
  filters: Object,
  categories: Array,
});

const page = usePage();
const userRole = computed(() => page.props.auth?.user?.role || 'student');

const searchQuery = ref(props.filters?.search || '');
const categoryFilter = ref(props.filters?.category || '');

const handleSearch = () => {
  router.get('/clubs', {
    search: searchQuery.value,
    category: categoryFilter.value,
  }, { preserveState: true, replace: true });
};
</script>

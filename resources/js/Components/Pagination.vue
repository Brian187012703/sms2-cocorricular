<script setup>
import { Link } from '@inertiajs/vue3';
defineProps({ page: Object });
</script>
<template>
  <nav v-if="page && page.last_page > 1" aria-label="Pagination" class="flex items-center justify-between gap-4 mt-5 text-sm">
    <Link v-if="page.prev_page_url" :href="page.prev_page_url" preserve-scroll class="px-4 py-2 rounded-lg bg-slate-800">Previous</Link>
    <span>Page {{ page.current_page }} of {{ page.last_page }}</span>
    <Link v-if="page.next_page_url" :href="page.next_page_url" preserve-scroll class="px-4 py-2 rounded-lg bg-slate-800">Next</Link>
  </nav>
</template>

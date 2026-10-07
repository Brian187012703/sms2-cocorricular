<template>
  <AppLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 class="text-2xl font-extrabold text-white tracking-tight">Institutional Bulletins &amp; Announcements</h1>
          <p class="text-sm text-slate-400 mt-1">
            Broadcast official administrative memos, organization reminders, and campus updates.
          </p>
        </div>

        <div v-if="canPost" class="flex items-center space-x-3">
          <button
            type="button"
            class="inline-flex items-center space-x-2 px-4 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-semibold text-sm shadow-lg shadow-blue-500/25 transition active:scale-95"
            @click="showCreateModal = true"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            <span>Publish Bulletin</span>
          </button>
        </div>
      </div>

      <!-- Announcements Grid -->
      <div v-if="announcements.data && announcements.data.length > 0" class="space-y-4">
        <div
          v-for="a in announcements.data"
          :key="a.id"
          class="p-6 rounded-2xl bg-slate-900/80 border border-slate-800 space-y-3 shadow-xl"
        >
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center space-x-2">
              <span
                :class="[
                  'text-[10px] font-bold px-2.5 py-0.5 rounded-md border uppercase',
                  a.priority === 'Urgent' ? 'bg-red-500/10 text-red-400 border-red-500/20' : (a.priority === 'High' ? 'bg-amber-500/10 text-amber-400 border-amber-500/20' : 'bg-blue-500/10 text-blue-400 border-blue-500/20')
                ]"
              >
                {{ a.priority || 'Normal' }} Priority
              </span>
              <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-slate-800 text-slate-300">
                Audience: {{ a.target_group }}
              </span>
            </div>

            <span class="text-xs text-slate-500 font-mono">{{ formatDate(a.created_at) }}</span>
          </div>

          <h3 class="text-lg font-bold text-white">{{ a.title }}</h3>
          <p class="text-xs sm:text-sm text-slate-300 whitespace-pre-line leading-relaxed">{{ a.content }}</p>

          <div class="pt-3 border-t border-slate-800/80 flex items-center justify-between text-xs text-slate-400">
            <div>
              Published by: <span class="text-slate-200 font-semibold">{{ a.author ? `${a.author.first_name} ${a.author.last_name}` : 'Institutional Office' }}</span>
            </div>
            <div v-if="a.club" class="text-blue-400 font-medium">
              {{ a.club.name }}
            </div>
          </div>
        </div>
      </div>

      <!-- Empty State -->
      <div v-else class="p-12 text-center rounded-2xl bg-slate-900/40 border border-slate-800">
        <svg class="w-12 h-12 mx-auto text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>
        <h3 class="mt-4 text-base font-bold text-white">No Bulletins Posted</h3>
        <p class="text-xs text-slate-400 mt-1 max-w-sm mx-auto">There are currently no active announcements matching your target role.</p>
      </div>

      <!-- Publish Modal -->
      <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-lg w-full p-6 space-y-4 shadow-2xl">
          <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h3 class="text-base font-bold text-white">Publish New Announcement</h3>
            <button class="text-slate-400 hover:text-white" @click="showCreateModal = false">&times;</button>
          </div>

          <form class="space-y-4" @submit.prevent="submitAnnouncement">
            <div>
              <label class="block text-xs font-semibold text-slate-300 mb-1">Bulletin Title</label>
              <input
                v-model="createForm.title"
                type="text"
                required
                placeholder="e.g. Schedule of Club Accreditation Renewal"
                class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
              />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Target Audience</label>
                <select
                  v-model="createForm.target_group"
                  required
                  class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
                >
                  <option value="All">All Campus</option>
                  <option value="Students">Students Only</option>
                  <option value="Advisers">Faculty Advisers</option>
                  <option value="SSC">Supreme Student Council</option>
                </select>
              </div>

              <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Priority Level</label>
                <select
                  v-model="createForm.priority"
                  class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
                >
                  <option value="Normal">Normal</option>
                  <option value="Important">Important</option>
                  <option value="Urgent">Urgent / Alert</option>
                </select>
              </div>
            </div>

            <div v-if="clubs && clubs.length > 0">
              <label class="block text-xs font-semibold text-slate-300 mb-1">Associated Organization (Optional)</label>
              <select
                v-model="createForm.club_id"
                class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
              >
                <option :value="null">Institutional (All Campus)</option>
                <option v-for="c in clubs" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
            </div>

            <div>
              <label class="block text-xs font-semibold text-slate-300 mb-1">Announcement Body</label>
              <textarea
                v-model="createForm.content"
                rows="4"
                required
                placeholder="Full content of the bulletin or memo..."
                class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
              ></textarea>
            </div>

            <div class="flex items-center justify-end space-x-3 pt-3 border-t border-slate-800">
              <button
                type="button"
                class="px-4 py-2 rounded-xl bg-slate-800 text-slate-300 text-xs font-semibold"
                @click="showCreateModal = false"
              >
                Cancel
              </button>
              <button
                type="submit"
                class="px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold shadow-lg shadow-blue-500/25"
              >
                Publish Now
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <Pagination :page="announcements" />
  </AppLayout>
</template>

<script setup>
import Pagination from '@/Components/Pagination.vue';
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
  announcements: Object,
  clubs: Array,
  canPost: Boolean,
});

const showCreateModal = ref(false);

const createForm = useForm({
  title: '',
  content: '',
  target_group: 'All',
  priority: 'Normal',
  club_id: null,
});

const submitAnnouncement = () => {
  createForm.post('/announcements', {
    onSuccess: () => {
      showCreateModal.value = false;
      createForm.reset();
    }
  });
};

const formatDate = (dateStr) => {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  return d.toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
};
</script>

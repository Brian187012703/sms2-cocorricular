<template>
  <AppLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 class="text-2xl font-extrabold text-white tracking-tight">Attendance &amp; Participation Tracking</h1>
          <p class="text-sm text-slate-400 mt-1">
            Real-time event check-in records, QR attendance validation, and duplicate scan protection logs.
          </p>
        </div>

        <div class="flex items-center space-x-3">
          <button
            v-if="userRole === 'student'"
            type="button"
            class="inline-flex items-center space-x-2 px-4 py-2.5 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white font-semibold text-sm shadow-lg shadow-blue-500/25 transition active:scale-95"
            @click="showQrModal = true"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>
            <span>My Digital Event Pass</span>
          </button>

          <Link
            v-if="userRole !== 'student'"
            href="/attendance/scanner"
            class="inline-flex items-center space-x-2 px-4 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-semibold text-sm shadow-lg shadow-blue-500/25 transition active:scale-95"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            <span>Launch Scanner</span>
          </Link>
        </div>
      </div>

      <!-- Stats -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="p-4 rounded-2xl bg-slate-900/70 border border-slate-800">
          <div class="text-xs font-semibold text-slate-400 uppercase tracking-wider">
            {{ userRole === 'student' ? 'My Verified Check-Ins' : 'Total Valid Check-Ins' }}
          </div>
          <div class="mt-2 text-2xl font-black text-white">{{ totalScans || 0 }}</div>
        </div>
        <div class="p-4 rounded-2xl bg-slate-900/70 border border-slate-800">
          <div class="text-xs font-semibold text-emerald-400 uppercase tracking-wider">Tracked Events</div>
          <div class="mt-2 text-2xl font-black text-emerald-400">{{ uniqueEvents || 0 }}</div>
        </div>
        <div class="p-4 rounded-2xl bg-slate-900/70 border border-slate-800">
          <div class="text-xs font-semibold text-blue-400 uppercase tracking-wider">Verification Integrity</div>
          <div class="mt-2 text-2xl font-black text-blue-400">100% Anti-Duplicate</div>
        </div>
      </div>

      <!-- Filters -->
      <div class="p-4 rounded-2xl bg-slate-900/60 border border-slate-800 flex flex-col sm:flex-row gap-3 items-center justify-between">
        <div class="w-full sm:w-72 relative">
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Search student name or ID..."
            class="w-full pl-9 pr-4 py-2 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
            @keyup.enter="applyFilters"
          />
          <svg class="w-4 h-4 text-slate-500 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        </div>

        <div class="w-full sm:w-auto">
          <select
            v-model="eventFilter"
            class="w-full sm:w-auto px-3 py-2 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
            @change="applyFilters"
          >
            <option value="">All Events</option>
            <option v-for="ev in events" :key="ev.id" :value="ev.id">
              {{ ev.title }} ({{ ev.venue }}) &bull; [{{ ev.audience_type || 'Inclusive' }}]
            </option>
          </select>
        </div>
      </div>

      <!-- Table Container -->
      <div class="bg-slate-900/80 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs text-slate-300">
            <thead class="bg-slate-950/60 text-slate-400 uppercase tracking-wider text-[10px] border-b border-slate-800">
              <tr>
                <th class="px-5 py-3.5">Student Attendee</th>
                <th class="px-5 py-3.5">Event &amp; Organization</th>
                <th class="px-5 py-3.5">Check-In Timestamp</th>
                <th class="px-5 py-3.5">Scan Method</th>
                <th class="px-5 py-3.5">Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/60">
              <tr v-for="l in logs.data" :key="l.id" class="hover:bg-slate-800/40 transition">
                <td class="px-5 py-4">
                  <div class="font-bold text-white text-sm">
                    {{ l.user ? `${l.user.first_name} ${l.user.last_name}` : 'Unknown Student' }}
                  </div>
                  <div class="text-slate-400 text-[11px] mt-0.5 font-mono">
                    {{ l.user?.student ? l.user.student.student_number : l.user?.username }}
                  </div>
                </td>
                <td class="px-5 py-4">
                  <div class="font-semibold text-white">{{ l.event ? l.event.title : 'Event #' + l.event_id }}</div>
                  <div class="text-slate-400 text-[11px] flex items-center space-x-1.5 mt-0.5">
                    <span>{{ l.event?.club ? l.event.club.name : 'Campus Activity' }}</span>
                    <span
                      v-if="l.event?.audience_type"
                      :class="[
                        'text-[9px] font-bold px-1.5 py-0.2 rounded border',
                        l.event.audience_type === 'Exclusive' ? 'text-purple-400 border-purple-500/30 bg-purple-500/10' : 'text-cyan-400 border-cyan-500/30 bg-cyan-500/10'
                      ]"
                    >
                      {{ l.event.audience_type }}
                    </span>
                  </div>
                </td>
                <td class="px-5 py-4 font-mono text-slate-300">
                  {{ formatDate(l.check_in) }}
                </td>
                <td class="px-5 py-4">
                  <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-blue-500/10 text-blue-400 border border-blue-500/20 font-mono">
                    {{ l.method || 'QR' }}
                  </span>
                </td>
                <td class="px-5 py-4">
                  <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                    {{ l.status || 'Valid' }}
                  </span>
                </td>
              </tr>
              <tr v-if="!logs.data || logs.data.length === 0">
                <td colspan="5" class="px-5 py-12 text-center text-slate-500">
                  No attendance records found matching the current query.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Student Digital QR Event Pass Modal -->
      <div v-if="showQrModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-md">
        <div class="bg-slate-900 border border-slate-700 rounded-3xl max-w-md w-full p-6 sm:p-7 space-y-5 shadow-2xl text-center">
          <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h3 class="text-base font-bold text-white text-left">Personal Digital Event Pass</h3>
            <button class="text-slate-400 hover:text-white" @click="showQrModal = false">&times;</button>
          </div>

          <!-- Select Event -->
          <div class="text-left space-y-1">
            <label class="block text-xs font-semibold text-slate-300">Select Target Campus Event</label>
            <select
              v-model="selectedPassEventId"
              class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none font-semibold"
            >
              <option v-for="ev in events" :key="ev.id" :value="ev.id">
                {{ ev.title }} ({{ ev.audience_type || 'Inclusive' }})
              </option>
            </select>
          </div>

          <!-- Exclusive Restriction Alert -->
          <div
            v-if="currentPassEvent && !currentPassEvent.is_eligible"
            class="p-4 rounded-2xl bg-purple-950/60 border border-purple-700 text-purple-200 text-xs text-left space-y-1.5"
          >
            <div class="font-bold flex items-center space-x-1.5 text-purple-300">
              <span>🔒</span>
              <span>Restricted: Exclusive Club Event</span>
            </div>
            <p class="text-[11px] leading-relaxed">
              This event is restricted exclusively to active enrolled members of <strong>{{ currentPassEvent.club?.name || 'the organizing club' }}</strong>. You are currently not registered as an active member in this organization.
            </p>
          </div>

          <!-- Verified Pass Container (If Eligible or Inclusive) -->
          <div v-else class="space-y-4">
            <!-- Simulated QR Code Card -->
            <div class="p-6 bg-white rounded-3xl shadow-2xl flex flex-col items-center justify-center space-y-3 mx-auto max-w-xs">
              <!-- Digital Barcode / QR Graphic -->
              <div class="h-44 w-44 bg-slate-950 rounded-2xl p-3 flex flex-col items-center justify-between border-4 border-slate-900">
                <div class="grid grid-cols-6 gap-1 w-full h-full p-1 bg-white rounded-lg">
                  <!-- QR Aesthetic Grid -->
                  <div class="bg-black col-span-2 row-span-2 rounded-sm"></div>
                  <div class="bg-black col-span-1"></div>
                  <div class="bg-black col-span-1"></div>
                  <div class="bg-black col-span-2 row-span-2 rounded-sm"></div>
                  <div class="bg-black col-span-1"></div>
                  <div class="bg-black col-span-2"></div>
                  <div class="bg-black col-span-1"></div>
                  <div class="bg-black col-span-2"></div>
                  <div class="bg-black col-span-1"></div>
                  <div class="bg-black col-span-2"></div>
                  <div class="bg-black col-span-2 row-span-2 rounded-sm"></div>
                  <div class="bg-black col-span-2"></div>
                  <div class="bg-black col-span-2"></div>
                  <div class="bg-black col-span-2"></div>
                </div>
              </div>

              <div class="text-center">
                <div class="font-black text-slate-900 text-sm tracking-tight">
                  {{ currentUser.first_name }} {{ currentUser.last_name }}
                </div>
                <div class="font-mono text-xs font-bold text-blue-600 tracking-wider">
                  {{ currentUser.username }}
                </div>
              </div>
            </div>

            <div class="inline-flex items-center space-x-1.5 px-3 py-1 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/30 text-xs font-bold">
              <span>✓ Verified Eligible Attendee</span>
              <span v-if="currentPassEvent?.audience_type === 'Exclusive'">• Club Member</span>
            </div>
            <p class="text-[11px] text-slate-400">
              Present this code at the registration table or QR scanner terminal for automated check-in.
            </p>
          </div>

          <div class="pt-3 border-t border-slate-800">
            <button
              type="button"
              class="w-full py-2.5 rounded-xl bg-slate-800 text-slate-200 text-xs font-semibold hover:bg-slate-700 transition"
              @click="showQrModal = false"
            >
              Done
            </button>
          </div>
        </div>
      </div>
    </div>
    <Pagination :page="logs" />
  </AppLayout>
</template>

<script setup>
import Pagination from '@/Components/Pagination.vue';
import { ref, computed } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
  logs: Object,
  events: Array,
  filters: Object,
  totalScans: Number,
  uniqueEvents: Number,
});

const page = usePage();
const userRole = computed(() => page.props.auth?.user?.role || 'student');
const currentUser = computed(() => page.props.auth?.user || {});

const searchQuery = ref(props.filters?.search || '');
const eventFilter = ref(props.filters?.event_id || '');
const showQrModal = ref(false);

const selectedPassEventId = ref(props.events?.[0]?.id || '');

const currentPassEvent = computed(() => {
  return props.events?.find((e) => e.id === Number(selectedPassEventId.value)) || props.events?.[0] || null;
});

const applyFilters = () => {
  router.get('/attendance', {
    search: searchQuery.value,
    event_id: eventFilter.value,
  }, { preserveState: true, replace: true });
};

const formatDate = (dateStr) => {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  return d.toLocaleDateString('en-US', {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
};
</script>

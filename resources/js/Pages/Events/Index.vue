<template>
  <AppLayout>
    <div class="space-y-6">
      <!-- Page Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 class="text-2xl font-extrabold text-white tracking-tight">Events &amp; Activity Center</h1>
          <p class="text-sm text-slate-400 mt-1">
            Browse campus activities, check venue schedules, and manage multi-tier approval workflows.
          </p>
        </div>

        <div v-if="userRole !== 'student'" class="flex items-center space-x-3">
          <button
            type="button"
            class="inline-flex items-center space-x-2 px-4 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-semibold text-sm shadow-lg shadow-blue-500/25 transition active:scale-95"
            @click="openCreateModal"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            <span>Propose Event</span>
          </button>
        </div>
      </div>

      <!-- Metric Stats -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="p-4 rounded-2xl bg-slate-900/70 border border-slate-800">
          <div class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Total Scheduled</div>
          <div class="mt-2 text-2xl font-black text-white">{{ metrics?.total || 0 }}</div>
        </div>
        <div class="p-4 rounded-2xl bg-slate-900/70 border border-slate-800">
          <div class="text-xs font-semibold text-emerald-400 uppercase tracking-wider">Approved &amp; Live</div>
          <div class="mt-2 text-2xl font-black text-emerald-400">{{ metrics?.approved || 0 }}</div>
        </div>
        <div class="p-4 rounded-2xl bg-slate-900/70 border border-slate-800">
          <div class="text-xs font-semibold text-amber-400 uppercase tracking-wider">Pending SSC Review</div>
          <div class="mt-2 text-2xl font-black text-amber-400">{{ metrics?.pending_ssc || 0 }}</div>
        </div>
        <div class="p-4 rounded-2xl bg-slate-900/70 border border-slate-800">
          <div class="text-xs font-semibold text-purple-400 uppercase tracking-wider">Pending Admin Clearance</div>
          <div class="mt-2 text-2xl font-black text-purple-400">{{ metrics?.pending_admin || 0 }}</div>
        </div>
      </div>

      <!-- Filter Bar -->
      <div class="p-4 rounded-2xl bg-slate-900/60 border border-slate-800 flex flex-col sm:flex-row gap-3 items-center justify-between">
        <div class="w-full sm:w-72 relative">
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Search event title or venue..."
            class="w-full pl-9 pr-4 py-2 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500 transition"
            @keyup.enter="applyFilters"
          />
          <svg class="w-4 h-4 text-slate-500 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        </div>

        <div v-if="userRole !== 'student'" class="flex items-center space-x-2 w-full sm:w-auto">
          <select
            v-model="statusFilter"
            class="w-full sm:w-auto px-3 py-2 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white text-xs focus:outline-none focus:ring-2 focus:ring-blue-500"
            @change="applyFilters"
          >
            <option value="">All Statuses</option>
            <option value="Approved">Approved</option>
            <option value="Pending Adviser">Pending Adviser</option>
            <option value="Pending SSC">Pending SSC</option>
            <option value="Pending Admin">Pending Admin</option>
            <option value="Rejected">Rejected</option>
          </select>
        </div>
      </div>

      <!-- Events List -->
      <div v-if="events && events.length > 0" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        <div
          v-for="ev in events"
          :key="ev.id"
          class="p-5 rounded-2xl bg-slate-900/80 border border-slate-800 hover:border-slate-700 transition flex flex-col justify-between space-y-4 shadow-lg"
        >
          <div>
            <div class="flex items-start justify-between gap-2 flex-wrap">
              <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-blue-500/10 text-blue-400 border border-blue-500/20 uppercase tracking-wide">
                {{ ev.club ? ev.club.name : 'Campus-Wide' }}
              </span>
              <div class="flex items-center space-x-1.5">
                <span
                  :class="[
                    'text-[10px] font-bold px-2 py-0.5 rounded-md border uppercase',
                    ev.audience_type === 'Exclusive'
                      ? 'bg-purple-500/10 text-purple-400 border-purple-500/20'
                      : 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20'
                  ]"
                >
                  {{ ev.audience_type === 'Exclusive' ? '🔒 Exclusive' : '🌐 Inclusive' }}
                </span>
                <span :class="statusBadge(ev.status)">
                  {{ ev.status }}
                </span>
              </div>
            </div>

            <h3 class="text-base font-bold text-white mt-2 leading-snug">{{ ev.title }}</h3>
            <p class="text-xs text-slate-400 mt-1 line-clamp-2">{{ ev.description || 'No description provided.' }}</p>

            <!-- Conflict Badge if venue conflict detected -->
            <div v-if="ev.has_conflict" class="mt-2.5 p-2 rounded-lg bg-red-950/40 border border-red-800/60 text-red-300 text-[11px] flex items-center space-x-1.5">
              <svg class="w-3.5 h-3.5 shrink-0 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
              <span>Schedule Overlap: {{ ev.conflicting_with || 'Venue Conflict' }}</span>
            </div>

            <div class="mt-4 space-y-1.5 text-xs text-slate-300">
              <div class="flex items-center space-x-2">
                <svg class="w-4 h-4 text-slate-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                <span class="font-medium text-white">{{ formatSchedule(ev.event_date, ev.end_time) }}</span>
              </div>
              <div class="flex items-center space-x-2">
                <svg class="w-4 h-4 text-slate-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                <span class="truncate">{{ ev.venue }}</span>
              </div>
              <div class="flex items-center space-x-2">
                <svg class="w-4 h-4 text-slate-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                <span>Expected: {{ ev.expected_attendees }} attendees</span>
              </div>
            </div>
          </div>

          <!-- Action Buttons Based on Role -->
          <div class="pt-3 border-t border-slate-800 flex items-center justify-between gap-2">
            <div class="flex items-center space-x-2 ml-auto">
              <button
                v-if="userRole === 'club_adviser' && ev.status === 'Pending Adviser'"
                type="button"
                class="px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold"
                @click="router.post('/events/' + ev.id + '/endorse-adviser')"
              >
                Endorse
              </button>

              <!-- SSC Endorse Action -->
              <button
                v-if="userRole === 'ssc' && ev.status === 'Pending SSC'"
                type="button"
                class="px-3 py-1.5 rounded-lg bg-amber-600 hover:bg-amber-500 text-white font-semibold text-xs transition"
                @click="endorseSsc(ev.id)"
              >
                Endorse to Admin
              </button>

              <!-- Admin Final Approve Action -->
              <button
                v-if="userRole === 'admin' && ev.status === 'Pending Admin'"
                type="button"
                class="px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-xs transition"
                @click="approveAdmin(ev.id)"
              >
                Approve &amp; Publish
              </button>

              <!-- Reject Action -->
              <button
                v-if="(userRole === 'admin' || userRole === 'ssc') && ev.status.includes('Pending')"
                type="button"
                class="px-2.5 py-1.5 rounded-lg bg-red-600/20 text-red-400 hover:bg-red-600/30 text-xs transition"
                @click="rejectEvent(ev.id)"
              >
                Reject
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Empty State -->
      <div v-else class="p-12 text-center rounded-2xl bg-slate-900/40 border border-slate-800">
        <svg class="w-12 h-12 mx-auto text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        <h3 class="mt-4 text-base font-bold text-white">No Events Found</h3>
        <p class="text-xs text-slate-400 mt-1 max-w-sm mx-auto">There are currently no events matching your criteria in the system database.</p>
      </div>

      <!-- Propose Event Modal with Real-Time Conflict Checker & Time End -->
      <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-sm">
        <div class="bg-slate-900 border border-slate-700 rounded-3xl max-w-xl w-full p-6 sm:p-7 space-y-4 shadow-2xl max-h-[90vh] overflow-y-auto custom-scrollbar">
          <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <div>
              <h3 class="text-base font-bold text-white">Propose Campus Event</h3>
              <p class="text-xs text-slate-400">Includes real-time venue conflict detection and audience scoping.</p>
            </div>
            <button class="text-slate-400 hover:text-white" @click="showCreateModal = false">&times;</button>
          </div>

          <form class="space-y-4" @submit.prevent="submitEvent">
            <div>
              <label class="block text-xs font-semibold text-slate-300 mb-1">Host Organization</label>
              <select
                v-model="createForm.club_id"
                required
                class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
              >
                <option value="" disabled>Select organization</option>
                <option v-for="c in clubs" :key="c.id" :value="c.id">{{ c.name }} ({{ c.code }})</option>
              </select>
            </div>

            <div>
              <label class="block text-xs font-semibold text-slate-300 mb-1">Event Title</label>
              <input
                v-model="createForm.title"
                type="text"
                required
                placeholder="e.g. IT Annual Innovation Summit 2026"
                class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
              />
            </div>

            <!-- Start and End Date/Time -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Event Start (Date &amp; Time)</label>
                <input
                  v-model="createForm.event_date"
                  type="datetime-local"
                  required
                  class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
                  @change="triggerConflictCheck"
                />
              </div>

              <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Time End (Required)</label>
                <input
                  v-model="createForm.end_time"
                  type="datetime-local"
                  required
                  class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
                  @change="triggerConflictCheck"
                />
              </div>
            </div>

            <div>
              <label class="block text-xs font-semibold text-slate-300 mb-1">Venue / Facility Location</label>
              <div class="relative">
                <input
                  v-model="createForm.venue"
                  type="text"
                  required
                  placeholder="e.g. BCP Main Gymnasium / AVR 1 / Quadrangle"
                  class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
                  @input="triggerConflictCheck"
                />
              </div>
            </div>

            <!-- Real-Time Conflict Checker Alert Box -->
            <div
              v-if="conflictStatus.checked"
              :class="[
                'p-3 rounded-xl border text-xs transition duration-200',
                conflictStatus.hasConflict
                  ? 'bg-red-950/60 border-red-500/80 text-red-200'
                  : 'bg-emerald-950/40 border-emerald-500/40 text-emerald-300'
              ]"
            >
              <div class="flex items-start space-x-2">
                <span class="text-base shrink-0">{{ conflictStatus.hasConflict ? '⚠️' : '✅' }}</span>
                <div>
                  <div class="font-bold">
                    {{ conflictStatus.hasConflict ? 'Venue Conflict Detected!' : 'Venue & Schedule Available' }}
                  </div>
                  <div class="text-[11px] mt-0.5 opacity-90">{{ conflictStatus.message }}</div>
                </div>
              </div>
            </div>

            <!-- Audience Exclusivity Scope -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Audience Scope &amp; QR Access</label>
                <select
                  v-model="createForm.audience_type"
                  class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none font-semibold"
                >
                  <option value="Inclusive">🌐 Inclusive (Open to All Campus Students)</option>
                  <option value="Exclusive">🔒 Exclusive (Club Enrolled Members Only)</option>
                </select>
                <span class="text-[10px] text-slate-400 mt-1 block">
                  {{ createForm.audience_type === 'Exclusive' ? 'Only active club members may register & scan in.' : 'Open campus-wide to all active students.' }}
                </span>
              </div>

              <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Expected Attendees</label>
                <input
                  v-model="createForm.expected_attendees"
                  type="number"
                  min="1"
                  placeholder="100"
                  class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
                />
              </div>
            </div>

            <div>
              <div class="flex items-center justify-between mb-1">
                <label class="block text-xs font-semibold text-slate-300">Description &amp; Activity Plan</label>
                <button
                  type="button"
                  class="text-[11px] text-blue-400 hover:text-blue-300 font-semibold"
                  @click="insertEventProposalTemplate"
                >
                  📄 Insert Proposal Template
                </button>
              </div>
              <textarea
                v-model="createForm.description"
                rows="4"
                placeholder="Detail the event objectives, schedule, and logistics..."
                class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none font-mono"
              ></textarea>
            </div>

            <div class="flex items-center justify-end space-x-3 pt-3 border-t border-slate-800">
              <button
                type="button"
                class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-semibold"
                @click="showCreateModal = false"
              >
                Cancel
              </button>
              <button
                type="submit"
                :disabled="createForm.processing || conflictStatus.hasConflict"
                class="px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 disabled:opacity-50 text-white text-xs font-semibold shadow-lg shadow-blue-500/25"
              >
                {{ createForm.processing ? 'Submitting...' : 'Submit Proposal' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, usePage, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
  events: Array,
  clubs: Array,
  filters: Object,
  metrics: Object,
});

const page = usePage();
const userRole = computed(() => page.props.auth?.user?.role || 'student');

const searchQuery = ref(props.filters?.search || '');
const statusFilter = ref(props.filters?.status || '');
const showCreateModal = ref(false);

const conflictStatus = ref({
  checked: false,
  hasConflict: false,
  message: '',
});

let conflictTimer = null;

const createForm = useForm({
  club_id: '',
  title: '',
  description: '',
  event_date: '',
  end_time: '',
  venue: '',
  expected_attendees: '',
  event_type: 'Club Activity',
  audience_type: 'Inclusive',
});

const openCreateModal = () => {
  createForm.reset();
  conflictStatus.value = { checked: false, hasConflict: false, message: '' };
  showCreateModal.value = true;
};

const triggerConflictCheck = () => {
  if (!createForm.venue || !createForm.event_date) return;
  if (conflictTimer) clearTimeout(conflictTimer);

  conflictTimer = setTimeout(async () => {
    try {
      const response = await fetch('/events/check-conflict', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
          'Accept': 'application/json',
        },
        body: JSON.stringify({
          venue: createForm.venue,
          event_date: createForm.event_date,
          end_time: createForm.end_time || null,
        }),
      });
      const data = await response.json();
      conflictStatus.value = {
        checked: true,
        hasConflict: data.has_conflict,
        message: data.message,
      };
    } catch (err) {
      console.error('Conflict check error', err);
    }
  }, 400);
};

const insertEventProposalTemplate = () => {
  createForm.description = `ACTIVITY PROPOSAL & RATIONALE
Bestlink College of the Philippines

1. OBJECTIVES: Promote academic excellence, technical competencies, and active co-curricular engagement.
2. SCHEDULE FLOW:
   - 08:30 AM: Registration & QR Check-In
   - 09:00 AM: Opening Program & Keynote
   - 12:00 PM: Lunch Break
   - 01:00 PM: Interactive Workshops
   - 04:00 PM: Closing & Digital Certificates
3. LOGISTICS: Sound systems, projector, safety marshals, and venue sanitation protocols arranged.`;
};

const applyFilters = () => {
  router.get('/events', {
    search: searchQuery.value,
    status: statusFilter.value,
  }, { preserveState: true, replace: true });
};

const submitEvent = () => {
  createForm.post('/events', {
    onSuccess: () => {
      showCreateModal.value = false;
      createForm.reset();
      conflictStatus.value = { checked: false, hasConflict: false, message: '' };
    }
  });
};

const endorseSsc = (id) => {
  if (confirm('Endorse this event and forward to Administrator clearance?')) {
    router.post(`/events/${id}/endorse-ssc`);
  }
};

const approveAdmin = (id) => {
  if (confirm('Grant final institutional approval and post to campus calendar?')) {
    router.post(`/events/${id}/approve-admin`);
  }
};

const rejectEvent = (id) => {
  const reason = prompt('Please enter the reason for rejection:');
  if (reason) {
    router.post(`/events/${id}/reject`, { reason });
  }
};

const formatSchedule = (startStr, endStr) => {
  if (!startStr) return '';
  const dStart = new Date(startStr);
  const dateFormatted = dStart.toLocaleDateString('en-US', {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
  const startTime = dStart.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });

  if (endStr) {
    const dEnd = new Date(endStr);
    const endTime = dEnd.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
    return `${dateFormatted} • ${startTime} – ${endTime}`;
  }
  return `${dateFormatted} • ${startTime}`;
};

const statusBadge = (status) => {
  switch (status) {
    case 'Approved':
      return 'text-[10px] font-bold px-2 py-0.5 rounded-md bg-emerald-500/10 text-emerald-400 border border-emerald-500/20';
    case 'Pending SSC':
      return 'text-[10px] font-bold px-2 py-0.5 rounded-md bg-amber-500/10 text-amber-400 border border-amber-500/20';
    case 'Pending Admin':
      return 'text-[10px] font-bold px-2 py-0.5 rounded-md bg-purple-500/10 text-purple-400 border border-purple-500/20';
    case 'Rejected':
      return 'text-[10px] font-bold px-2 py-0.5 rounded-md bg-red-500/10 text-red-400 border border-red-500/20';
    default:
      return 'text-[10px] font-bold px-2 py-0.5 rounded-md bg-slate-500/10 text-slate-400 border border-slate-500/20';
  }
};
</script>

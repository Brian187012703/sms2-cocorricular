<template>
  <AppLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 class="text-2xl font-extrabold text-white tracking-tight">Student Elections &amp; Governance</h1>
          <p class="text-sm text-slate-400 mt-1">
            Official collegiate voting portal: cast secret ballots, view candidate platforms, and audit real-time vote tallies.
          </p>
        </div>

        <div v-if="userRole === 'ssc' || userRole === 'admin'" class="flex items-center space-x-3">
          <button
            type="button"
            class="inline-flex items-center space-x-2 px-4 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-semibold text-sm shadow-lg shadow-blue-500/25 transition active:scale-95"
            @click="showCreateModal = true"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            <span>Initialize Election</span>
          </button>
        </div>
      </div>

      <!-- Metrics -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="p-4 rounded-2xl bg-slate-900/70 border border-slate-800">
          <div class="text-xs font-semibold text-emerald-400 uppercase tracking-wider">Active Elections Open</div>
          <div class="mt-2 text-2xl font-black text-emerald-400">{{ metrics?.active || 0 }}</div>
        </div>
        <div class="p-4 rounded-2xl bg-slate-900/70 border border-slate-800">
          <div class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Total Ballots Cast</div>
          <div class="mt-2 text-2xl font-black text-white">{{ metrics?.total_votes || 0 }}</div>
        </div>
        <div class="p-4 rounded-2xl bg-slate-900/70 border border-slate-800">
          <div class="text-xs font-semibold text-blue-400 uppercase tracking-wider">Archived Sessions</div>
          <div class="mt-2 text-2xl font-black text-blue-400">{{ metrics?.total || 0 }}</div>
        </div>
      </div>

      <!-- Elections Cards -->
      <div v-if="elections && elections.length > 0" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        <div
          v-for="el in elections"
          :key="el.id"
          class="p-5 rounded-2xl bg-slate-900/80 border border-slate-800 hover:border-slate-700 transition flex flex-col justify-between space-y-4 shadow-lg"
        >
          <div>
            <div class="flex items-center justify-between">
              <span class="text-[10px] font-mono px-2 py-0.5 rounded-md bg-blue-500/10 text-blue-400 border border-blue-500/20 font-bold uppercase">
                {{ el.election_code }}
              </span>
              <span
                :class="[
                  'text-[10px] font-bold px-2 py-0.5 rounded-md border uppercase',
                  el.status === 'active' ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-slate-500/10 text-slate-400 border-slate-500/20'
                ]"
              >
                {{ el.status === 'active' ? 'Open for Voting' : 'Closed' }}
              </span>
            </div>

            <h3 class="text-base font-bold text-white mt-2 leading-snug">{{ el.title }}</h3>
            <p class="text-xs text-slate-400 mt-1 line-clamp-2">{{ el.description || 'Student governance electoral council.' }}</p>

            <div class="mt-4 space-y-1.5 text-xs text-slate-300">
              <div class="flex items-center justify-between">
                <span class="text-slate-400">Organization:</span>
                <span class="font-medium text-white truncate max-w-[180px]">{{ el.club ? el.club.name : 'Campus-Wide' }}</span>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-slate-400">Candidates:</span>
                <span class="font-semibold text-white">{{ el.candidates_count || 0 }} Filed</span>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-slate-400">Voters Participated:</span>
                <span class="font-semibold text-white">{{ el.voters_count || 0 }}</span>
              </div>
            </div>

            <!-- Student Voting Status Indicator -->
            <div v-if="userRole === 'student'" class="mt-3">
              <div v-if="el.has_voted" class="p-2 rounded-lg bg-emerald-950/40 border border-emerald-800/60 text-emerald-300 text-xs flex items-center space-x-1.5">
                <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                <span>You have successfully voted</span>
              </div>
              <div v-else-if="el.status === 'active'" class="p-2 rounded-lg bg-amber-950/40 border border-amber-800/60 text-amber-300 text-xs flex items-center space-x-1.5">
                <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                <span>Ballot pending: Vote required</span>
              </div>
            </div>
          </div>

          <div class="pt-3 border-t border-slate-800 flex items-center justify-between">
            <Link
              :href="`/elections/${el.id}`"
              :class="[
                'w-full text-center px-4 py-2 rounded-xl text-xs font-semibold transition shadow-md',
                (!el.has_voted && el.status === 'active' && userRole === 'student')
                  ? 'bg-blue-600 hover:bg-blue-500 text-white shadow-blue-500/25 active:scale-98'
                  : 'bg-slate-800 hover:bg-slate-700 text-slate-200'
              ]"
            >
              {{ (!el.has_voted && el.status === 'active' && userRole === 'student') ? 'Cast Secret Ballot' : 'View Ballot & Results' }}
            </Link>
          </div>
        </div>
      </div>

      <!-- Empty State -->
      <div v-else class="p-12 text-center rounded-2xl bg-slate-900/40 border border-slate-800">
        <svg class="w-12 h-12 mx-auto text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
        <h3 class="mt-4 text-base font-bold text-white">No Active Elections</h3>
        <p class="text-xs text-slate-400 mt-1 max-w-sm mx-auto">There are currently no active student governance elections scheduled in the database.</p>
      </div>

      <!-- Create Election Modal -->
      <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-lg w-full p-6 space-y-4 shadow-2xl">
          <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h3 class="text-lg font-bold text-white">Initialize New Election</h3>
            <button class="text-slate-400 hover:text-white" @click="showCreateModal = false">&times;</button>
          </div>

          <form class="space-y-4" @submit.prevent="submitElection">
            <!-- Election Template Selector -->
            <div>
              <div class="flex items-center justify-between mb-1">
                <label class="block text-xs font-semibold text-slate-300">Choose Election Template</label>
                <span class="text-[10px] text-cyan-400 font-medium">📋 Standardized Slates</span>
              </div>
              <select
                v-model="selectedElectionTemplate"
                class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
                @change="applyElectionTemplate"
              >
                <option value="">-- Select Template (Optional) --</option>
                <option value="ssc">Supreme Student Council General Election (Executive Slate)</option>
                <option value="club">Academic Club Executive Board (Officers Slate)</option>
                <option value="year_level">Departmental &amp; Year-Level Representative Slate</option>
                <option value="plebiscite">Student Plebiscite / Charter Referendum</option>
              </select>
            </div>

            <div>
              <label class="block text-xs font-semibold text-slate-300 mb-1">Organization</label>
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
              <label class="block text-xs font-semibold text-slate-300 mb-1">Election Title</label>
              <input
                v-model="createForm.title"
                type="text"
                required
                placeholder="e.g. SSC Executive Council Election 2026"
                class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
              />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Voting Starts</label>
                <input
                  v-model="createForm.starts_at"
                  type="datetime-local"
                  required
                  class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
                />
              </div>

              <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Voting Closes</label>
                <input
                  v-model="createForm.closes_at"
                  type="datetime-local"
                  required
                  class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
                />
              </div>
            </div>

            <div>
              <label class="block text-xs font-semibold text-slate-300 mb-1">Description / Rules</label>
              <textarea
                v-model="createForm.description"
                rows="2"
                placeholder="Official election guidelines..."
                class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
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
                class="px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold shadow-lg shadow-blue-500/25"
              >
                Open Election
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
import { Link, usePage, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
  elections: Array,
  clubs: Array,
  metrics: Object,
});

const page = usePage();
const userRole = computed(() => page.props.auth?.user?.role || 'student');

const showCreateModal = ref(false);
const selectedElectionTemplate = ref('');

const createForm = useForm({
  club_id: '',
  title: '',
  description: '',
  starts_at: '',
  closes_at: '',
  eligible_voters: null,
});

const applyElectionTemplate = () => {
  const tmpl = selectedElectionTemplate.value;
  const now = new Date();
  const starts = new Date(now.getTime() + 24 * 60 * 60 * 1000).toISOString().slice(0, 16);
  const closes = new Date(now.getTime() + 72 * 60 * 60 * 1000).toISOString().slice(0, 16);

  createForm.starts_at = starts;
  createForm.closes_at = closes;

  if (tmpl === 'ssc') {
    createForm.title = 'Supreme Student Council General Executive Election 2026-2027';
    createForm.description = `OFFICIAL SUPREME STUDENT COUNCIL ELECTION
Governing Body: Commission on Student Elections (COMELEC)
Official Ballot Slate:
1. President (Chief Executive Officer)
2. Vice President Internal
3. Vice President External
4. Secretary General
5. Finance Officer (Treasurer)
6. Auditor General
7. Public Relations Officer (PRO)
Rules: One vote per position. Ballots are strictly secret, anonymous, and tamper-evident.`;
  } else if (tmpl === 'club') {
    createForm.title = 'Student Club Executive Committee Elections';
    createForm.description = `RECOGNIZED STUDENT ORGANIZATION OFFICER ELECTIONS
Slate Positions:
1. President
2. Vice President
3. Secretary
4. Treasurer
5. Auditor
6. Public Relations Officer (PRO)
Eligibility: Active enrolled club members in good standing.`;
  } else if (tmpl === 'year_level') {
    createForm.title = 'Departmental & Year-Level Representative Elections';
    createForm.description = `STUDENT YEAR-LEVEL COUNCIL ELECTIONS
Positions:
1. Department Governor
2. Department Vice Governor
3. 1st Year Representative
4. 2nd Year Representative
5. 3rd Year Representative
6. 4th Year Representative`;
  } else if (tmpl === 'plebiscite') {
    createForm.title = 'Institutional Student Charter Ratification Plebiscite';
    createForm.description = `STUDENT BODY CONSTITUTIONAL PLEBISCITE
Resolution: Do you approve the proposed 2026 amendments to the Student Organization Charter & Constitution?
Ballot Choices: YES / NO / ABSTAIN.`;
  }
};

const submitElection = () => {
  createForm.post('/elections', {
    onSuccess: () => {
      showCreateModal.value = false;
      createForm.reset();
      selectedElectionTemplate.value = '';
    }
  });
};
</script>

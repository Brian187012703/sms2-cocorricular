<template>
  <AppLayout>
    <div class="space-y-6">
      <!-- Back Link & Header -->
      <div>
        <Link href="/elections" class="text-xs text-blue-400 hover:text-blue-300 font-semibold inline-flex items-center space-x-1 mb-3">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
          <span>Back to Elections</span>
        </Link>
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <div class="flex items-center space-x-2">
              <span class="text-xs font-mono font-bold px-2.5 py-0.5 rounded-md bg-blue-500/10 text-blue-400 border border-blue-500/20">
                {{ election.election_code }}
              </span>
              <span
                :class="[
                  'text-xs font-bold px-2.5 py-0.5 rounded-md border uppercase',
                  election.status === 'active' ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-slate-500/10 text-slate-400 border-slate-500/20'
                ]"
              >
                {{ election.status === 'active' ? 'Voting Open' : 'Closed' }}
              </span>
            </div>
            <h1 class="text-2xl font-extrabold text-white mt-1.5">{{ election.title }}</h1>
            <p class="text-xs text-slate-400 mt-0.5">{{ election.club ? election.club.name : 'Campus-Wide Student Body' }}</p>
          </div>

          <div v-if="userRole !== 'student'" class="flex items-center space-x-3">
            <button
              type="button"
              class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold transition"
              @click="showCandidateModal = true"
            >
              + Register Candidate
            </button>
          </div>
        </div>
      </div>

      <!-- Voter Status Alert -->
      <div v-if="hasVoted" class="p-4 rounded-2xl bg-emerald-950/40 border border-emerald-800/80 text-emerald-300 text-xs flex items-center space-x-3 shadow-lg">
        <div class="h-8 w-8 rounded-full bg-emerald-500/20 flex items-center justify-center shrink-0">
          <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        </div>
        <div>
          <div class="font-bold text-sm text-white">Ballot Successfully Recorded!</div>
          <div class="text-emerald-400/80 mt-0.5">Your ballot has been recorded separately from your participation record. You can view the live tally below.</div>
        </div>
      </div>

      <!-- Active Voting Ballot Form -->
      <div v-if="canVote" class="space-y-6">
        <div class="p-4 rounded-xl bg-blue-950/30 border border-blue-800/60 text-blue-300 text-xs flex items-center space-x-2">
          <svg class="w-4 h-4 text-blue-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          <span>Select your candidate for each office. Your choices are 100% secret and untraceable to your student identity.</span>
        </div>

        <form class="space-y-6" @submit.prevent="submitBallot">
          <div
            v-for="(candidates, position) in candidatesByPosition"
            :key="position"
            class="bg-slate-900/80 border border-slate-800 rounded-2xl p-5 space-y-4 shadow-xl"
          >
            <div class="border-b border-slate-800 pb-2">
              <h3 class="text-base font-bold text-white uppercase tracking-wider">{{ position }}</h3>
              <span class="text-[11px] text-slate-400">Vote for one (1) candidate</span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <label
                v-for="c in candidates"
                :key="c.id"
                :class="[
                  'relative p-4 rounded-xl border flex items-start space-x-3 cursor-pointer transition select-none',
                  selectedVotes[position] === c.id
                    ? 'bg-blue-600/15 border-blue-500 shadow-md ring-1 ring-blue-500'
                    : 'bg-slate-950/60 border-slate-800 hover:border-slate-700'
                ]"
              >
                <input
                  v-model="selectedVotes[position]"
                  type="radio"
                  :name="position"
                  :value="c.id"
                  class="mt-1 text-blue-600 focus:ring-blue-500 bg-slate-900 border-slate-700"
                />
                <div class="flex-1">
                  <div class="font-bold text-white text-sm">{{ c.name }}</div>
                  <div class="text-[11px] text-blue-400 font-medium mt-0.5">{{ c.party_list }}</div>
                  <p v-if="c.platform" class="text-xs text-slate-400 mt-1 italic">"{{ c.platform }}"</p>
                </div>
              </label>
            </div>
          </div>

          <div class="flex items-center justify-end pt-4">
            <button
              type="submit"
              class="px-6 py-3 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-bold text-sm shadow-xl shadow-blue-500/25 active:scale-95 transition"
            >
              Submit Official Ballot
            </button>
          </div>
        </form>
      </div>

      <!-- Live Results Tally (For voted students, admins, advisers, ssc) -->
      <div v-else class="space-y-6">
        <h2 class="text-lg font-bold text-white tracking-tight">Official Vote Tallies &amp; Results</h2>

        <div
          v-for="(candidates, position) in candidatesByPosition"
          :key="position"
          class="bg-slate-900/80 border border-slate-800 rounded-2xl p-5 space-y-4 shadow-xl"
        >
          <div class="border-b border-slate-800 pb-2">
            <h3 class="text-base font-bold text-white uppercase tracking-wider">{{ position }}</h3>
          </div>

          <div class="space-y-3">
            <div
              v-for="c in candidates"
              :key="c.id"
              class="p-4 rounded-xl bg-slate-950/70 border border-slate-800 space-y-2"
            >
              <div class="flex items-center justify-between">
                <div>
                  <div class="font-bold text-white text-sm">{{ c.name }}</div>
                  <div class="text-[11px] text-blue-400">{{ c.party_list }}</div>
                </div>
                <div class="text-right">
                  <div class="text-base font-black text-white font-mono">{{ c.vote_count }} Votes</div>
                </div>
              </div>

              <!-- Vote Bar -->
              <div class="w-full bg-slate-800 rounded-full h-2 overflow-hidden">
                <div
                  class="bg-gradient-to-r from-blue-600 to-indigo-500 h-2 rounded-full transition-all duration-500"
                  :style="{ width: getPercentage(c.vote_count, position) + '%' }"
                ></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Register Candidate Modal -->
      <div v-if="showCandidateModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl">
          <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h3 class="text-base font-bold text-white">Register Candidate</h3>
            <button class="text-slate-400 hover:text-white" @click="showCandidateModal = false">&times;</button>
          </div>

          <form class="space-y-4" @submit.prevent="submitCandidate">
            <div>
              <label class="block text-xs font-semibold text-slate-300 mb-1">Candidate Full Name</label>
              <input
                v-model="candidateForm.name"
                type="text"
                required
                placeholder="e.g. Juan Dela Cruz"
                class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
              />
            </div>

            <div>
              <label class="block text-xs font-semibold text-slate-300 mb-1">Office / Position (Template Slate)</label>
              <select
                v-model="candidateForm.position"
                required
                class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
              >
                <option value="President">President</option>
                <option value="Vice President">Vice President</option>
                <option value="Vice President Internal">Vice President Internal</option>
                <option value="Vice President External">Vice President External</option>
                <option value="Secretary General">Secretary General</option>
                <option value="Secretary">Secretary</option>
                <option value="Treasurer">Treasurer / Finance Officer</option>
                <option value="Auditor">Auditor</option>
                <option value="PRO">Public Relations Officer (PRO)</option>
                <option value="Governor">Department Governor</option>
                <option value="Vice Governor">Department Vice Governor</option>
                <option value="1st Year Representative">1st Year Representative</option>
                <option value="2nd Year Representative">2nd Year Representative</option>
                <option value="3rd Year Representative">3rd Year Representative</option>
                <option value="4th Year Representative">4th Year Representative</option>
              </select>
            </div>

            <div>
              <label class="block text-xs font-semibold text-slate-300 mb-1">Party-List / Affiliation</label>
              <input
                v-model="candidateForm.party_list"
                type="text"
                placeholder="e.g. Lead Forward Party or Independent"
                class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
              />
            </div>

            <div>
              <div class="flex items-center justify-between mb-1">
                <label class="block text-xs font-semibold text-slate-300">Platform Statement</label>
                <button
                  type="button"
                  class="text-[11px] text-cyan-400 hover:text-cyan-300 font-semibold"
                  @click="insertPlatformTemplate"
                >
                  📋 Insert Platform Template
                </button>
              </div>
              <textarea
                v-model="candidateForm.platform"
                rows="3"
                placeholder="Brief advocacy or platform statement..."
                class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none font-mono"
              ></textarea>
            </div>

            <div class="flex items-center justify-end space-x-3 pt-3 border-t border-slate-800">
              <button
                type="button"
                class="px-4 py-2 rounded-xl bg-slate-800 text-slate-300 text-xs font-semibold"
                @click="showCandidateModal = false"
              >
                Cancel
              </button>
              <button
                type="submit"
                class="px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold"
              >
                Register Candidate
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
  election: Object,
  candidatesByPosition: Object,
  hasVoted: Boolean,
  canVote: Boolean,
});

const page = usePage();
const userRole = computed(() => page.props.auth?.user?.role || 'student');

const selectedVotes = ref({});
const showCandidateModal = ref(false);

const candidateForm = useForm({
  name: '',
  position: 'President',
  party_list: '',
  platform: '',
});

const insertPlatformTemplate = () => {
  candidateForm.platform = `CANDIDATE ADVOCACY & ACTION PLAN:
1. Transparency: Open communication and regular activity progress reports.
2. Empowerment: Enhanced workshops, skill building, and active student representation.
3. Service: Inclusive co-curricular programs that serve the entire campus community.`;
};

const submitBallot = () => {
  const voteList = Object.values(selectedVotes.value);
  if (voteList.length === 0) {
    alert('Please select at least one candidate before submitting your ballot.');
    return;
  }

  if (confirm('Are you sure you want to cast your official secret ballot? This action cannot be undone.')) {
    router.post(`/elections/${props.election.id}/vote`, {
      votes: voteList,
    });
  }
};

const submitCandidate = () => {
  candidateForm.post(`/elections/${props.election.id}/candidates`, {
    onSuccess: () => {
      showCandidateModal.value = false;
      candidateForm.reset();
    }
  });
};

const getPercentage = (count, position) => {
  const candidatesInPos = props.candidatesByPosition[position] || [];
  const totalInPos = candidatesInPos.reduce((sum, c) => sum + (c.vote_count || 0), 0);
  if (totalInPos === 0) return 0;
  return Math.round((count / totalInPos) * 100);
};
</script>

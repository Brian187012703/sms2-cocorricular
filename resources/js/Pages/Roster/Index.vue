<template>
  <AppLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 class="text-2xl font-extrabold text-white tracking-tight">Organization Roster &amp; Memberships</h1>
          <p class="text-sm text-slate-400 mt-1">
            Student membership applications, faculty adviser official endorsements, and SSC institutional clearances.
          </p>
        </div>

        <div v-if="userRole === 'student'" class="flex items-center space-x-3">
          <button
            type="button"
            class="inline-flex items-center space-x-2 px-4 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-semibold text-sm shadow-lg shadow-blue-500/25 transition active:scale-95"
            @click="showApplyModal = true"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
            <span>Apply to Club</span>
          </button>
        </div>
      </div>

      <!-- Metrics -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="p-4 rounded-2xl bg-slate-900/70 border border-slate-800">
          <div class="text-xs font-semibold text-emerald-400 uppercase tracking-wider">Active Verified Members</div>
          <div class="mt-2 text-2xl font-black text-emerald-400">{{ metrics?.total_members || 0 }}</div>
        </div>
        <div class="p-4 rounded-2xl bg-slate-900/70 border border-slate-800">
          <div class="text-xs font-semibold text-amber-400 uppercase tracking-wider">Awaiting Adviser Endorsement</div>
          <div class="mt-2 text-2xl font-black text-amber-400">{{ metrics?.pending_adviser || 0 }}</div>
        </div>
        <div class="p-4 rounded-2xl bg-slate-900/70 border border-slate-800">
          <div class="text-xs font-semibold text-purple-400 uppercase tracking-wider">Awaiting SSC Review</div>
          <div class="mt-2 text-2xl font-black text-purple-400">{{ metrics?.pending_ssc || 0 }}</div>
        </div>
      </div>

      <!-- Table Container -->
      <div class="bg-slate-900/80 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs text-slate-300">
            <thead class="bg-slate-950/60 text-slate-400 uppercase tracking-wider text-[10px] border-b border-slate-800">
              <tr>
                <th class="px-5 py-3.5">Student Member</th>
                <th class="px-5 py-3.5">Organization</th>
                <th class="px-5 py-3.5">Role</th>
                <th class="px-5 py-3.5">Documents &amp; Endorsement</th>
                <th class="px-5 py-3.5">Stage 1: Adviser</th>
                <th class="px-5 py-3.5">Stage 2: SSC</th>
                <th class="px-5 py-3.5">Status</th>
                <th class="px-5 py-3.5 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/60">
              <tr v-for="m in memberships.data" :key="m.id" class="hover:bg-slate-800/40 transition">
                <td class="px-5 py-4">
                  <div class="font-bold text-white text-sm">
                    {{ m.user ? `${m.user.first_name} ${m.user.last_name}` : 'Unknown' }}
                  </div>
                  <div class="text-slate-400 text-[11px] mt-0.5 font-mono">
                    {{ m.user?.student ? `${m.user.student.course} - ${m.user.student.student_number}` : m.user?.username }}
                  </div>
                </td>
                <td class="px-5 py-4">
                  <div class="font-semibold text-white">{{ m.club ? m.club.name : 'Club #' + m.club_id }}</div>
                  <div class="text-slate-500 text-[10px] font-mono">{{ m.club?.code }}</div>
                </td>
                <td class="px-5 py-4 font-medium text-slate-200">
                  {{ m.role }}
                </td>
                <td class="px-5 py-4">
                  <div class="flex flex-col space-y-1">
                    <button
                      v-if="m.letter_intent"
                      type="button"
                      class="text-[11px] text-blue-400 hover:text-blue-300 font-semibold inline-flex items-center space-x-1"
                      @click="viewDocModal('Letter of Intent', m.letter_intent)"
                    >
                      <span>📄 View Intent</span>
                    </button>
                    <button
                      v-if="m.letter_endorsement"
                      type="button"
                      class="text-[11px] text-emerald-400 hover:text-emerald-300 font-semibold inline-flex items-center space-x-1"
                      @click="viewDocModal('Faculty Adviser Endorsement Letter', m.letter_endorsement)"
                    >
                      <span>📜 Adviser Endorsement</span>
                    </button>
                    <span v-else class="text-[10px] text-slate-500 italic">
                      Awaiting Adviser Letter
                    </span>
                  </div>
                </td>
                <td class="px-5 py-4">
                  <span
                    :class="[
                      'text-[10px] font-bold px-2 py-0.5 rounded-md border',
                      m.adviser_review === 'Endorsed' ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-amber-500/10 text-amber-400 border-amber-500/20'
                    ]"
                  >
                    {{ m.adviser_review }}
                  </span>
                </td>
                <td class="px-5 py-4">
                  <span
                    :class="[
                      'text-[10px] font-bold px-2 py-0.5 rounded-md border',
                      m.ssc_review === 'Approved' ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-purple-500/10 text-purple-400 border-purple-500/20'
                    ]"
                  >
                    {{ m.ssc_review }}
                  </span>
                </td>
                <td class="px-5 py-4">
                  <span
                    :class="[
                      'text-[10px] font-bold px-2 py-0.5 rounded-md border uppercase',
                      m.status === 'Active' ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : (m.status === 'Pending' ? 'bg-amber-500/10 text-amber-400 border-amber-500/20' : 'bg-red-500/10 text-red-400 border-red-500/20')
                    ]"
                  >
                    {{ m.status }}
                  </span>
                </td>
                <td class="px-5 py-4 text-right">
                  <div class="flex items-center justify-end space-x-2">
                    <!-- Adviser Endorsement Action -->
                    <button
                      v-if="userRole === 'club_adviser' && m.status === 'Pending' && m.adviser_review === 'Pending Adviser'"
                      type="button"
                      class="px-2.5 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-xs transition active:scale-95 flex items-center space-x-1"
                      @click="openEndorseModal(m)"
                    >
                      <span>Endorse Application</span>
                    </button>

                    <!-- SSC Clearance Action -->
                    <button
                      v-if="userRole === 'ssc' && m.adviser_review === 'Endorsed' && m.ssc_review === 'Pending SSC' && m.status === 'Pending'"
                      type="button"
                      class="px-2.5 py-1.5 rounded-lg bg-purple-600 hover:bg-purple-500 text-white font-semibold text-xs transition active:scale-95"
                      @click="approveSsc(m.id)"
                    >
                      Forward to Admin
                    </button>

                    <!-- Admin Final Clearance Action -->
                    <button
                      v-if="userRole === 'admin' && m.ssc_review === 'Approved' && m.status === 'Pending'"
                      type="button"
                      class="px-2.5 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold"
                      @click="router.post('/roster/' + m.id + '/approve-admin')"
                    >
                      Clear &amp; Activate
                    </button>

                    <!-- Reject Action -->
                    <button
                      v-if="userRole !== 'student' && m.status === 'Pending'"
                      type="button"
                      class="px-2.5 py-1.5 rounded-lg bg-red-600/20 text-red-400 hover:bg-red-600/30 text-xs transition"
                      @click="rejectMembership(m.id)"
                    >
                      Reject
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="!memberships.data || memberships.data.length === 0">
                <td colspan="8" class="px-5 py-12 text-center text-slate-500">
                  No roster or membership records found matching your role.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Student Apply Modal (Zero Endorsement Letter required from student) -->
      <div v-if="showApplyModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-lg w-full p-6 space-y-4 shadow-2xl">
          <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <div>
              <h3 class="text-base font-bold text-white">Apply for Organization Membership</h3>
              <p class="text-[11px] text-slate-400 mt-0.5">Faculty Adviser will provide the official endorsement letter upon reviewing.</p>
            </div>
            <button class="text-slate-400 hover:text-white" @click="showApplyModal = false">&times;</button>
          </div>

          <form class="space-y-4" @submit.prevent="submitApplication">
            <div>
              <label class="block text-xs font-semibold text-slate-300 mb-1">Target Organization</label>
              <select
                v-model="applyForm.club_id"
                required
                class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
              >
                <option value="" disabled>Select club to join</option>
                <option v-for="c in availableClubs" :key="c.id" :value="c.id">
                  {{ c.name }} ({{ c.code }} - {{ c.category }})
                </option>
              </select>
            </div>

            <div>
              <div class="flex items-center justify-between mb-1">
                <label class="block text-xs font-semibold text-slate-300">Statement of Intent &amp; Advocacy</label>
                <button
                  type="button"
                  class="text-[11px] text-blue-400 hover:text-blue-300 font-semibold"
                  @click="insertIntentTemplate"
                >
                  📄 Insert Intent Template
                </button>
              </div>
              <textarea
                v-model="applyForm.letter_intent"
                rows="5"
                required
                placeholder="State your reasons and advocacy for joining this student organization..."
                class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none font-mono"
              ></textarea>
            </div>

            <div class="flex items-center justify-end space-x-3 pt-3 border-t border-slate-800">
              <button
                type="button"
                class="px-4 py-2 rounded-xl bg-slate-800 text-slate-300 text-xs font-semibold"
                @click="showApplyModal = false"
              >
                Cancel
              </button>
              <button
                type="submit"
                :disabled="applyForm.processing"
                class="px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold shadow-lg shadow-blue-500/25 disabled:opacity-50"
              >
                {{ applyForm.processing ? 'Submitting...' : 'Submit Application' }}
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- Adviser Endorsement Modal (Adviser provides letter for SSC approval) -->
      <div v-if="showEndorseModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-sm">
        <div class="bg-slate-900 border border-slate-700 rounded-2xl max-w-2xl w-full p-6 space-y-4 shadow-2xl">
          <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <div>
              <h3 class="text-base font-bold text-white">Provide Official Faculty Endorsement</h3>
              <p class="text-xs text-slate-400 mt-0.5">
                Applicant: <strong class="text-white">{{ activeApplicant?.user?.first_name }} {{ activeApplicant?.user?.last_name }}</strong> &bull; {{ activeApplicant?.club?.name }}
              </p>
            </div>
            <button class="text-slate-400 hover:text-white" @click="showEndorseModal = false">&times;</button>
          </div>

          <form class="space-y-4" @submit.prevent="submitAdviserEndorsement">
            <div>
              <div class="flex items-center justify-between mb-1">
                <label class="block text-xs font-semibold text-slate-300">Official Letter of Endorsement</label>
                <button
                  type="button"
                  class="text-[11px] text-emerald-400 hover:text-emerald-300 font-semibold"
                  @click="insertEndorsementTemplate"
                >
                  📜 Insert Endorsement Template
                </button>
              </div>
              <textarea
                v-model="endorseForm.letter_endorsement"
                rows="8"
                required
                placeholder="Enter or paste the official Faculty Endorsement text here..."
                class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-emerald-500 focus:outline-none font-mono leading-relaxed"
              ></textarea>
            </div>

            <div>
              <label class="block text-xs font-semibold text-slate-300 mb-1">Review Notes / Remarks for SSC</label>
              <input
                v-model="endorseForm.notes"
                type="text"
                placeholder="e.g. In good academic standing. Recommended for approval."
                class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-emerald-500 focus:outline-none"
              />
            </div>

            <div class="p-3 rounded-xl bg-emerald-950/40 border border-emerald-800/60 text-[11px] text-emerald-300">
              ✔️ This endorsement letter will be archived and provided to the <strong>Supreme Student Council (SSC)</strong> for Stage 2 Institutional Clearance.
            </div>

            <div class="flex items-center justify-end space-x-3 pt-3 border-t border-slate-800">
              <button
                type="button"
                class="px-4 py-2 rounded-xl bg-slate-800 text-slate-300 text-xs font-semibold"
                @click="showEndorseModal = false"
              >
                Cancel
              </button>
              <button
                type="submit"
                :disabled="endorseForm.processing"
                class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold shadow-lg shadow-emerald-500/25 disabled:opacity-50"
              >
                {{ endorseForm.processing ? 'Endorsing...' : 'Endorse to SSC' }}
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- View Document Modal (Read Intent or Adviser Endorsement) -->
      <div v-if="showDocModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-sm">
        <div class="bg-slate-900 border border-slate-700 rounded-2xl max-w-xl w-full p-6 space-y-4 shadow-2xl">
          <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h3 class="text-sm font-bold text-white">{{ docTitle }}</h3>
            <button class="text-slate-400 hover:text-white" @click="showDocModal = false">&times;</button>
          </div>
          <div class="bg-slate-950 border border-slate-800 rounded-xl p-4 font-mono text-xs text-slate-300 whitespace-pre-wrap max-h-96 overflow-y-auto leading-relaxed select-all">
            {{ docBody }}
          </div>
          <div class="flex justify-end pt-2 border-t border-slate-800">
            <button
              type="button"
              class="px-4 py-2 rounded-xl bg-slate-800 text-slate-200 text-xs font-semibold hover:bg-slate-700"
              @click="showDocModal = false"
            >
              Close
            </button>
          </div>
        </div>
      </div>
    </div>
    <Pagination :page="memberships" />
  </AppLayout>
</template>

<script setup>
import Pagination from '@/Components/Pagination.vue';
import { ref, computed } from 'vue';
import { usePage, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
  memberships: Object,
  availableClubs: Array,
  filters: Object,
  metrics: Object,
});

const page = usePage();
const userRole = computed(() => page.props.auth?.user?.role || 'student');

const showApplyModal = ref(false);
const showEndorseModal = ref(false);
const activeApplicant = ref(null);

const showDocModal = ref(false);
const docTitle = ref('');
const docBody = ref('');

const applyForm = useForm({
  club_id: '',
  letter_intent: '',
});

const endorseForm = useForm({
  letter_endorsement: '',
  notes: '',
});

const insertIntentTemplate = () => {
  applyForm.letter_intent = `STATEMENT OF INTENT & ADVOCACY
Bestlink College of the Philippines

I am formally applying to join this organization for Academic Year 2026-2027.
1. Motivation: I seek to contribute actively to campus leadership, academic excellence, and co-curricular projects.
2. Participation: I commit to regular attendance in meetings and scheduled events.
3. Conduct: I agree to uphold all student organization guidelines and institutional policies.`;
};

const insertEndorsementTemplate = () => {
  const applicantName = activeApplicant.value?.user ? `${activeApplicant.value.user.first_name} ${activeApplicant.value.user.last_name}` : '[Student Name]';
  const clubName = activeApplicant.value?.club?.name || '[Organization Name]';
  const today = new Date().toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });

  endorseForm.letter_endorsement = `MEMORANDUM OF FACULTY ENDORSEMENT
OFFICE OF THE FACULTY ADVISER
BESTLINK COLLEGE OF THE PHILIPPINES

Date: ${today}
To: Supreme Student Council (SSC) Officers & Office of Student Affairs
Subject: Official Faculty Endorsement for Club Membership

This is to officially certify that applicant ${applicantName} has been vetted and interviewed by the faculty adviser for active participation in ${clubName}.

The applicant demonstrates good academic and moral standing, fulfills all organizational requirements, and is hereby officially ENDORSED for Stage 2 SSC review and institutional clearance.

Endorsed by:
Faculty Adviser, ${clubName}
Bestlink College of the Philippines`;
};

const submitApplication = () => {
  applyForm.post('/roster/apply', {
    onSuccess: () => {
      showApplyModal.value = false;
      applyForm.reset();
    }
  });
};

const openEndorseModal = (membership) => {
  activeApplicant.value = membership;
  endorseForm.reset();
  insertEndorsementTemplate();
  showEndorseModal.value = true;
};

const submitAdviserEndorsement = () => {
  if (!activeApplicant.value) return;
  endorseForm.post(`/roster/${activeApplicant.value.id}/endorse-adviser`, {
    onSuccess: () => {
      showEndorseModal.value = false;
      endorseForm.reset();
      activeApplicant.value = null;
    }
  });
};

const approveSsc = (id) => {
  if (confirm('Verify the Faculty Adviser Endorsement Letter and forward this student membership to Admin clearance?')) {
    router.post(`/roster/${id}/approve-ssc`);
  }
};

const rejectMembership = (id) => {
  const reason = prompt('Please enter the reason for rejection:');
  if (reason) {
    router.post(`/roster/${id}/reject`, { reason });
  }
};

const viewDocModal = (title, body) => {
  docTitle.value = title;
  docBody.value = body;
  showDocModal.value = true;
};
</script>

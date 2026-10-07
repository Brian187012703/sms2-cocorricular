<template>
  <AppLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 class="text-2xl font-extrabold text-white tracking-tight">Budget &amp; Finance Requisitions</h1>
          <p class="text-sm text-slate-400 mt-1">
            Institutional finance portal: submit line-item funding requests, SSC reviews, and administrative disbursements.
          </p>
        </div>

        <div v-if="userRole === 'club_adviser' || userRole === 'admin'" class="flex items-center space-x-3">
          <button
            type="button"
            class="inline-flex items-center space-x-2 px-4 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-semibold text-sm shadow-lg shadow-blue-500/25 transition active:scale-95"
            @click="showCreateModal = true"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            <span>New Requisition</span>
          </button>
        </div>
      </div>

      <!-- Metrics -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="p-4 rounded-2xl bg-slate-900/70 border border-slate-800">
          <div class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Total Requested</div>
          <div class="mt-2 text-xl font-black text-white">PHP {{ formatMoney(metrics?.total_requested) }}</div>
        </div>
        <div class="p-4 rounded-2xl bg-slate-900/70 border border-slate-800">
          <div class="text-xs font-semibold text-emerald-400 uppercase tracking-wider">Total Disbursed</div>
          <div class="mt-2 text-xl font-black text-emerald-400">PHP {{ formatMoney(metrics?.total_disbursed) }}</div>
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

      <!-- Table Container -->
      <div class="bg-slate-900/80 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-sm text-slate-300">
            <thead class="bg-slate-950/60 text-slate-400 uppercase tracking-wider text-xs border-b border-slate-800">
              <tr>
                <th class="px-5 py-3.5">Organization &amp; Title</th>
                <th class="px-5 py-3.5">Requested Amount</th>
                <th class="px-5 py-3.5">SSC Vetted</th>
                <th class="px-5 py-3.5">Approved / Disbursed</th>
                <th class="px-5 py-3.5">Workflow Status</th>
                <th class="px-5 py-3.5 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/60">
              <tr v-for="b in budgets.data" :key="b.id" class="hover:bg-slate-800/40 transition">
                <td class="px-5 py-4">
                  <div class="font-bold text-white text-sm">{{ b.title }}</div>
                  <div class="text-slate-400 text-xs mt-0.5">{{ b.club ? b.club.name : 'Institutional' }}</div>
                  <div v-if="b.line_items" class="text-slate-500 text-xs mt-1 font-mono truncate max-w-xs">{{ b.line_items }}</div>
                </td>
                <td class="px-5 py-4 font-mono font-semibold text-white">
                  PHP {{ formatMoney(b.amount) }}
                </td>
                <td class="px-5 py-4 font-mono text-amber-300">
                  {{ b.recommended_amount ? 'PHP ' + formatMoney(b.recommended_amount) : '—' }}
                </td>
                <td class="px-5 py-4 font-mono text-emerald-400">
                  <div v-if="b.disbursed_at">
                    <div>PHP {{ formatMoney(b.final_approved_amount || b.amount) }}</div>
                    <div class="text-xs text-slate-400 font-sans">Ref: {{ b.disbursement_reference }}</div>
                  </div>
                  <div v-else-if="b.final_approved_amount">
                    PHP {{ formatMoney(b.final_approved_amount) }}
                  </div>
                  <div v-else class="text-slate-500">—</div>
                </td>
                <td class="px-5 py-4">
                  <span :class="statusBadge(b.status)">{{ b.status }}</span>
                </td>
                <td class="px-5 py-4 text-right">
                  <div class="flex items-center justify-end space-x-2">
                    <!-- SSC Review Action -->
<button v-if="userRole === 'club_adviser' && b.status === 'Pending Adviser'" @click="router.post('/budgets/' + b.id + '/endorse-adviser')" class="px-3 py-1.5 rounded-lg bg-emerald-600 text-xs">Endorse</button>
                    <button
                      v-if="userRole === 'ssc' && b.status === 'Pending SSC'"
                      type="button"
                      class="px-3 py-1.5 rounded-lg bg-amber-600 hover:bg-amber-500 text-white font-semibold text-xs transition"
                      @click="openSscReview(b)"
                    >
                      Vet Amount
                    </button>

                    <!-- Admin Clearance Action -->
                    <button
                      v-if="userRole === 'admin' && b.status === 'Pending Admin'"
                      type="button"
                      class="px-3 py-1.5 rounded-lg bg-purple-600 hover:bg-purple-500 text-white font-semibold text-xs transition"
                      @click="openAdminApprove(b)"
                    >
                      Grant Clearance
                    </button>

                    <!-- Admin Disburse Action -->
                    <button
                      v-if="userRole === 'admin' && b.status === 'Approved' && !b.disbursed_at"
                      type="button"
                      class="px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-xs transition"
                      @click="openDisburse(b)"
                    >
                      Disburse
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="!budgets.data || budgets.data.length === 0">
                <td colspan="6" class="px-5 py-12 text-center text-slate-500">
                  No budget requisitions found in the database.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Create Modal -->
      <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-lg w-full p-6 space-y-4 shadow-2xl">
          <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h3 class="text-lg font-bold text-white">Submit Budget Requisition</h3>
            <button class="text-slate-400 hover:text-white" @click="showCreateModal = false">&times;</button>
          </div>

          <form class="space-y-4" @submit.prevent="submitRequisition">
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
              <label class="block text-xs font-semibold text-slate-300 mb-1">Requisition Title</label>
              <input
                v-model="createForm.title"
                type="text"
                required
                placeholder="e.g. Stage Setup & Sound System for Tech Week"
                class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
              />
            </div>

            <div>
              <label class="block text-xs font-semibold text-slate-300 mb-1">Total Requested Amount (PHP)</label>
              <input
                v-model="createForm.amount"
                type="number"
                step="0.01"
                min="1"
                required
                placeholder="15000.00"
                class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none font-mono"
              />
            </div>

            <div>
              <label class="block text-xs font-semibold text-slate-300 mb-1">Itemized Line Items (Breakdown)</label>
              <textarea
                v-model="createForm.line_items"
                rows="3"
                placeholder="Item 1: Sound system rental - PHP 5,000&#10;Item 2: Certificates & printouts - PHP 2,000&#10;Item 3: Speaker honorarium - PHP 8,000"
                class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none font-mono"
              ></textarea>
            </div>

            <div>
              <label class="block text-xs font-semibold text-slate-300 mb-1">Justification &amp; Purpose</label>
              <textarea
                v-model="createForm.description"
                rows="2"
                placeholder="Institutional objectives and justification..."
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
                Submit for SSC Review
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- SSC Review Modal -->
      <div v-if="activeSscItem" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl">
          <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h3 class="text-base font-bold text-white">SSC Financial Review</h3>
            <button class="text-slate-400 hover:text-white" @click="activeSscItem = null">&times;</button>
          </div>

          <div class="text-xs text-slate-400">
            Original Requested: <span class="font-bold text-white font-mono">PHP {{ formatMoney(activeSscItem.amount) }}</span>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-300 mb-1">Recommended Vetted Amount (PHP)</label>
            <input
              v-model="sscAmount"
              type="number"
              step="0.01"
              required
              class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs font-mono"
            />
          </div>

          <div class="flex items-center justify-end space-x-3 pt-3 border-t border-slate-800">
            <button class="px-3 py-2 rounded-xl bg-slate-800 text-slate-300 text-xs font-semibold" @click="activeSscItem = null">Cancel</button>
            <button class="px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-xs font-semibold" @click="submitSscReview">Endorse &amp; Forward</button>
          </div>
        </div>
      </div>

      <!-- Admin Approve Modal -->
      <div v-if="activeAdminItem" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl">
          <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h3 class="text-base font-bold text-white">Administrative Final Approval</h3>
            <button class="text-slate-400 hover:text-white" @click="activeAdminItem = null">&times;</button>
          </div>

          <div class="text-xs text-slate-400">
            SSC Recommended: <span class="font-bold text-white font-mono">PHP {{ formatMoney(activeAdminItem.recommended_amount || activeAdminItem.amount) }}</span>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-300 mb-1">Final Approved Amount (PHP)</label>
            <input
              v-model="adminAmount"
              type="number"
              step="0.01"
              required
              class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs font-mono"
            />
          </div>

          <div class="flex items-center justify-end space-x-3 pt-3 border-t border-slate-800">
            <button class="px-3 py-2 rounded-xl bg-slate-800 text-slate-300 text-xs font-semibold" @click="activeAdminItem = null">Cancel</button>
            <button class="px-4 py-2 rounded-xl bg-purple-600 hover:bg-purple-500 text-white text-xs font-semibold" @click="submitAdminApproval">Approve Budget</button>
          </div>
        </div>
      </div>

      <!-- Admin Disburse Modal -->
      <div v-if="activeDisburseItem" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl">
          <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h3 class="text-base font-bold text-white">Release Disbursement</h3>
            <button class="text-slate-400 hover:text-white" @click="activeDisburseItem = null">&times;</button>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-300 mb-1">Check / Bank Voucher Reference #</label>
            <input
              v-model="disbursementRef"
              type="text"
              required
              placeholder="e.g. CHK-2026-09418 or BDO-TRX-8821"
              class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs font-mono"
            />
          </div>

          <div class="flex items-center justify-end space-x-3 pt-3 border-t border-slate-800">
            <button class="px-3 py-2 rounded-xl bg-slate-800 text-slate-300 text-xs font-semibold" @click="activeDisburseItem = null">Cancel</button>
            <button class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold" @click="submitDisbursement">Confirm Disbursement</button>
          </div>
        </div>
      </div>
    </div>
    <Pagination :page="budgets" />
  </AppLayout>
</template>

<script setup>
import Pagination from '@/Components/Pagination.vue';
import { ref, computed } from 'vue';
import { usePage, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
  budgets: Object,
  clubs: Array,
  filters: Object,
  metrics: Object,
});

const page = usePage();
const userRole = computed(() => page.props.auth?.user?.role || 'student');

const showCreateModal = ref(false);
const activeSscItem = ref(null);
const sscAmount = ref(0);
const activeAdminItem = ref(null);
const adminAmount = ref(0);
const activeDisburseItem = ref(null);
const disbursementRef = ref('');

const createForm = useForm({
  club_id: '',
  title: '',
  amount: '',
  line_items: '',
  description: '',
});

const submitRequisition = () => {
  createForm.post('/budgets', {
    onSuccess: () => {
      showCreateModal.value = false;
      createForm.reset();
    }
  });
};

const openSscReview = (item) => {
  activeSscItem.value = item;
  sscAmount.value = item.amount;
};

const submitSscReview = () => {
  router.post(`/budgets/${activeSscItem.value.id}/endorse-ssc`, {
    recommended_amount: sscAmount.value,
  }, {
    onSuccess: () => { activeSscItem.value = null; }
  });
};

const openAdminApprove = (item) => {
  activeAdminItem.value = item;
  adminAmount.value = item.recommended_amount || item.amount;
};

const submitAdminApproval = () => {
  router.post(`/budgets/${activeAdminItem.value.id}/approve-admin`, {
    final_approved_amount: adminAmount.value,
  }, {
    onSuccess: () => { activeAdminItem.value = null; }
  });
};

const openDisburse = (item) => {
  activeDisburseItem.value = item;
  disbursementRef.value = 'CHK-' + Math.floor(100000 + Math.random() * 900000);
};

const submitDisbursement = () => {
  router.post(`/budgets/${activeDisburseItem.value.id}/disburse`, {
    disbursement_reference: disbursementRef.value,
  }, {
    onSuccess: () => { activeDisburseItem.value = null; }
  });
};

const formatMoney = (val) => {
  if (!val) return '0.00';
  return Number(val).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

const statusBadge = (status) => {
  switch (status) {
    case 'Disbursed':
      return 'text-xs font-semibold px-2.5 py-1 rounded-md bg-emerald-500/10 text-emerald-400 border border-emerald-500/20';
    case 'Approved':
      return 'text-xs font-semibold px-2.5 py-1 rounded-md bg-cyan-500/10 text-cyan-400 border border-cyan-500/20';
    case 'Pending SSC':
      return 'text-xs font-semibold px-2.5 py-1 rounded-md bg-amber-500/10 text-amber-400 border border-amber-500/20';
    case 'Pending Admin':
      return 'text-xs font-semibold px-2.5 py-1 rounded-md bg-purple-500/10 text-purple-400 border border-purple-500/20';
    case 'Rejected':
      return 'text-xs font-semibold px-2.5 py-1 rounded-md bg-red-500/10 text-red-400 border border-red-500/20';
    default:
      return 'text-xs font-semibold px-2.5 py-1 rounded-md bg-slate-500/10 text-slate-400 border border-slate-500/20';
  }
};
</script>

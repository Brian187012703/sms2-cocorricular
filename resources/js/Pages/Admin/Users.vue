<template>
  <AppLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 class="text-2xl font-extrabold text-white tracking-tight">Central User Administration</h1>
          <p class="text-sm text-slate-400 mt-1">
            Manage system roles, account lifecycles, and security credentials across all institutional profiles.
          </p>
        </div>

        <div class="flex items-center space-x-3">
          <button
            type="button"
            class="inline-flex items-center space-x-2 px-4 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-semibold text-sm shadow-lg shadow-blue-500/25 transition active:scale-95"
            @click="showCreateModal = true"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
            <span>Provision User</span>
          </button>
        </div>
      </div>

      <!-- Metrics -->
      <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
        <div class="p-4 rounded-2xl bg-slate-900/70 border border-slate-800">
          <div class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Total Accounts</div>
          <div class="mt-1 text-2xl font-black text-white">{{ metrics?.total_users || 0 }}</div>
        </div>
        <div class="p-4 rounded-2xl bg-slate-900/70 border border-slate-800">
          <div class="text-[11px] font-semibold text-blue-400 uppercase tracking-wider">Students</div>
          <div class="mt-1 text-2xl font-black text-blue-400">{{ metrics?.students || 0 }}</div>
        </div>
        <div class="p-4 rounded-2xl bg-slate-900/70 border border-slate-800">
          <div class="text-[11px] font-semibold text-purple-400 uppercase tracking-wider">Faculty Advisers</div>
          <div class="mt-1 text-2xl font-black text-purple-400">{{ metrics?.advisers || 0 }}</div>
        </div>
        <div class="p-4 rounded-2xl bg-slate-900/70 border border-slate-800">
          <div class="text-[11px] font-semibold text-amber-400 uppercase tracking-wider">SSC Officers</div>
          <div class="mt-1 text-2xl font-black text-amber-400">{{ metrics?.ssc || 0 }}</div>
        </div>
        <div class="p-4 rounded-2xl bg-slate-900/70 border border-slate-800">
          <div class="text-[11px] font-semibold text-emerald-400 uppercase tracking-wider">Administrators</div>
          <div class="mt-1 text-2xl font-black text-emerald-400">{{ metrics?.admins || 0 }}</div>
        </div>
      </div>

      <!-- Filters -->
      <div class="p-4 rounded-2xl bg-slate-900/60 border border-slate-800 flex flex-col sm:flex-row gap-3 items-center justify-between">
        <div class="w-full sm:w-72 relative">
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Search username, name, or email..."
            class="w-full pl-9 pr-4 py-2 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
            @keyup.enter="applyFilters"
          />
          <svg class="w-4 h-4 text-slate-500 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        </div>

        <div class="flex items-center space-x-2 w-full sm:w-auto">
          <select
            v-model="roleFilter"
            class="w-full sm:w-auto px-3 py-2 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
            @change="applyFilters"
          >
            <option value="">All Roles</option>
            <option value="student">Student</option>
            <option value="club_adviser">Club Adviser</option>
            <option value="ssc">SSC Officer</option>
            <option value="admin">Administrator</option>
          </select>

          <select
            v-model="statusFilter"
            class="w-full sm:w-auto px-3 py-2 bg-slate-950/80 border border-slate-700/80 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
            @change="applyFilters"
          >
            <option value="">All Statuses</option>
            <option value="Active">Active</option>
            <option value="Inactive">Inactive</option>
          </select>
        </div>
      </div>

      <!-- Users Table -->
      <div class="bg-slate-900/80 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs text-slate-300">
            <thead class="bg-slate-950/60 text-slate-400 uppercase tracking-wider text-[10px] border-b border-slate-800">
              <tr>
                <th class="px-5 py-3.5">User Identity</th>
                <th class="px-5 py-3.5">Email</th>
                <th class="px-5 py-3.5">System Role</th>
                <th class="px-5 py-3.5">Account Status</th>
                <th class="px-5 py-3.5">Last Login</th>
                <th class="px-5 py-3.5 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/60">
              <tr v-for="u in users.data" :key="u.id" class="hover:bg-slate-800/40 transition">
                <td class="px-5 py-4">
                  <div class="font-bold text-white text-sm">{{ u.first_name }} {{ u.last_name }}</div>
                  <div class="text-slate-400 text-[11px] font-mono mt-0.5">@{{ u.username }}</div>
                </td>
                <td class="px-5 py-4 font-mono text-slate-300">{{ u.email }}</td>
                <td class="px-5 py-4">
                  <span :class="roleBadge(u.role)">{{ formatRole(u.role) }}</span>
                </td>
                <td class="px-5 py-4">
                  <span
                    :class="[
                      'text-[10px] font-bold px-2 py-0.5 rounded-md border uppercase',
                      u.status === 'Active' ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-red-500/10 text-red-400 border-red-500/20'
                    ]"
                  >
                    {{ u.status }}
                  </span>
                </td>
                <td class="px-5 py-4 font-mono text-slate-400 text-[11px]">
                  {{ u.last_login ? formatDate(u.last_login) : 'Never' }}
                </td>
                <td class="px-5 py-4 text-right">
                  <button
                    type="button"
                    :class="[
                      'px-3 py-1.5 rounded-lg text-xs font-semibold transition',
                      u.status === 'Active'
                        ? 'bg-red-600/20 text-red-400 hover:bg-red-600/30'
                        : 'bg-emerald-600/20 text-emerald-400 hover:bg-emerald-600/30'
                    ]"
                    @click="toggleStatus(u)"
                  >
                    {{ u.status === 'Active' ? 'Deactivate' : 'Activate' }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Provision User Modal -->
      <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-lg w-full p-6 space-y-4 shadow-2xl">
          <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h3 class="text-base font-bold text-white">Provision User Account</h3>
            <button class="text-slate-400 hover:text-white" @click="showCreateModal = false">&times;</button>
          </div>

          <form class="space-y-4" @submit.prevent="submitUser">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">First Name</label>
                <input
                  v-model="createForm.first_name"
                  type="text"
                  required
                  class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
                />
              </div>
              <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Last Name</label>
                <input
                  v-model="createForm.last_name"
                  type="text"
                  required
                  class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
                />
              </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Username</label>
                <input
                  v-model="createForm.username"
                  type="text"
                  required
                  placeholder="e.g. j.santos"
                  class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none font-mono"
                />
              </div>
              <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Email Address</label>
                <input
                  v-model="createForm.email"
                  type="email"
                  required
                  placeholder="name@bcp.edu.ph"
                  class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none font-mono"
                />
              </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">System Role</label>
                <select
                  v-model="createForm.role"
                  required
                  class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
                >
                  <option value="student">Student</option>
                  <option value="club_adviser">Club Adviser</option>
                  <option value="ssc">SSC Officer</option>
                  <option value="admin">Administrator</option>
                </select>
              </div>
              <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Initial Password</label>
                <input
                  v-model="createForm.password"
                  type="password"
                  required
                  minlength="6"
                  class="w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-xl text-white text-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
                />
              </div>
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
                Create Account
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <Pagination :page="users" />
  </AppLayout>
</template>

<script setup>
import Pagination from '@/Components/Pagination.vue';
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
  users: Object,
  filters: Object,
  metrics: Object,
});

const searchQuery = ref(props.filters?.search || '');
const roleFilter = ref(props.filters?.role || '');
const statusFilter = ref(props.filters?.status || '');
const showCreateModal = ref(false);

const createForm = useForm({
  first_name: '',
  last_name: '',
  username: '',
  email: '',
  role: 'student',
  password: 'Password@2026!',
});

const applyFilters = () => {
  router.get('/admin/users', {
    search: searchQuery.value,
    role: roleFilter.value,
    status: statusFilter.value,
  }, { preserveState: true, replace: true });
};

const toggleStatus = (user) => {
  if (confirm(`Change status for user ${user.username}?`)) {
    router.post(`/admin/users/${user.id}/toggle-status`);
  }
};

const submitUser = () => {
  createForm.post('/admin/users', {
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
  });
};

const formatRole = (role) => {
  switch (role) {
    case 'student': return 'Student';
    case 'club_adviser': return 'Club Adviser';
    case 'ssc': return 'SSC Officer';
    case 'admin': return 'Administrator';
    default: return role;
  }
};

const roleBadge = (role) => {
  switch (role) {
    case 'admin':
      return 'text-[10px] font-bold px-2 py-0.5 rounded-md bg-emerald-500/10 text-emerald-400 border border-emerald-500/20';
    case 'ssc':
      return 'text-[10px] font-bold px-2 py-0.5 rounded-md bg-amber-500/10 text-amber-400 border border-amber-500/20';
    case 'club_adviser':
      return 'text-[10px] font-bold px-2 py-0.5 rounded-md bg-purple-500/10 text-purple-400 border border-purple-500/20';
    default:
      return 'text-[10px] font-bold px-2 py-0.5 rounded-md bg-blue-500/10 text-blue-400 border border-blue-500/20';
  }
};
</script>

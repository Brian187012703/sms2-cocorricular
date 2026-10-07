<template>
  <AppLayout>
    <div class="space-y-6 max-w-4xl mx-auto">
      <!-- Header -->
      <div>
        <Link href="/attendance" class="text-xs text-blue-400 hover:text-blue-300 font-semibold inline-flex items-center space-x-1 mb-3">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
          <span>Back to Attendance Logs</span>
        </Link>
        <h1 class="text-2xl font-extrabold text-white tracking-tight">Attendance Check-In Terminal</h1>
        <p class="text-sm text-slate-400 mt-1">
          Scan attendee QR code or enter student ID. Validates event exclusivity rules and rejects duplicates.
        </p>
      </div>

      <!-- Scanner Console Card -->
      <div class="bg-slate-900/80 border border-slate-800 rounded-3xl p-6 sm:p-8 space-y-6 shadow-2xl">
        <!-- Event Selection -->
        <div>
          <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">
            1. Select Active Campus Event
          </label>
          <select
            v-model="selectedEventId"
            class="w-full px-4 py-3 bg-slate-950 border border-slate-700 rounded-2xl text-white text-sm font-semibold focus:ring-2 focus:ring-blue-500 focus:outline-none"
            @change="onEventChange"
          >
            <option value="" disabled>-- Select Event to Record Attendance --</option>
            <option v-for="ev in events" :key="ev.id" :value="ev.id">
              {{ ev.title }} ({{ ev.venue }}) &bull; [{{ ev.audience_type || 'Inclusive' }}]
            </option>
          </select>

          <!-- Selected Event Badge Strip -->
          <div v-if="selectedEvent" class="mt-3 p-3 rounded-xl bg-slate-950/70 border border-slate-800 flex flex-wrap items-center justify-between gap-2 text-xs">
            <div class="flex items-center space-x-2">
              <span
                :class="[
                  'px-2.5 py-1 rounded-lg font-bold text-xs uppercase border',
                  selectedEvent.audience_type === 'Exclusive'
                    ? 'bg-purple-500/20 text-purple-300 border-purple-500/30'
                    : 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30'
                ]"
              >
                {{ selectedEvent.audience_type === 'Exclusive' ? '🔒 Exclusive (Members Only)' : '🌐 Inclusive (All Campus)' }}
              </span>
              <span class="text-slate-300 font-semibold">{{ selectedEvent.venue }}</span>
            </div>
            <span class="font-mono text-slate-400 text-[11px]">{{ formatSchedule(selectedEvent.event_date, selectedEvent.end_time) }}</span>
          </div>
        </div>

        <!-- Scanner / Input Zone -->
        <div class="p-6 rounded-2xl bg-slate-950/70 border border-slate-800 space-y-4">
          <div class="flex items-center justify-between">
            <span class="text-xs font-bold text-slate-300 uppercase tracking-wider">
              2. Scan QR / Enter Student ID
            </span>
            <span class="text-[11px] text-emerald-400 font-mono flex items-center space-x-1">
              <span class="h-2 w-2 rounded-full bg-emerald-400 animate-pulse"></span>
              <span>Terminal Ready</span>
            </span>
          </div>

          <form class="flex flex-col sm:flex-row gap-3" @submit.prevent="processScan">
            <input
              ref="scanInputRef"
              v-model="studentNumberInput"
              type="text"
              autofocus
              placeholder="e.g. 2024-10001 or bsit.student"
              class="flex-1 px-4 py-3 bg-slate-900 border border-slate-700 rounded-xl text-white placeholder-slate-500 text-sm font-mono focus:ring-2 focus:ring-blue-500 focus:outline-none uppercase"
            />
            <button
              type="submit"
              :disabled="loading || !selectedEventId || !studentNumberInput"
              class="px-6 py-3 rounded-xl bg-blue-600 hover:bg-blue-500 disabled:opacity-50 text-white font-bold text-sm shadow-lg shadow-blue-500/25 active:scale-95 transition"
            >
              {{ loading ? 'Verifying...' : 'Check-In' }}
            </button>
          </form>
        </div>

        <!-- Real-Time Feedback Alert -->
        <div v-if="lastResult" :class="alertBoxClass">
          <div class="flex items-start space-x-3">
            <div :class="alertIconClass">
              <svg v-if="lastResult.success" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
              <svg v-else class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            </div>
            <div>
              <div class="text-sm font-bold">{{ lastResult.title }}</div>
              <div class="text-xs mt-1">{{ lastResult.message }}</div>
              <div v-if="lastResult.student" class="mt-2 text-xs font-mono opacity-80">
                Attendee: {{ lastResult.student.name }} ({{ lastResult.student.student_number }}) • Time: {{ lastResult.student.check_in || 'N/A' }}
              </div>
            </div>
          </div>
        </div>

        <!-- Recent Session Check-Ins -->
        <div v-if="sessionLogs.length > 0" class="space-y-3 pt-4 border-t border-slate-800">
          <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400">Recent Session Check-Ins</h3>
          <div class="space-y-2">
            <div
              v-for="(item, idx) in sessionLogs"
              :key="idx"
              class="p-3 rounded-xl bg-slate-950/50 border border-slate-800 flex items-center justify-between text-xs font-mono"
            >
              <div class="flex items-center space-x-2">
                <span class="text-emerald-400">✓</span>
                <span class="text-white font-bold">{{ item.name }}</span>
                <span class="text-slate-500">({{ item.student_number }})</span>
              </div>
              <span class="text-slate-400">{{ item.check_in }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
  events: Array,
});

const selectedEventId = ref(props.events?.[0]?.id || '');
const studentNumberInput = ref('');
const loading = ref(false);
const lastResult = ref(null);
const scanInputRef = ref(null);
const sessionLogs = ref([]);

const selectedEvent = computed(() => {
  return props.events?.find((e) => e.id === Number(selectedEventId.value)) || null;
});

const onEventChange = () => {
  lastResult.value = null;
};

const formatSchedule = (start, end) => {
  if (!start) return '';
  const s = new Date(start);
  const sStr = s.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
  if (end) {
    const e = new Date(end);
    const eStr = e.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
    return `${s.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} • ${sStr} - ${eStr}`;
  }
  return `${s.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} • ${sStr}`;
};

const alertBoxClass = computed(() => {
  if (!lastResult.value) return '';
  if (lastResult.value.success) {
    return 'p-4 rounded-2xl bg-emerald-950/60 border border-emerald-800 text-emerald-200';
  }
  if (lastResult.value.exclusive_denied) {
    return 'p-4 rounded-2xl bg-purple-950/60 border border-purple-700 text-purple-200';
  }
  return 'p-4 rounded-2xl bg-red-950/60 border border-red-800 text-red-200';
});

const alertIconClass = computed(() => {
  if (!lastResult.value) return '';
  if (lastResult.value.success) {
    return 'h-10 w-10 rounded-xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center shrink-0';
  }
  if (lastResult.value.exclusive_denied) {
    return 'h-10 w-10 rounded-xl bg-purple-500/20 text-purple-400 flex items-center justify-center shrink-0';
  }
  return 'h-10 w-10 rounded-xl bg-red-500/20 text-red-400 flex items-center justify-center shrink-0';
});

const processScan = async () => {
  if (!selectedEventId.value) {
    alert('Please select an active event first.');
    return;
  }
  if (!studentNumberInput.value.trim()) return;

  loading.value = true;
  lastResult.value = null;

  try {
    const res = await axios.post('/attendance/scan', {
      event_id: selectedEventId.value,
      student_number: studentNumberInput.value.trim(),
      method: 'Manual',
    });

    lastResult.value = {
      success: true,
      title: 'Valid Attendance Recorded',
      message: res.data.message,
      student: res.data.student,
    };

    if (res.data.student) {
      sessionLogs.value.unshift(res.data.student);
    }

    studentNumberInput.value = '';
  } catch (err) {
    const data = err.response?.data;
    lastResult.value = {
      success: false,
      exclusive_denied: data?.exclusive_denied,
      title: data?.exclusive_denied
        ? 'Restricted Access: Exclusive Event'
        : (data?.duplicate ? 'Duplicate Check-In Detected' : 'Scan Error'),
      message: data?.message || 'Failed to record attendance.',
      student: data?.student,
    };
  } finally {
    loading.value = false;
    scanInputRef.value?.focus();
  }
};
</script>

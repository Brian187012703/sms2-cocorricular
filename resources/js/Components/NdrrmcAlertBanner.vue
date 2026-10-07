<template>
  <div
    v-if="showAlert && event"
    class="relative overflow-hidden mb-6 rounded-2xl bg-gradient-to-r from-red-950/90 via-amber-950/80 to-slate-900/95 border-2 border-red-500/80 shadow-2xl shadow-red-950/50 p-4 sm:p-5 text-white transition-all duration-300"
  >
    <!-- Hazard Warning Top Strip -->
    <div class="absolute top-0 left-0 right-0 h-1.5 bg-gradient-to-r from-red-500 via-amber-400 to-red-500 animate-pulse"></div>

    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <!-- Left: Siren & Event Message -->
      <div class="flex items-start space-x-3.5">
        <div class="h-11 w-11 rounded-2xl bg-red-600/30 border border-red-500/60 flex items-center justify-center shrink-0 shadow-lg shadow-red-600/30 animate-bounce">
          <span class="text-xl">🚨</span>
        </div>

        <div class="space-y-1">
          <div class="flex flex-wrap items-center gap-2">
            <span class="px-2 py-0.5 rounded-md bg-red-600 text-white font-black text-[10px] tracking-widest uppercase border border-red-400 shadow-sm animate-pulse">
              NDRRMC-STYLE CAMPUS ALERT
            </span>
            <span class="px-2 py-0.5 rounded-md bg-amber-500/20 text-amber-300 font-bold text-[10px] border border-amber-500/30 uppercase">
              UPCOMING EVENT IN PROGRESS
            </span>
            <span
              :class="[
                'px-2 py-0.5 rounded-md font-bold text-[10px] border uppercase',
                event.audience_type === 'Exclusive'
                  ? 'bg-purple-500/20 text-purple-300 border-purple-500/40'
                  : 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40'
              ]"
            >
              {{ event.audience_type === 'Exclusive' ? '🔒 Exclusive (Members Only)' : '🌐 Inclusive (Open to All)' }}
            </span>
          </div>

          <h3 class="text-base sm:text-lg font-black text-white tracking-tight leading-snug">
            {{ event.title }}
          </h3>

          <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-300">
            <span class="flex items-center space-x-1 font-semibold text-amber-300">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
              <span>Venue: {{ event.venue }}</span>
            </span>

            <span class="flex items-center space-x-1 font-mono text-slate-300">
              <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              <span>{{ formatDateTime(event.event_date, event.end_time) }}</span>
            </span>

            <span v-if="event.club" class="text-slate-400 text-[11px]">
              Organized by: <strong class="text-white">{{ event.club.name }}</strong>
            </span>
          </div>
        </div>
      </div>

      <!-- Right: Action Buttons & Siren Audio -->
      <div class="flex items-center space-x-2 shrink-0 self-end md:self-center">
        <!-- Audio Siren Beep Toggle -->
        <button
          type="button"
          :title="isPlayingSiren ? 'Mute Siren Tone' : 'Play Emergency Alert Siren Tone'"
          :class="[
            'p-2 rounded-xl text-xs font-bold border transition flex items-center space-x-1',
            isPlayingSiren
              ? 'bg-red-600 text-white border-red-500 animate-pulse'
              : 'bg-slate-900/80 text-amber-400 border-amber-500/40 hover:bg-slate-800'
          ]"
          @click="toggleSiren"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.536 8.464a5 5 0 010 7.072m2.828-9.9a9 9 0 010 12.728M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z"/></svg>
          <span class="text-[11px]">{{ isPlayingSiren ? 'Tone On' : 'Alert Sound' }}</span>
        </button>

        <!-- View Event Details Link -->
        <Link
          href="/events"
          class="px-3.5 py-2 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-black text-xs transition shadow-lg shadow-amber-500/20 active:scale-95"
        >
          View Details
        </Link>

        <!-- Acknowledge / Dismiss Button -->
        <button
          type="button"
          class="px-3 py-2 rounded-xl bg-slate-900/90 hover:bg-slate-800 text-slate-300 hover:text-white font-semibold text-xs border border-slate-700 transition"
          @click="dismissAlert"
        >
          Acknowledge &times;
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
  event: {
    type: Object,
    default: null,
  },
});

const showAlert = ref(false);
const isPlayingSiren = ref(false);
let audioCtx = null;
let sirenInterval = null;

const formatDateTime = (start, end) => {
  if (!start) return '';
  const startDate = new Date(start);
  const dateStr = startDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  const startStr = startDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
  
  if (end) {
    const endDate = new Date(end);
    const endStr = endDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
    return `${dateStr} • ${startStr} – ${endStr}`;
  }
  return `${dateStr} • ${startStr}`;
};

const checkDismissed = () => {
  if (!props.event) {
    showAlert.value = false;
    return;
  }
  const key = `acknowledged_event_alert_${props.event.id}`;
  if (sessionStorage.getItem(key)) {
    showAlert.value = false;
  } else {
    showAlert.value = true;
  }
};

const dismissAlert = () => {
  stopSiren();
  if (props.event) {
    sessionStorage.setItem(`acknowledged_event_alert_${props.event.id}`, 'true');
  }
  showAlert.value = false;
};

// Web Audio API Synthesized Alert Sound
const playTone = (freq, durationMs) => {
  try {
    if (!audioCtx) {
      audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    }
    if (audioCtx.state === 'suspended') {
      audioCtx.resume();
    }
    const osc = audioCtx.createOscillator();
    const gain = audioCtx.createGain();
    osc.type = 'sawtooth';
    osc.frequency.setValueAtTime(freq, audioCtx.currentTime);
    gain.gain.setValueAtTime(0.08, audioCtx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + (durationMs / 1000));
    osc.connect(gain);
    gain.connect(audioCtx.destination);
    osc.start();
    osc.stop(audioCtx.currentTime + (durationMs / 1000));
  } catch (err) {
    console.warn('Audio tone error', err);
  }
};

const toggleSiren = () => {
  if (isPlayingSiren.value) {
    stopSiren();
  } else {
    startSiren();
  }
};

const startSiren = () => {
  isPlayingSiren.value = true;
  let highTone = true;
  // Play initial two tones
  playTone(880, 250);
  setTimeout(() => playTone(660, 250), 300);

  sirenInterval = setInterval(() => {
    if (highTone) {
      playTone(880, 200);
    } else {
      playTone(660, 200);
    }
    highTone = !highTone;
  }, 400);

  // Auto stop after 8 seconds
  setTimeout(() => {
    stopSiren();
  }, 8000);
};

const stopSiren = () => {
  isPlayingSiren.value = false;
  if (sirenInterval) {
    clearInterval(sirenInterval);
    sirenInterval = null;
  }
};

onMounted(() => {
  checkDismissed();
});

watch(() => props.event, () => {
  checkDismissed();
});
</script>

<template>
  <div v-if="isOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-md">
    <div class="bg-slate-900 border border-slate-700 rounded-3xl max-w-3xl w-full p-6 sm:p-8 space-y-6 shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
      <!-- Header -->
      <div class="flex items-center justify-between border-b border-slate-800 pb-4 shrink-0">
        <div class="flex items-center space-x-3">
          <div class="h-10 w-10 rounded-xl bg-blue-600/20 text-blue-400 flex items-center justify-center border border-blue-500/30">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
          </div>
          <div>
            <h2 class="text-lg font-bold text-white tracking-tight">Institutional Document Templates</h2>
            <p class="text-xs text-slate-400">Standardized official templates for co-curricular submissions &amp; endorsements.</p>
          </div>
        </div>
        <button
          class="text-slate-400 hover:text-white p-2 rounded-xl hover:bg-slate-800 transition"
          @click="close"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>

      <!-- Template Tabs -->
      <div class="flex space-x-2 border-b border-slate-800 pb-2 overflow-x-auto shrink-0 custom-scrollbar">
        <button
          v-for="t in templates"
          :key="t.id"
          type="button"
          :class="[
            'px-3.5 py-2 rounded-xl text-xs font-semibold whitespace-nowrap transition',
            activeTemplateId === t.id
              ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20'
              : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/60'
          ]"
          @click="activeTemplateId = t.id"
        >
          {{ t.name }}
        </button>
      </div>

      <!-- Template Content Body -->
      <div class="flex-1 overflow-y-auto space-y-4 pr-1 custom-scrollbar">
        <div class="flex items-center justify-between">
          <div>
            <h3 class="text-sm font-bold text-white">{{ activeTemplate.name }}</h3>
            <span class="text-[11px] text-slate-400">{{ activeTemplate.description }}</span>
          </div>
          <div class="flex items-center space-x-2">
            <button
              type="button"
              class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold flex items-center space-x-1.5 transition active:scale-95"
              @click="copyContent"
            >
              <svg class="w-3.5 h-3.5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
              <span>{{ copied ? 'Copied!' : 'Copy Template' }}</span>
            </button>
            <button
              type="button"
              class="px-3 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold flex items-center space-x-1.5 transition active:scale-95 shadow-md shadow-blue-500/20"
              @click="downloadTemplate"
            >
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
              <span>Download (.txt)</span>
            </button>
          </div>
        </div>

        <div class="bg-slate-950 border border-slate-800 rounded-2xl p-4 font-mono text-xs text-slate-300 whitespace-pre-wrap leading-relaxed select-all">
          {{ activeTemplate.content }}
        </div>
      </div>

      <!-- Footer -->
      <div class="pt-3 border-t border-slate-800 flex items-center justify-between text-xs text-slate-500 shrink-0">
        <span>Office of Student Affairs &amp; Supreme Student Council Institutional Standards</span>
        <button
          type="button"
          class="px-4 py-2 rounded-xl bg-slate-800 text-slate-300 font-semibold hover:bg-slate-700"
          @click="close"
        >
          Close
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
  modelValue: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['update:modelValue']);

const isOpen = computed({
  get: () => props.modelValue,
  set: (val) => emit('update:modelValue', val),
});

const close = () => {
  isOpen.value = false;
};

const copied = ref(false);

const templates = [
  {
    id: 'endorsement',
    name: 'Adviser Endorsement Letter',
    description: 'Mandatory faculty adviser official endorsement for member applications & projects.',
    content: `MEMORANDUM OF ENDORSEMENT
OFFICE OF THE FACULTY ADVISER
BESTLINK COLLEGE OF THE PHILIPPINES

DATE: [Insert Date, e.g., October 06, 2026]
TO: SUPREME STUDENT COUNCIL (SSC) / OFFICE OF STUDENT AFFAIRS
FROM: [Faculty Adviser Name], Faculty Club Adviser
SUBJECT: OFFICIAL FACULTY ENDORSEMENT FOR MEMBERSHIP / ACCREDITATION

Dear Council Officers and Administrators,

I am writing to formally provide my official faculty endorsement for:
Student Name: [Insert Student Full Name]
Student ID No: [Insert Student Number, e.g., 2024-10001]
Program & Year: [e.g., BSIT - 3rd Year]
Organization: [Insert Club / Organization Name]

Having reviewed the applicant's academic standing, conduct records, and stated commitment to our organization's constitution and by-laws, I verify that they meet all institutional eligibility guidelines.

I hereby recommend the unconditional endorsement of this application for Stage 2 SSC Institutional Review and subsequent Administrative Clearance.

Respectfully submitted,

____________________________________________
[Faculty Adviser Name]
Faculty Adviser, [Organization Name]
Bestlink College of the Philippines`
  },
  {
    id: 'intent',
    name: 'Student Letter of Intent',
    description: 'Required statement of motivation and advocacy for student club applications.',
    content: `STATEMENT OF INTENT & MEMBERSHIP ADVOCACY
BESTLINK COLLEGE OF THE PHILIPPINES

Date: [Insert Date]
To: The Executive Committee & Faculty Adviser, [Target Club Name]
From: [Student Full Name] (ID: [Student Number])
Program: [Degree Course, e.g. BS Information Technology]

Subject: Application for Active Organization Membership

Dear Executive Board and Faculty Adviser,

I am writing to express my strong intent to join [Target Club Name] for the Academic Year 2026-2027.

1. Motivation & Goals:
[Describe your passion, relevant academic skills, and why this organization is your top choice.]

2. Intended Contribution:
[Explain how you plan to contribute to club projects, community activities, and student development.]

3. Commitment to Conduct:
I solemnly commit to upholding the values of Bestlink College of the Philippines and adhering faithfully to our organization's Constitution and By-Laws.

Thank you very much for your time and consideration.

Sincerely,

____________________________________________
[Student Full Name]
Applicant Member`
  },
  {
    id: 'event',
    name: 'Event Activity Proposal',
    description: 'Standard event requisition plan with schedule, venue safety, and objectives.',
    content: `CAMPUS CO-CURRICULAR ACTIVITY PROPOSAL
BESTLINK COLLEGE OF THE PHILIPPINES

I. BASIC INFORMATION
- Activity Title: [Title of Activity / Seminar / Workshop]
- Organizing Body: [Club / Organization Name]
- Proposed Date & Start Time: [YYYY-MM-DD HH:MM]
- Proposed Time End: [YYYY-MM-DD HH:MM]
- Target Venue: [e.g., Main Campus Auditorium / AVR 1 / Quadrangle]
- Target Attendees: [Estimated Number, e.g., 150 students]
- Audience Scope: [Inclusive: All Campus Students | Exclusive: Club Members Only]

II. RATIONALE & OBJECTIVES
1. Primary Objective: [State main educational or community purpose]
2. Key Outcomes: [Expected skills, insights, or community benefit]

III. SCHEDULE OF ACTIVITIES (PROGRAM FLOW)
- [Time 1]: Registration & QR Code Attendance Verification
- [Time 2]: Opening Remarks & Keynote Address
- [Time 3]: Interactive Workshop / Main Session
- [Time 4]: Open Forum & Evaluation Survey
- [Time 5]: Closing Ceremony & Digital Certificate Distribution

IV. LOGISTICS & SAFETY PROTOCOL
- Sound, Projector, and Technical Requirements verified.
- Venue reservation conflict checked and confirmed clear.
- Campus Safety and Emergency Protocols in place.

Prepared by: [Project Head / Student Officer]
Endorsed by: [Faculty Club Adviser]
Cleared by: Supreme Student Council (SSC)`
  },
  {
    id: 'budget',
    name: 'Budget Requisition Form',
    description: 'Itemized expenditure justification for club project disbursements.',
    content: `STUDENT CO-CURRICULAR BUDGET REQUISITION
BESTLINK COLLEGE OF THE PHILIPPINES

Organization Name: [Club Name]
Activity Title: [Associated Event / Project Name]
Date of Request: [YYYY-MM-DD]
Total Requested Amount: PHP [Amount, e.g., 15,000.00]

ITEMIZED EXPENDITURE BREAKDOWN:
1. Materials & Supplies:
   - Item A: PHP [0.00]
   - Item B: PHP [0.00]
2. Speaker Honorarium / Tokens: PHP [0.00]
3. Logistics & Sound Setup: PHP [0.00]
4. Food & Water Provision for Participants: PHP [0.00]
5. Miscellaneous Contingency (Max 5%): PHP [0.00]

FINANCIAL ACCOUNTABILITY CLAUSE:
The organizing committee certifies that all disbursements will follow institutional procurement rules and submit official liquidation receipts within 5 days post-event.

Submitted by: [Club Treasurer / President]
Faculty Endorsement: [Faculty Adviser Signature / Approval]
SSC Reviewer: [SSC Finance Committee]
Admin Disbursement Officer: [Office of Administration]`
  },
  {
    id: 'election',
    name: 'Election Platform Slate Template',
    description: 'Official position structure and candidate slate guidelines for voting.',
    content: `STUDENT GOVERNANCE ELECTION BLUEPRINT & PLATFORM
BESTLINK COLLEGE OF THE PHILIPPINES

Election Title: [e.g., General Student Council Executive Elections 2026]
Governing Body: [Club Name / Supreme Student Council]
Voting Period: [Starts At] to [Closes At]
Eligible Electorate: [Active Enrolled Club Members / Campus Students]

STANDARD POSITION SLATE:
1. PRESIDENT (Chief Executive Officer)
   - Scope: Overall organization representation, institutional leadership.
2. VICE PRESIDENT (Operations & Internal Affairs)
   - Scope: Committees supervision, internal governance.
3. SECRETARY GENERAL
   - Scope: Minutes of meetings, official correspondence, roster management.
4. TREASURER (Finance Officer)
   - Scope: Budget requisitions, collection logs, liquidation reports.
5. AUDITOR
   - Scope: Financial vetting, transparency audits, inventory check.
6. PUBLIC RELATIONS OFFICER (PRO)
   - Scope: Bulletin notices, digital announcements, external relations.
7. YEAR LEVEL REPRESENTATIVES (1st, 2nd, 3rd, 4th Year)

CANDIDATE PLATFORM GUIDELINES:
- Mission Statement (Max 150 words)
- 3 Core Action Pillars
- Signature Project Proposal

Approved by: Commission on Student Elections (COMELEC) & Faculty Adviser`
  }
];

const activeTemplateId = ref('endorsement');

const activeTemplate = computed(() => {
  return templates.find((t) => t.id === activeTemplateId.value) || templates[0];
});

const copyContent = async () => {
  try {
    await navigator.clipboard.writeText(activeTemplate.value.content);
    copied.value = true;
    setTimeout(() => {
      copied.value = false;
    }, 2000);
  } catch (e) {
    console.error('Failed to copy', e);
  }
};

const downloadTemplate = () => {
  const element = document.createElement('a');
  const file = new Blob([activeTemplate.value.content], { type: 'text/plain' });
  element.href = URL.createObjectURL(file);
  element.download = `${activeTemplate.value.id}_template.txt`;
  document.body.appendChild(element);
  element.click();
  document.body.removeChild(element);
};
</script>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
  height: 4px;
  width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background: rgba(148, 163, 184, 0.2);
  border-radius: 4px;
}
</style>

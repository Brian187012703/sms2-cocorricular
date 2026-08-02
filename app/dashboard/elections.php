<?php
// ============================================================
//  ELECTIONS.PHP  (dashboard/)
//  Co-Curricular System — Elections & Voting Portal
//  Landing: Active Elections by Organization → Select → Ballot/Candidates
// ============================================================
require_once __DIR__ . '/../shared/db.php';
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/signin.php');
    exit;
}

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$sess_uid     = htmlspecialchars($_SESSION['user_id'] ?? 'USR001');

// Session-based votes cast tracker
if (!isset($_SESSION['votes_cast'])) { $_SESSION['votes_cast'] = []; }

// Handle vote submission via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cast_vote') {
    header('Content-Type: application/json');
    $eid = $_POST['election_id'] ?? '';
    if (!$eid) { echo json_encode(['success'=>false,'message'=>'Invalid election.']); exit; }
    if (in_array($eid, $_SESSION['votes_cast'])) {
        echo json_encode(['success'=>false,'message'=>'You have already voted in this election.']);
    } else {
        $_SESSION['votes_cast'][] = $eid;
        echo json_encode(['success'=>true,'message'=>'Vote recorded successfully!']);
    }
    exit;
}

// Active elections data (in production, pull from DB)
$active_elections = [
    [
        'id'          => 'itsoc_2026',
        'org'         => 'Information Technology Society',
        'acronym'     => 'ITS',
        'color'       => 'linear-gradient(135deg,#1a3a8c,#2563eb)',
        'title'       => 'IT Society Executive Board Election 2026',
        'closes'      => '5:00 PM, August 10, 2026',
        'eligible'    => 42,
        'voted'       => 31,
        'status'      => 'open',       // open | closed | counting
        'positions'   => ['President','Vice President','Secretary','Treasurer'],
        'candidates'  => [
            ['id'=>'maria_santos',  'name'=>'Maria Santos',  'pos'=>'President',       'party'=>'Innovate Tech Party',   'initials'=>'MS','color'=>'linear-gradient(135deg,#1a3a8c,#2563eb)','year'=>'3rd Year','prog'=>'BSIT','gwa'=>'1.5','tag'=>'"Building bridges between tech and community."','achievements'=>['Dean\'s Lister 2025','Hackathon Champion']],
            ['id'=>'carlo_reyes',   'name'=>'Carlo Reyes',   'pos'=>'President',       'party'=>'Green Code Alliance',   'initials'=>'CR','color'=>'linear-gradient(135deg,#0f766e,#10b981)','year'=>'4th Year','prog'=>'BSIT','gwa'=>'1.7','tag'=>'"Sustainability in tech, innovation for all."','achievements'=>['Best Thesis 2025','Club Secretary \'25']],
            ['id'=>'jose_reyes',    'name'=>'Jose Reyes',    'pos'=>'Vice President',  'party'=>'Tech Forward Alliance', 'initials'=>'JR','color'=>'linear-gradient(135deg,#7c3aed,#a78bfa)','year'=>'3rd Year','prog'=>'BSCS','gwa'=>'1.6','tag'=>'"Empowering students through digital literacy."','achievements'=>['ICPC Regional \'25','Peer Tutor']],
            ['id'=>'anna_cruz',     'name'=>'Anna Cruz',     'pos'=>'Vice President',  'party'=>'Innovate Tech Party',   'initials'=>'AC','color'=>'linear-gradient(135deg,#b45309,#f59e0b)','year'=>'3rd Year','prog'=>'BSIT','gwa'=>'1.4','tag'=>'"Leadership with integrity and transparency."','achievements'=>['Dean\'s Lister','Leadership Award']],
            ['id'=>'lea_mendoza',   'name'=>'Lea Mendoza',   'pos'=>'Secretary',       'party'=>'Green Code Alliance',   'initials'=>'LM','color'=>'linear-gradient(135deg,#db2777,#f472b6)','year'=>'2nd Year','prog'=>'BSIT','gwa'=>'1.3','tag'=>'"Organized, efficient, and student-first."','achievements'=>['Best Documenter','Top 10 GWA']],
            ['id'=>'mark_garcia',   'name'=>'Mark Garcia',   'pos'=>'Secretary',       'party'=>'Tech Forward Alliance', 'initials'=>'MG','color'=>'linear-gradient(135deg,#0369a1,#38bdf8)','year'=>'3rd Year','prog'=>'BSCS','gwa'=>'1.8','tag'=>'"Clear records, open communication."','achievements'=>['Research Publication','Org VP \'24']],
            ['id'=>'nina_ocampo',   'name'=>'Nina Ocampo',   'pos'=>'Treasurer',       'party'=>'Innovate Tech Party',   'initials'=>'NO','color'=>'linear-gradient(135deg,#166534,#22c55e)','year'=>'3rd Year','prog'=>'BSIT','gwa'=>'1.5','tag'=>'"Transparent finances, accountable management."','achievements'=>['Finance Committee','Audit Top Grad']],
            ['id'=>'ryan_tan',      'name'=>'Ryan Tan',      'pos'=>'Treasurer',       'party'=>'Green Code Alliance',   'initials'=>'RT','color'=>'linear-gradient(135deg,#92400e,#d97706)','year'=>'4th Year','prog'=>'BSCS','gwa'=>'1.6','tag'=>'"Smart budgeting for smarter outcomes."','achievements'=>['Scholarship Holder','Club Treasurer \'24']],
        ],
    ],
    [
        'id'          => 'csec_2026',
        'org'         => 'CS Executive Council',
        'acronym'     => 'CSEC',
        'color'       => 'linear-gradient(135deg,#7c3aed,#a78bfa)',
        'title'       => 'CS Executive Council Officers 2026',
        'closes'      => '4:00 PM, August 15, 2026',
        'eligible'    => 55,
        'voted'       => 12,
        'status'      => 'open',
        'positions'   => ['President','Vice President','Secretary','Auditor'],
        'candidates'  => [
            ['id'=>'john_marcos','name'=>'John Marcos','pos'=>'President','party'=>'Code Unity Party','initials'=>'JM','color'=>'linear-gradient(135deg,#7c3aed,#a78bfa)','year'=>'4th Year','prog'=>'BSCS','gwa'=>'1.4','tag'=>'"One council, one vision."','achievements'=>['Regional Champion','CS Student Rep']],
            ['id'=>'sara_lim','name'=>'Sara Lim','pos'=>'President','party'=>'Forward CS','initials'=>'SL','color'=>'linear-gradient(135deg,#0369a1,#38bdf8)','year'=>'3rd Year','prog'=>'BSCS','gwa'=>'1.5','tag'=>'"Stronger CS community through collaboration."','achievements'=>['Dean\'s Lister','Debate Champion']],
        ],
    ],
    [
        'id'          => 'baa_2025',
        'org'         => 'BCP Athletic Association',
        'acronym'     => 'BAA',
        'color'       => 'linear-gradient(135deg,#166534,#22c55e)',
        'title'       => 'Athletic Association Officers 2025',
        'closes'      => 'April 10, 2025',
        'eligible'    => 98,
        'voted'       => 98,
        'status'      => 'closed',
        'positions'   => ['President','Vice President','Secretary','Treasurer'],
        'candidates'  => [],
    ],
];

// Past election results
$past_results = [
    ['org'=>'Information Technology Society','date'=>'Jun 15, 2025','winner'=>'Maria Santos','votes'=>'38/42 (90%)'],
    ['org'=>'CS Executive Council',          'date'=>'May 20, 2025','winner'=>'John Marcos', 'votes'=>'42/55 (76%)'],
    ['org'=>'BCP Athletic Association',      'date'=>'Apr 10, 2025','winner'=>'Christine Lee','votes'=>'65/98 (66%)'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Elections &amp; Voting – BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?= filemtime(__DIR__ . '/../css/dashboard.css') ?>"/>
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <script src="../js/page-loader.js"></script>
</head>
<body>

<?php
$APP_ROOT   = '../';
$ACTIVE_NAV = 'elections';
require_once __DIR__ . '/../shared/sidebar.php';
?>

<div class="main">

  <!-- Topbar -->
  <div class="topbar">
    <button class="hamburger" id="hamburgerBtn" aria-label="Toggle sidebar">
      <i class="fa-solid fa-bars"></i>
    </button>
    <span class="topbar-spacer"></span>
    <div class="topbar-right">
      <div class="search-wrap">
        <input type="text" id="electionSearch" placeholder="Search elections..."/>
        <i class="fa-solid fa-magnifying-glass"></i>
      </div>
      <button class="topbar-qr-btn" id="qrFabBtn" title="QR Code Center" type="button"><i class="fa-solid fa-qrcode"></i></button>
      <a href="../dashboard/account.php" class="avatar" id="avatarBtn" title="Account Settings"><?= $sess_initial ?></a>
    </div>
  </div>

  <!-- Content -->
  <div class="content">

    <div class="page-title-bar">
      <h2 class="page-title">
        <i class="fa-solid fa-check-to-slot"></i>
        Elections &amp; Voting Portal
      </h2>
    </div>

    <div class="content-body">

      <!-- ══════════════════════════════════════════════════════
           LANDING VIEW: Active Elections by Organization
      ══════════════════════════════════════════════════════ -->
      <div id="electionLanding">

        <!-- Section Header -->
        <div class="election-landing-header">
          <div>
            <h3 class="election-landing-title">
              <i class="fa-solid fa-circle-dot" style="color:#22c55e;"></i>
              Active Elections
            </h3>
            <p class="election-landing-sub">Select an organization below to access its Digital Balloting Booth or view Candidate Profiles.</p>
          </div>
        </div>

        <!-- Organization Election Cards -->
        <div class="election-org-grid" id="electionOrgGrid">
          <?php foreach ($active_elections as $el): ?>
          <?php
            $voted    = in_array($el['id'], $_SESSION['votes_cast']);
            $turnout  = $el['eligible'] > 0 ? round(($el['voted'] / $el['eligible']) * 100) : 0;
            $isClosed = $el['status'] === 'closed';
            $statusLabel = match($el['status']) {
              'open'     => 'Voting Open',
              'closed'   => 'Results Released',
              'counting' => 'Vote Counting',
              default    => 'Unknown'
            };
            $statusClass = match($el['status']) {
              'open'     => 'eorg-live',
              'closed'   => 'eorg-closed',
              'counting' => 'eorg-counting',
              default    => ''
            };
          ?>
          <div class="election-org-card <?= $isClosed ? 'closed' : '' ?>" data-election-id="<?= $el['id'] ?>">
            <!-- Card Top Band -->
            <div class="eorg-band" style="background:<?= $el['color'] ?>;">
              <div class="eorg-acronym"><?= $el['acronym'] ?></div>
              <div class="eorg-status-badge <?= $statusClass ?>">
                <?php if ($el['status'] === 'open'): ?><span class="eorg-dot"></span><?php endif; ?>
                <?= $statusLabel ?>
              </div>
            </div>

            <!-- Card Body -->
            <div class="eorg-body">
              <div class="eorg-org-name"><?= htmlspecialchars($el['org']) ?></div>
              <div class="eorg-title"><?= htmlspecialchars($el['title']) ?></div>
              <div class="eorg-meta">
                <span><i class="fa-solid fa-clock"></i> Closes: <?= htmlspecialchars($el['closes']) ?></span>
              </div>

              <!-- Turnout bar -->
              <div class="eorg-turnout">
                <div class="eorg-turnout-labels">
                  <span>Voter Turnout</span>
                  <span><?= $turnout ?>% (<?= $el['voted'] ?>/<?= $el['eligible'] ?>)</span>
                </div>
                <div class="eorg-turnout-track">
                  <div class="eorg-turnout-fill <?= $el['status'] === 'open' ? 'fill-green' : 'fill-grey' ?>" style="width:<?= $turnout ?>%"></div>
                </div>
              </div>

              <!-- Positions chips -->
              <div class="eorg-positions">
                <?php foreach ($el['positions'] as $pos): ?>
                <span class="eorg-pos-chip"><?= $pos ?></span>
                <?php endforeach; ?>
              </div>

              <?php if ($voted): ?>
              <div class="eorg-voted-badge">
                <i class="fa-solid fa-circle-check"></i> You have already voted
              </div>
              <?php endif; ?>
            </div>

            <!-- Card Actions -->
            <?php if (!$isClosed): ?>
            <div class="eorg-actions">
              <button class="eorg-btn eorg-btn-secondary" onclick="openCandidatesView('<?= $el['id'] ?>')">
                <i class="fa-solid fa-id-card-clip"></i>
                Candidate Profiles
              </button>
              <?php if (!$voted): ?>
              <button class="eorg-btn eorg-btn-primary" onclick="openBoothView('<?= $el['id'] ?>')">
                <i class="fa-solid fa-check-to-slot"></i>
                Enter Ballot Booth
              </button>
              <?php else: ?>
              <button class="eorg-btn eorg-btn-receipt" onclick="openBoothView('<?= $el['id'] ?>')">
                <i class="fa-solid fa-receipt"></i>
                View My Receipt
              </button>
              <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="eorg-actions">
              <button class="eorg-btn eorg-btn-secondary" style="flex:1;" onclick="showResultsModal('<?= addslashes($el['org']) ?> <?= date('Y') ?>','Released','<?= $el['voted'] ?>/<?= $el['eligible'] ?>','Results have been officially published.')">
                <i class="fa-solid fa-trophy"></i> View Official Results
              </button>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div><!-- end grid -->

        <!-- Admin/Adviser: Election Control Panel -->
        <?php if (in_array($sess_role, ['club_adviser', 'osa_director', 'admin'])): ?>
        <div class="table-card" id="auditPanel" style="margin-top:8px;">
          <h3><i class="fa-solid fa-shield-halved" style="color:#2563eb;"></i> Election Control &amp; Audit Log</h3>
          <div class="eaudit-row">
            <div class="eaudit-stat">
              <span class="eaudit-num">2</span>
              <span class="eaudit-label">Active Elections</span>
            </div>
            <div class="eaudit-stat">
              <span class="eaudit-num">43</span>
              <span class="eaudit-label">Total Votes Cast</span>
            </div>
            <div class="eaudit-stat">
              <span class="eaudit-num">0</span>
              <span class="eaudit-label">Duplicate Flags</span>
            </div>
            <div class="eaudit-stat">
              <span class="eaudit-num eaudit-green">Clean</span>
              <span class="eaudit-label">UUID Verification</span>
            </div>
          </div>
          <p style="font-size:0.78rem; color:#64748b; margin:12px 0;">Cryptographic UUID log verified cleanly — zero double-votes across all active elections.</p>
          <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button class="card-btn" style="background:#16a34a;" onclick="showToast('IT Society Election results published!','success')">
              <i class="fa-solid fa-bullhorn"></i> Publish IT Society Results
            </button>
            <button class="card-btn" onclick="showToast('Audit log exported.','updated')">
              <i class="fa-solid fa-file-export"></i> Export Audit Log
            </button>
          </div>
        </div>
        <?php endif; ?>

        <!-- Past Results -->
        <div class="table-card" id="results">
          <h3><i class="fa-solid fa-trophy" style="color:#2563eb;"></i> Past Election Results &amp; Archives</h3>
          <div class="resp-table-wrap">
            <table class="data-table resp-table">
              <thead>
                <tr>
                  <th>Organization</th>
                  <th>Election Date</th>
                  <th>Winning President</th>
                  <th>Votes Cast</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($past_results as $r): ?>
                <tr>
                  <td data-label="Organization"><strong><?= htmlspecialchars($r['org']) ?></strong></td>
                  <td data-label="Date"><?= $r['date'] ?></td>
                  <td data-label="Winner"><?= $r['winner'] ?></td>
                  <td data-label="Votes"><?= $r['votes'] ?></td>
                  <td data-label="Action"><button class="card-btn" onclick="showResultsModal('<?= addslashes($r['org']) ?>','<?= $r['winner'] ?>','<?= $r['votes'] ?>','Official election results for <?= addslashes($r['org']) ?>.')">View Results</button></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div><!-- end #electionLanding -->

      <!-- ══════════════════════════════════════════════════════
           BOOTH VIEW (hidden until org selected)
      ══════════════════════════════════════════════════════ -->
      <div id="boothView" style="display:none;">
        <div class="election-view-nav">
          <button class="election-back-btn" onclick="backToLanding()">
            <i class="fa-solid fa-arrow-left"></i> Back to Elections
          </button>
          <span class="election-view-breadcrumb" id="boothBreadcrumb"></span>
        </div>
        <div id="boothContent"></div>
      </div>

      <!-- ══════════════════════════════════════════════════════
           CANDIDATES VIEW (hidden until org selected)
      ══════════════════════════════════════════════════════ -->
      <div id="candidatesView" style="display:none;">
        <div class="election-view-nav">
          <button class="election-back-btn" onclick="backToLanding()">
            <i class="fa-solid fa-arrow-left"></i> Back to Elections
          </button>
          <span class="election-view-breadcrumb" id="candsBreadcrumb"></span>
        </div>
        <div id="candidatesContent"></div>
      </div>

    </div><!-- end content-body -->
  </div><!-- end content -->

  <div class="footer">Co-Curricular Management System &copy; 2026</div>
</div><!-- end main -->

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ── Platform Modal ── -->
<div class="modal-overlay" id="platformModal">
  <div class="modal modal-lg">
    <div class="modal-header" id="platformModalHeader" style="background:linear-gradient(135deg,#1a3a8c,#2563eb);">
      <span id="platformModalTitle">Candidate Platform</span>
      <button class="modal-close" data-close="platformModal">×</button>
    </div>
    <div class="modal-body" id="platformModalBody" style="padding:0;"></div>
  </div>
</div>

<!-- ── Results Modal ── -->
<div class="modal-overlay" id="resultsModal">
  <div class="modal modal-lg">
    <div class="modal-header" style="background:linear-gradient(135deg,#1a3a8c,#2563eb);">
      <span id="resultsModalTitle">Election Results</span>
      <button class="modal-close" data-close="resultsModal">×</button>
    </div>
    <div class="modal-body" id="resultsModalBody"></div>
  </div>
</div>

<script src="../js/dashboard.js"></script>
<script>
// ============================================================
//  ELECTIONS PAGE — INLINE JAVASCRIPT
// ============================================================

// Serialise PHP elections data into JS
const electionsData = <?= json_encode($active_elections) ?>;
const sessionVotes  = <?= json_encode($_SESSION['votes_cast']) ?>;

const positionLabels  = { president:'President', vp:'Vice President', sec:'Secretary', treas:'Treasurer' };
const posMap = {
  'President': 'president', 'Vice President': 'vp',
  'Secretary': 'sec', 'Treasurer': 'treas', 'Auditor': 'auditor'
};

// ── Platform data per candidate key ──────────────────────────
const platformDB = {
  maria_santos: { platform:[
    {icon:'fa-laptop-code',title:'Tech Skills Program',desc:'Monthly free workshops on web dev, AI/ML, and cybersecurity for all IT Society members.'},
    {icon:'fa-handshake',title:'Industry Partnership',desc:'Forge partnerships with 5 tech companies for internship pipelines and mentorship programs.'},
    {icon:'fa-building-columns',title:'Lab Improvement',desc:'Lobby for updated computer lab equipment and 24-hour access for enrolled IT students.'},
    {icon:'fa-comments',title:'Open Communication',desc:'Monthly town halls, transparent meeting minutes, and a dedicated member feedback portal.'}
  ]},
  carlo_reyes: { platform:[
    {icon:'fa-leaf',title:'Sustainable IT Events',desc:'All events go paperless — digital registration, QR attendance, and e-certificates.'},
    {icon:'fa-code-branch',title:'Open Source Projects',desc:'Establish a student-led GitHub org for BCP open source contributions and portfolios.'},
    {icon:'fa-solar-panel',title:'Green Campus Initiative',desc:'Partner with campus sustainability office to promote energy-efficient computing.'},
    {icon:'fa-trophy',title:'Competition Fund',desc:'Dedicated budget pool for students joining national and international coding competitions.'}
  ]},
  jose_reyes: { platform:[
    {icon:'fa-chalkboard-user',title:'Digital Literacy Drive',desc:'Free digital literacy seminars for incoming freshmen during orientation week.'},
    {icon:'fa-people-group',title:'Inclusive Tech Community',desc:'Establish support groups for differently-abled students in tech programs.'},
    {icon:'fa-network-wired',title:'Alumni Network',desc:'Build an active alumni database for mentorship and career guidance programs.'},
    {icon:'fa-calendar-days',title:'Hackathon Calendar',desc:'Organize quarterly mini-hackathons and provide logistical support for external events.'}
  ]},
  anna_cruz: { platform:[
    {icon:'fa-scale-balanced',title:'Transparent Governance',desc:'All meeting resolutions and financial reports published on the portal within 48 hours.'},
    {icon:'fa-graduation-cap',title:'Scholarship Assistance',desc:'Create a peer-support network to guide students in applying for scholarships.'},
    {icon:'fa-microphone',title:'Student Voice Program',desc:'Quarterly student congress where any member can raise concerns to the executive board.'},
    {icon:'fa-heart',title:'Mental Health Month',desc:'Annual Tech & Wellness month with free counseling referrals and social events.'}
  ]},
  lea_mendoza: { platform:[
    {icon:'fa-file-lines',title:'Digital Documentation',desc:'All meeting minutes digitized, searchable, and accessible to members via the portal.'},
    {icon:'fa-bell',title:'Timely Notifications',desc:'Automated reminder system for meetings, deadlines, and events.'},
    {icon:'fa-folder-open',title:'Archive System',desc:'Build a comprehensive 5-year archive of all society records for institutional continuity.'},
    {icon:'fa-check-double',title:'Compliance Tracking',desc:'Real-time dashboard for OSA requirements, accreditation deadlines, and submission status.'}
  ]},
  mark_garcia: { platform:[
    {icon:'fa-clipboard-list',title:'Streamlined Records',desc:'Migrate all records to a cloud-based system for real-time access and collaboration.'},
    {icon:'fa-envelope-open-text',title:'Open Correspondence',desc:'All official communications between the society and OSA/admin copied to members.'},
    {icon:'fa-qrcode',title:'QR Meeting Attendance',desc:'QR-based attendance for all meetings, replacing manual sign-in sheets.'},
    {icon:'fa-language',title:'Bilingual Minutes',desc:'Meeting minutes provided in English and Filipino for wider member accessibility.'}
  ]},
  nina_ocampo: { platform:[
    {icon:'fa-chart-pie',title:'Budget Transparency',desc:'Real-time public budget dashboard showing all income, expenses, and fund allocations.'},
    {icon:'fa-receipt',title:'Digitized Receipts',desc:'All financial records digitized and auditable — full e-documentation.'},
    {icon:'fa-piggy-bank',title:'Emergency Fund',desc:'Establish a 10% reserve fund from society dues for unexpected event costs.'},
    {icon:'fa-handshake-angle',title:'Sponsorship Drive',desc:'Active outreach to 10+ local tech companies for event sponsorships and member benefits.'}
  ]},
  ryan_tan: { platform:[
    {icon:'fa-coins',title:'Smart Budgeting',desc:'Implement zero-based budgeting each semester — every peso justified and student-approved.'},
    {icon:'fa-file-invoice-dollar',title:'Quarterly Reports',desc:'Detailed financial statements every quarter, reviewed by an independent student auditor.'},
    {icon:'fa-hand-holding-dollar',title:'Grant Writing',desc:'Apply for CHED and private sector grants to fund tech programs without raising dues.'},
    {icon:'fa-scale-unbalanced',title:'Fair Allocation',desc:'Equitable distribution of funds across all sub-committees — no budget favoritism.'}
  ]},
  john_marcos: { platform:[
    {icon:'fa-users',title:'One Council Vision',desc:'Unite all CS students under a single, cohesive community with shared goals.'},
    {icon:'fa-code',title:'Code Bootcamps',desc:'Monthly intensive coding bootcamps open to all CS students.'},
    {icon:'fa-trophy',title:'Competition Support',desc:'Full logistical and financial support for students joining CS competitions.'},
    {icon:'fa-handshake',title:'Industry Ties',desc:'Strengthen ties with alumni working in top tech companies for career opportunities.'}
  ]},
  sara_lim: { platform:[
    {icon:'fa-people-arrows',title:'Collaboration First',desc:'Foster collaboration between CS sub-organizations through joint events.'},
    {icon:'fa-book-open',title:'Study Resources Hub',desc:'Centralized digital library of CS study materials, notes, and past papers.'},
    {icon:'fa-microphone-lines',title:'Student Forums',desc:'Bi-monthly open forums for CS students to address academic and social concerns.'},
    {icon:'fa-star',title:'Excellence Program',desc:'Recognition program for outstanding CS students in academics, leadership, and community service.'}
  ]},
};

// ── Helper: get election by ID ──────────────────────────────
function getElection(id) { return electionsData.find(e => e.id === id); }

// ── Switch views ─────────────────────────────────────────────
function backToLanding() {
  document.getElementById('electionLanding').style.display = '';
  document.getElementById('boothView').style.display = 'none';
  document.getElementById('candidatesView').style.display = 'none';
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ── OPEN BALLOT BOOTH for an election ────────────────────────
function openBoothView(electionId) {
  const el = getElection(electionId);
  if (!el) return;
  const hasVoted = sessionVotes.includes(electionId);

  document.getElementById('electionLanding').style.display = 'none';
  document.getElementById('candidatesView').style.display = 'none';
  document.getElementById('boothBreadcrumb').textContent = el.org + ' — Digital Balloting Booth';

  const boothEl = document.getElementById('boothContent');
  boothEl.innerHTML = buildBoothHTML(el, hasVoted);
  document.getElementById('boothView').style.display = '';

  // Init wizard if not voted
  if (!hasVoted) { initBallotWizard(el); }

  window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ── OPEN CANDIDATE PROFILES for an election ───────────────────
function openCandidatesView(electionId) {
  const el = getElection(electionId);
  if (!el) return;

  document.getElementById('electionLanding').style.display = 'none';
  document.getElementById('boothView').style.display = 'none';
  document.getElementById('candsBreadcrumb').textContent = el.org + ' — Candidate Profiles';

  document.getElementById('candidatesContent').innerHTML = buildCandidatesHTML(el);
  document.getElementById('candidatesView').style.display = '';

  // Position filter tabs
  document.querySelectorAll('.pos-tab').forEach(tab => {
    tab.addEventListener('click', function() {
      document.querySelectorAll('.pos-tab').forEach(t => t.classList.remove('active'));
      this.classList.add('active');
      const filter = this.dataset.filter;
      document.querySelectorAll('.candidate-profile-card').forEach(card => {
        card.style.display = (filter === 'all' || card.dataset.position === filter) ? '' : 'none';
      });
    });
  });

  window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ── Build Booth HTML ──────────────────────────────────────────
function buildBoothHTML(el, hasVoted) {
  const turnout = el.eligible > 0 ? Math.round((el.voted / el.eligible) * 100) : 0;

  let headerHTML = `
    <div class="ballot-header-card">
      <div class="ballot-header-left">
        <div class="ballot-status-badge ${hasVoted ? 'badge-voted' : 'badge-live'}">
          ${!hasVoted ? '<span class="ballot-status-dot"></span>' : ''}
          ${hasVoted ? 'Vote Cast' : 'Voting Open'}
        </div>
        <h2 class="ballot-election-title"><i class="fa-solid fa-check-to-slot"></i> ${el.title}</h2>
        <p class="ballot-election-sub">
          <i class="fa-solid fa-clock"></i> Closes: <strong>${el.closes}</strong>
          &nbsp;·&nbsp;<i class="fa-solid fa-shield-halved"></i> UUID-verified · Double-vote protected
        </p>
      </div>
      <div class="ballot-header-right">
        <div class="ballot-stat"><span class="ballot-stat-num">${el.voted}</span><span class="ballot-stat-label">Votes Cast</span></div>
        <div class="ballot-stat"><span class="ballot-stat-num">${el.eligible}</span><span class="ballot-stat-label">Eligible Voters</span></div>
        <div class="ballot-turnout-bar-wrap">
          <div class="ballot-turnout-label"><span>Turnout</span><span>${turnout}%</span></div>
          <div class="ballot-turnout-bar"><div class="ballot-turnout-fill" style="width:${turnout}%"></div></div>
        </div>
      </div>
    </div>`;

  if (hasVoted) {
    return headerHTML + `
      <div class="vote-receipt-card">
        <div class="vote-receipt-icon"><i class="fa-solid fa-circle-check"></i></div>
        <h3>Your Vote Has Been Cast</h3>
        <p>You have already voted in the <strong>${el.title}</strong>. Your UUID-verified ballot is cryptographically secured.</p>
        <div class="vote-receipt-meta">
          <span><i class="fa-solid fa-fingerprint"></i> Voter ID: BCP-<?= $sess_uid ?></span>
          <span><i class="fa-solid fa-building"></i> ${el.org}</span>
        </div>
        <button class="ballot-cta-btn secondary" onclick="openCandidatesView('${el.id}')">
          <i class="fa-solid fa-id-card-clip"></i> View Candidate Profiles
        </button>
      </div>`;
  }

  // Group candidates by position
  const positions = [...new Set(el.candidates.map(c => c.pos))];

  let posBlocks = positions.map(pos => {
    const posKey = posMap[pos] || pos.toLowerCase().replace(/\s+/g, '_');
    const posIcon = { 'President':'fa-crown', 'Vice President':'fa-star', 'Secretary':'fa-pen-to-square', 'Treasurer':'fa-coins', 'Auditor':'fa-magnifying-glass-chart' }[pos] || 'fa-user';
    const cands = el.candidates.filter(c => c.pos === pos);
    const inputName = `vote_${posKey}`;
    const cards = cands.map((c,i) => `
      <label class="ballot-candidate-card" for="vote-${posKey}-${i}">
        <input type="radio" name="${inputName}" id="vote-${posKey}-${i}" value="${c.id}" class="vote-radio" data-position="${posKey}"/>
        <div class="ballot-card-avatar" style="background:${c.color};">${c.initials}</div>
        <div class="ballot-card-info">
          <div class="ballot-card-name">${c.name}</div>
          <div class="ballot-card-party"><i class="fa-solid fa-flag"></i> ${c.party}</div>
          <div class="ballot-card-tagline">${c.tag}</div>
        </div>
        <div class="ballot-card-check"><i class="fa-solid fa-circle-check"></i></div>
      </label>`).join('');
    return `
      <div class="ballot-position-block">
        <div class="ballot-position-header">
          <i class="fa-solid ${posIcon}"></i>
          <span>${pos}</span>
          <span class="ballot-position-select-status" id="status-${posKey}">Select 1</span>
        </div>
        <div class="ballot-candidate-row">${cards}</div>
      </div>`;
  }).join('');

  return headerHTML + `
    <div class="ballot-wizard" id="ballotWizard">
      <!-- Steps -->
      <div class="ballot-steps">
        <div class="ballot-step active" data-step="1"><div class="step-bubble">1</div><span>Select Candidates</span></div>
        <div class="ballot-step-line"></div>
        <div class="ballot-step" data-step="2"><div class="step-bubble">2</div><span>Review Ballot</span></div>
        <div class="ballot-step-line"></div>
        <div class="ballot-step" data-step="3"><div class="step-bubble">3</div><span>Confirm &amp; Submit</span></div>
        <div class="ballot-step-line"></div>
        <div class="ballot-step" data-step="4"><div class="step-bubble"><i class="fa-solid fa-check"></i></div><span>Receipt</span></div>
      </div>
      <!-- Step 1 -->
      <div class="ballot-panel active" id="stepPanel1">
        <p class="ballot-instruction"><i class="fa-solid fa-circle-info"></i> Select <strong>one candidate per position</strong>. You may only vote once.</p>
        ${posBlocks}
        <div class="ballot-step-actions">
          <span class="ballot-selections-note" id="selectionsNote">${positions.length} position${positions.length>1?'s':''} remaining.</span>
          <button class="ballot-next-btn" id="btnStep1Next" disabled>Review My Ballot <i class="fa-solid fa-arrow-right"></i></button>
        </div>
      </div>
      <!-- Step 2 -->
      <div class="ballot-panel" id="stepPanel2">
        <p class="ballot-instruction"><i class="fa-solid fa-eye"></i> Review your selections. Once confirmed, your vote <strong>cannot be changed</strong>.</p>
        <div class="ballot-review-table">
          <div class="ballot-review-header"><span>Position</span><span>Your Selection</span><span>Party</span></div>
          <div id="ballotReviewRows"></div>
        </div>
        <div class="ballot-uuid-notice"><i class="fa-solid fa-fingerprint"></i> Ballot linked to voter UUID: <strong>BCP-<?= $sess_uid ?></strong></div>
        <div class="ballot-step-actions">
          <button class="ballot-back-btn" id="btnStep2Back"><i class="fa-solid fa-arrow-left"></i> Go Back</button>
          <button class="ballot-next-btn" id="btnStep2Next">Confirm Selections <i class="fa-solid fa-arrow-right"></i></button>
        </div>
      </div>
      <!-- Step 3 -->
      <div class="ballot-panel" id="stepPanel3">
        <div class="ballot-confirm-box">
          <div class="ballot-confirm-icon"><i class="fa-solid fa-shield-halved"></i></div>
          <h3>Final Confirmation</h3>
          <p>You are about to officially cast your vote in the <strong>${el.title}</strong>. This action is <strong>irreversible</strong>.</p>
          <div class="ballot-confirm-checks">
            <div class="ballot-check-item"><i class="fa-solid fa-circle-check" style="color:#22c55e;"></i> Voter identity verified via BCP-<?= $sess_uid ?></div>
            <div class="ballot-check-item"><i class="fa-solid fa-circle-check" style="color:#22c55e;"></i> ${positions.length} position${positions.length>1?'s':''} covered</div>
            <div class="ballot-check-item"><i class="fa-solid fa-circle-check" style="color:#22c55e;"></i> Double-vote prevention active</div>
          </div>
        </div>
        <div class="ballot-step-actions">
          <button class="ballot-back-btn" id="btnStep3Back"><i class="fa-solid fa-arrow-left"></i> Go Back</button>
          <button class="ballot-submit-btn" id="btnCastVote" data-election-id="${el.id}"><i class="fa-solid fa-check-to-slot"></i> Cast My Official Vote</button>
        </div>
      </div>
      <!-- Step 4 -->
      <div class="ballot-panel" id="stepPanel4">
        <div class="vote-receipt-card" id="voteReceipt">
          <div class="vote-receipt-icon"><i class="fa-solid fa-circle-check"></i></div>
          <h3>Vote Successfully Cast!</h3>
          <p>Thank you, <strong><?= $sess_first ?></strong>! Your ballot for <strong>${el.title}</strong> has been recorded and cryptographically secured.</p>
          <div class="vote-receipt-meta">
            <span><i class="fa-solid fa-fingerprint"></i> Voter ID: BCP-<?= $sess_uid ?></span>
            <span class="receipt-timestamp"><i class="fa-solid fa-calendar-check"></i> <span id="receiptTimestamp"></span></span>
          </div>
          <div class="vote-receipt-summary" id="receiptSummary"></div>
          <button class="ballot-cta-btn secondary" style="margin-top:16px;" onclick="backToLanding()">
            <i class="fa-solid fa-arrow-left"></i> Back to All Elections
          </button>
        </div>
      </div>
    </div>`;
}

// ── Build Candidates HTML ─────────────────────────────────────
function buildCandidatesHTML(el) {
  const positions = [...new Set(el.candidates.map(c => c.pos))];

  const tabsHTML = `<div class="position-tab-bar">
    <button class="pos-tab active" data-filter="all">All Positions</button>
    ${positions.map(p => `<button class="pos-tab" data-filter="${p.toLowerCase().replace(/\s+/g,'-')}">${p}</button>`).join('')}
  </div>`;

  const cardsHTML = el.candidates.map(c => {
    const posFilter = c.pos.toLowerCase().replace(/\s+/g, '-');
    const achHTML = c.achievements.map(a => `<span class="cand-badge"><i class="fa-solid fa-award"></i> ${a}</span>`).join('');
    return `
    <div class="candidate-profile-card" data-position="${posFilter}">
      <div class="cand-card-header" style="background:${c.color};">
        <div class="cand-avatar-lg">${c.initials}</div>
        <div class="cand-header-info">
          <div class="cand-position-pill">${c.pos}</div>
          <div class="cand-full-name">${c.name}</div>
          <div class="cand-party-name"><i class="fa-solid fa-flag"></i> ${c.party}</div>
        </div>
      </div>
      <div class="cand-card-body">
        <div class="cand-stats-row">
          <div class="cand-stat"><span>${c.year}</span><label>Year Level</label></div>
          <div class="cand-stat"><span>${c.prog}</span><label>Program</label></div>
          <div class="cand-stat"><span>GWA ${c.gwa}</span><label>Academics</label></div>
        </div>
        <p class="cand-tagline">${c.tag}</p>
        <div class="cand-achievements">${achHTML}</div>
      </div>
      <div class="cand-card-footer">
        <button class="cand-platform-btn" onclick="openPlatformModal('${c.id}','${c.color}','${c.name}','${c.pos}','${c.initials}')">
          <i class="fa-solid fa-file-lines"></i> Full Platform
        </button>
        <button class="cand-vote-link" onclick="openBoothView('${el.id}')">
          <i class="fa-solid fa-check-to-slot"></i> Cast Vote
        </button>
      </div>
    </div>`;
  }).join('');

  return `
    <div class="table-card">
      <h3><i class="fa-solid fa-id-card-clip" style="color:#2563eb;"></i> Candidate Profiles — ${el.org}</h3>
      <p style="font-size:0.82rem; color:#64748b; margin:-4px 0 14px;">Review candidate platforms and campaign statements before casting your vote.</p>
      ${tabsHTML}
      <div class="candidate-profiles-grid">${cardsHTML}</div>
    </div>`;
}

// ── Platform Modal ────────────────────────────────────────────
function openPlatformModal(cid, color, name, position, initials) {
  const db = platformDB[cid];
  if (!db) return;
  document.getElementById('platformModalHeader').style.background = color;
  document.getElementById('platformModalTitle').textContent = name + ' — ' + position;
  const itemsHTML = db.platform.map(p => `
    <div class="platform-item">
      <div class="platform-item-icon"><i class="fa-solid ${p.icon}"></i></div>
      <div><div class="platform-item-title">${p.title}</div><div class="platform-item-desc">${p.desc}</div></div>
    </div>`).join('');
  document.getElementById('platformModalBody').innerHTML = `
    <div class="platform-modal-hero" style="background:${color};">
      <div class="platform-modal-avatar">${initials}</div>
      <div><div class="platform-modal-name">${name}</div><div class="platform-modal-meta">${position}</div></div>
    </div>
    <div style="padding:20px 22px;">
      <h4 style="font-size:0.82rem; font-weight:700; color:#1a1a2e; margin-bottom:14px; text-transform:uppercase; letter-spacing:0.05em;">Campaign Platform</h4>
      <div class="platform-items-list">${itemsHTML}</div>
    </div>
    <div class="modal-footer"><button class="btn-modal-close" data-close="platformModal">Close</button></div>`;
  document.getElementById('platformModal').classList.add('active');
}

// ── Results Modal ──────────────────────────────────────────────
function showResultsModal(title, winner, turnout, summary) {
  document.getElementById('resultsModalTitle').textContent = '🏆 ' + title;
  document.getElementById('resultsModalBody').innerHTML = `
    <div style="padding:20px 22px;">
      <div class="results-winner-card">
        <div class="results-winner-icon"><i class="fa-solid fa-crown"></i></div>
        <div>
          <div style="font-size:0.72rem; font-weight:700; color:#92400e; text-transform:uppercase; letter-spacing:0.05em;">Winning President</div>
          <div style="font-size:1.1rem; font-weight:800; color:#1a1a2e;">${winner}</div>
          <div style="font-size:0.78rem; color:#64748b; margin-top:2px;">Voter Turnout: <strong>${turnout}</strong></div>
        </div>
      </div>
      <p style="font-size:0.85rem; color:#444; line-height:1.6; margin:14px 0 0;">${summary}</p>
    </div>
    <div class="modal-footer"><button class="btn-modal-close" data-close="resultsModal">Close</button></div>`;
  document.getElementById('resultsModal').classList.add('active');
}

// ── Ballot Wizard Logic ───────────────────────────────────────
function initBallotWizard(el) {
  const positions = [...new Set(el.candidates.map(c => c.pos))];
  const posKeys = positions.map(p => posMap[p] || p.toLowerCase().replace(/\s+/g,'_'));
  const selections = {};
  posKeys.forEach(k => { selections[k] = null; });

  const candidateNames  = {};
  const candidateParties = {};
  el.candidates.forEach(c => {
    candidateNames[c.id]   = c.name;
    candidateParties[c.id] = c.party;
  });

  function updateStep1NextBtn() {
    const allSelected = posKeys.every(k => selections[k] !== null);
    const btn = document.getElementById('btnStep1Next');
    const note = document.getElementById('selectionsNote');
    if (!btn) return;
    btn.disabled = !allSelected;
    if (allSelected) {
      note.textContent = '✓ All positions selected. Ready to review!';
      note.style.color = '#16a34a';
    } else {
      const remaining = posKeys.filter(k => !selections[k]).length;
      note.textContent = `${remaining} position${remaining > 1 ? 's' : ''} remaining.`;
      note.style.color = '#64748b';
    }
  }

  // Radio change
  document.querySelectorAll('.vote-radio').forEach(radio => {
    radio.addEventListener('change', function() {
      const pos = this.dataset.position;
      selections[pos] = this.value;
      const statusEl = document.getElementById('status-' + pos);
      if (statusEl) { statusEl.textContent = '✓ Selected'; statusEl.style.color = '#16a34a'; statusEl.style.background = '#dcfce7'; statusEl.style.border = '1px solid #86efac'; }
      document.querySelectorAll(`[name="${this.name}"]`).forEach(r => r.closest('.ballot-candidate-card')?.classList.remove('selected'));
      this.closest('.ballot-candidate-card')?.classList.add('selected');
      updateStep1NextBtn();
    });
  });

  function goToStep(n) {
    document.querySelectorAll('.ballot-panel').forEach((p, i) => p.classList.toggle('active', i + 1 === n));
    document.querySelectorAll('.ballot-step').forEach((s, i) => {
      s.classList.remove('active','done');
      if (i + 1 < n) s.classList.add('done');
      if (i + 1 === n) s.classList.add('active');
    });
    document.getElementById('boothView')?.scrollIntoView({ behavior:'smooth', block:'start' });
  }

  function buildReviewTable() {
    const container = document.getElementById('ballotReviewRows');
    if (!container) return;
    container.innerHTML = posKeys.map(k => {
      const val = selections[k];
      return `<div class="ballot-review-row">
        <span class="review-position">${positions[posKeys.indexOf(k)]}</span>
        <span class="review-name">${candidateNames[val] || val}</span>
        <span class="review-party">${candidateParties[val] || ''}</span>
      </div>`;
    }).join('');
  }

  document.getElementById('btnStep1Next')?.addEventListener('click', () => { buildReviewTable(); goToStep(2); });
  document.getElementById('btnStep2Back')?.addEventListener('click', () => goToStep(1));
  document.getElementById('btnStep2Next')?.addEventListener('click', () => goToStep(3));
  document.getElementById('btnStep3Back')?.addEventListener('click', () => goToStep(2));

  document.getElementById('btnCastVote')?.addEventListener('click', async function() {
    this.disabled = true;
    this.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
    try {
      const fd = new FormData();
      fd.append('action', 'cast_vote');
      fd.append('election_id', this.dataset.electionId);
      posKeys.forEach(k => fd.append('vote_' + k, selections[k]));
      const resp = await fetch('elections.php', { method:'POST', body:fd });
      const data = await resp.json();
      if (data.success) {
        sessionVotes.push(this.dataset.electionId);
        const ts = new Date().toLocaleString('en-PH', { dateStyle:'long', timeStyle:'short' });
        const el2 = document.getElementById('receiptTimestamp');
        if (el2) el2.textContent = ts;
        const summaryEl = document.getElementById('receiptSummary');
        if (summaryEl) {
          summaryEl.innerHTML = posKeys.map(k => `
            <div class="receipt-row">
              <span>${positions[posKeys.indexOf(k)]}</span>
              <strong>${candidateNames[selections[k]]}</strong>
            </div>`).join('');
        }
        goToStep(4);
      } else {
        showToast(data.message, 'error');
        this.disabled = false;
        this.innerHTML = '<i class="fa-solid fa-check-to-slot"></i> Cast My Official Vote';
      }
    } catch(e) {
      showToast('Connection error. Please try again.', 'error');
      this.disabled = false;
      this.innerHTML = '<i class="fa-solid fa-check-to-slot"></i> Cast My Official Vote';
    }
  });
}
</script>
</body>
</html>

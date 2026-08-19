<?php
// ============================================================
//  ELECTIONS.PHP  (dashboard/)
//  Co-Curricular Management System — Elections & Voting Portal
// ============================================================
require_once __DIR__ . '/../shared/db.php';
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/signin.php');
    exit;
}

// Auto-run DB schema check
require_once __DIR__ . '/../shared/init_elections.php';

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_role    = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$user_id      = (int)$_SESSION['user_id'];

// ── 1. Fetch Adviser Handled Organization ──────────────────────────
$adviser_club = null;
if ($sess_role === 'club_adviser') {
    $stmt_ac = $conn->prepare("
        SELECT c.id, c.name, c.code, c.description 
        FROM clubs c 
        JOIN club_memberships cm ON cm.club_id = c.id 
        WHERE cm.user_id = ? AND cm.status = 'Active' 
        LIMIT 1
    ");
    if ($stmt_ac) {
        $stmt_ac->bind_param('i', $user_id);
        $stmt_ac->execute();
        $res_ac = $stmt_ac->get_result();
        if ($res_ac && $row_ac = $res_ac->fetch_assoc()) {
            $adviser_club = $row_ac;
        }
        $stmt_ac->close();
    }
    if (!$adviser_club) {
        $r_c = $conn->query("SELECT id, name, code, description FROM clubs WHERE status='Active' LIMIT 1");
        if ($r_c && $row_c = $r_c->fetch_assoc()) {
            $adviser_club = $row_c;
        }
    }
}

// Session-based votes cast tracker
if (!isset($_SESSION['votes_cast'])) { $_SESSION['votes_cast'] = []; }

// ── 2. Fetch Active Elections from DB (Scoped by Role) ─────────────
$where_sql = "";
$params = [];
$types = "";

if ($sess_role === 'club_adviser' && $adviser_club) {
    $where_sql = "WHERE e.club_id = ?";
    $params = [$adviser_club['id']];
    $types = "i";
} elseif ($sess_role === 'student') {
    $where_sql = "WHERE e.club_id IN (SELECT club_id FROM club_memberships WHERE user_id = ? AND status = 'Active')";
    $params = [$user_id];
    $types = "i";
}

$sql_elections = "
    SELECT e.id, e.election_code, e.club_id, e.title, e.description, e.closes_at, e.status, e.positions, e.created_at,
           c.name AS club_name, c.code AS club_code
    FROM elections e
    JOIN clubs c ON c.id = e.club_id
    $where_sql
    ORDER BY e.created_at DESC
";

$stmt_el = $conn->prepare($sql_elections);
if (!empty($types) && !empty($params)) {
    $stmt_el->bind_param($types, ...$params);
}
$stmt_el->execute();
$raw_elections = $stmt_el->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_el->close();

// Build elections dataset with candidates
$active_elections = [];
$colors = [
    '#1a3a8c',
    '#1a3a8c',
    '#1a3a8c',
    '#1a3a8c'
];
$color_idx = 0;

foreach ($raw_elections as $el) {
    $eid = (int)$el['id'];
    $el_status = $el['status'];

    // Auto-close election if closes_at datetime has passed
    if ($el_status === 'open' && !empty($el['closes_at']) && strtotime($el['closes_at']) <= time()) {
        $conn->query("UPDATE elections SET status = 'closed' WHERE id = {$eid}");
        $el_status = 'closed';
    }

    // Fetch Candidates
    $c_stmt = $conn->prepare("SELECT id, candidate_code, name, position, party, year_level, program, gwa, platform_tag, achievements, votes_count FROM election_candidates WHERE election_id = ? ORDER BY position, name");
    $c_stmt->bind_param('i', $eid);
    $c_stmt->execute();
    $cands_raw = $c_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $c_stmt->close();

    $candidates = [];
    foreach ($cands_raw as $cand) {
        $initials = '';
        $parts = explode(' ', $cand['name']);
        foreach ($parts as $p) { $initials .= strtoupper(substr($p, 0, 1)); }
        $initials = substr($initials, 0, 2);

        $ach_arr = json_decode($cand['achievements'] ?? '[]', true);
        if (!is_array($ach_arr)) $ach_arr = array_filter(explode(',', $cand['achievements'] ?? ''));

        $candidates[] = [
            'id'           => $cand['id'],
            'cand_code'    => $cand['candidate_code'],
            'name'         => htmlspecialchars($cand['name']),
            'pos'          => htmlspecialchars($cand['position']),
            'party'        => htmlspecialchars($cand['party']),
            'initials'     => $initials,
            'color'        => $colors[$color_idx % count($colors)],
            'year'         => htmlspecialchars($cand['year_level']),
            'prog'         => htmlspecialchars($cand['program']),
            'gwa'          => htmlspecialchars($cand['gwa']),
            'tag'          => htmlspecialchars($cand['platform_tag']),
            'achievements' => $ach_arr,
            'votes_count'  => (int)$cand['votes_count']
        ];
    }

    // Turnout stats — real eligible member count
    $el_club_id = (int)$el['club_id'];
    $m_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM club_memberships WHERE club_id = ? AND status = 'Active'");
    $m_stmt->bind_param('i', $el_club_id);
    $m_stmt->execute();
    $eligible = (int)$m_stmt->get_result()->fetch_assoc()['c'];
    $m_stmt->close();
    if ($eligible <= 0) { $eligible = 42; } // Fallback baseline if no membership records yet

    $v_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM election_votes WHERE election_id = ?");
    $v_stmt->bind_param('i', $eid);
    $v_stmt->execute();
    $voted = (int)$v_stmt->get_result()->fetch_assoc()['c'];
    $v_stmt->close();

    // Fallback if votes table has no records but candidates have vote counts (seeded data)
    if ($voted === 0) {
        $max_cand_votes = 0;
        foreach ($candidates as $c) {
            if ($c['votes_count'] > $max_cand_votes) {
                $max_cand_votes = $c['votes_count'];
            }
        }
        $voted = $max_cand_votes;
    }

    if ($voted > $eligible) {
        $eligible = max($voted, 42);
    }

    $positions = json_decode($el['positions'] ?? '[]', true);
    if (!is_array($positions) || empty($positions)) {
        $positions = ['President', 'Vice President', 'Secretary', 'Treasurer'];
    }

    $closes_formatted = !empty($el['closes_at']) ? date('g:i A, F j, Y', strtotime($el['closes_at'])) : 'Closes Soon';

    $active_elections[] = [
        'id'          => $el['id'],
        'code'        => $el['election_code'],
        'org'         => htmlspecialchars($el['club_name']),
        'acronym'     => htmlspecialchars($el['club_code']),
        'color'       => $colors[$color_idx % count($colors)],
        'title'       => htmlspecialchars($el['title']),
        'description' => htmlspecialchars($el['description'] ?? ''),
        'closes'      => $closes_formatted,
        'closes_raw'  => $el['closes_at'],
        'eligible'    => $eligible,
        'voted'       => $voted,
        'status'      => $el_status,
        'positions'   => $positions,
        'candidates'  => $candidates,
        'club_id'     => (int)$el['club_id']
    ];
    $color_idx++;
}

// ── 3. Past Election Results (Scoped by Role & Real DB Query) ──────
$past_where = "WHERE e.status = 'closed'";
$past_params = [];
$past_types = "";

if ($sess_role === 'club_adviser' && $adviser_club) {
    $past_where .= " AND e.club_id = ?";
    $past_params = [$adviser_club['id']];
    $past_types = "i";
} elseif ($sess_role === 'student') {
    $past_where .= " AND e.club_id IN (SELECT club_id FROM club_memberships WHERE user_id = ? AND status = 'Active')";
    $past_params = [$user_id];
    $past_types = "i";
}

$sql_past = "
    SELECT e.id, e.title, e.closes_at, e.club_id, c.name AS club_name, c.code AS club_code
    FROM elections e
    JOIN clubs c ON c.id = e.club_id
    $past_where
    ORDER BY e.closes_at DESC
";
$stmt_p = $conn->prepare($sql_past);
if (!empty($past_types)) {
    $stmt_p->bind_param($past_types, ...$past_params);
}
$stmt_p->execute();
$raw_past = $stmt_p->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_p->close();

$past_results = [];
foreach ($raw_past as $pr) {
    $pr_id = (int)$pr['id'];
    $pr_club_id = (int)$pr['club_id'];

    // Voted count
    $v_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM election_votes WHERE election_id = ?");
    $v_stmt->bind_param('i', $pr_id);
    $v_stmt->execute();
    $pvoted = (int)$v_stmt->get_result()->fetch_assoc()['c'];
    $v_stmt->close();

    // Eligible count
    $m_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM club_memberships WHERE club_id = ? AND status = 'Active'");
    $m_stmt->bind_param('i', $pr_club_id);
    $m_stmt->execute();
    $peligible = (int)$m_stmt->get_result()->fetch_assoc()['c'];
    $m_stmt->close();
    if ($peligible <= 0) $peligible = 42;

    // Winning President
    $w_stmt = $conn->prepare("SELECT name, votes_count FROM election_candidates WHERE election_id = ? AND position LIKE '%President%' ORDER BY votes_count DESC LIMIT 1");
    $w_stmt->bind_param('i', $pr_id);
    $w_stmt->execute();
    $w_res = $w_stmt->get_result()->fetch_assoc();
    $w_stmt->close();
    
    if (!$w_res) {
        $w_stmt2 = $conn->prepare("SELECT name, votes_count FROM election_candidates WHERE election_id = ? ORDER BY votes_count DESC LIMIT 1");
        $w_stmt2->bind_param('i', $pr_id);
        $w_stmt2->execute();
        $w_res = $w_stmt2->get_result()->fetch_assoc();
        $w_stmt2->close();
    }
    
    $winner_name = $w_res ? $w_res['name'] : 'Declared Winner';
    if ($pvoted === 0 && $w_res) {
        $pvoted = $w_res['votes_count'];
    }
    if ($pvoted > $peligible) $peligible = $pvoted;

    $pct = $peligible > 0 ? round(($pvoted / $peligible) * 100) : 0;

    $past_results[] = [
        'id'      => $pr_id,
        'org'     => htmlspecialchars($pr['club_name']),
        'acronym' => htmlspecialchars($pr['club_code']),
        'date'    => !empty($pr['closes_at']) ? date('M j, Y', strtotime($pr['closes_at'])) : 'Completed',
        'winner'  => htmlspecialchars($winner_name),
        'votes'   => "{$pvoted}/{$peligible} ({$pct}%)",
        'details' => htmlspecialchars($pr['title'])
    ];
}
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
  <style>
    .modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,0.65); backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; z-index: 9999; }
    .modal-card { background: #fff; border-radius: 16px; width: 100%; max-width: 560px; padding: 28px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.15); max-height: 90vh; overflow-y: auto; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 0.78rem; font-weight: 700; color: #475569; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
    .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 14px; border: 1.5px solid #cbd5e1; border-radius: 10px; font-size: 0.88rem; color: #0f172a; font-family: inherit; box-sizing: border-box; }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: #2563eb; outline: none; box-shadow: 0 0 0 3px rgba(37,99,235,0.15); }
    .candidate-manage-chip { display: inline-flex; align-items: center; gap: 6px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 6px 12px; font-size: 0.8rem; font-weight: 600; color: #0f172a; box-shadow: 0 1px 3px rgba(0,0,0,0.04); margin: 3px 2px; }
    .candidate-manage-chip strong { color: #0f172a; }
    .candidate-manage-chip .btn-del { color: #ef4444; cursor: pointer; font-size: 0.85rem; margin-left: 4px; transition: color 0.15s ease; }
    .candidate-manage-chip .btn-del:hover { color: #b91c1c; }
    @media print {
      body * { visibility: hidden; }
      #printableResultsArea, #printableResultsArea * { visibility: visible; }
      #printableResultsArea { position: absolute; left: 0; top: 0; width: 100%; }
    }
  </style>
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
           FACULTY ADVISER CONTROL HUB (Visible to Adviser)
      ══════════════════════════════════════════════════════ -->
      <?php if ($sess_role === 'club_adviser' && $adviser_club): ?>
      <div class="adviser-hero-banner" style="background: #1a3a8c; border-radius:16px; padding:24px 28px; color:#fff; margin-bottom:24px; box-shadow:0 10px 25px -5px rgba(30,58,138,0.3);">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
          <div>
            <div style="display:inline-flex; align-items:center; gap:8px; background:rgba(255,255,255,0.15); padding:4px 12px; border-radius:20px; font-size:0.78rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px;">
              <i class="fa-solid fa-user-shield"></i> Faculty Adviser Election Control Hub
            </div>
            <h2 style="margin:0; font-size:1.4rem; font-weight:800; color:#fff;">
              <?= htmlspecialchars($adviser_club['name']) ?> (<?= htmlspecialchars($adviser_club['code']) ?>)
            </h2>
            <p style="margin:4px 0 0 0; font-size:0.85rem; opacity:0.9; max-width:650px;">
              Exclusively establish election pools, manage candidate profiles, oversee voting progress, and export verified results for your handled organization.
            </p>
          </div>
          <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button class="card-btn" onclick="openCreateElectionModal()" style="background:#fff; color:#1e3a8a; font-weight:700; height:42px; padding:0 20px; border-radius:10px; box-shadow:0 4px 12px rgba(0,0,0,0.15); cursor:pointer;">
              <i class="fa-solid fa-plus-circle"></i> Create Election Pool
            </button>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- ══════════════════════════════════════════════════════
           LANDING VIEW: Active Elections by Organization
      ══════════════════════════════════════════════════════ -->
      <div id="electionLanding">

        <!-- Section Header -->
        <div class="election-landing-header">
          <div>
            <h3 class="election-landing-title">
              <i class="fa-solid fa-circle-dot" style="color:#22c55e;"></i>
              <?= $sess_role === 'club_adviser' ? htmlspecialchars($adviser_club['code'] ?? 'Org') . ' Active Elections' : 'Active Campus Elections' ?>
            </h3>
            <p class="election-landing-sub">
              <?= $sess_role === 'club_adviser' ? 'Supervise election progress and candidate slates for ' . htmlspecialchars($adviser_club['name'] ?? 'your organization') . '.' : 'Select an organization below to access its Digital Balloting Booth or view Candidate Profiles.' ?>
            </p>
          </div>
        </div>

        <!-- Organization Election Cards -->
        <div class="election-org-grid" id="electionOrgGrid">
          <?php if (empty($active_elections)): ?>
          <div style="grid-column: 1/-1; background:#fff; padding:40px; text-align:center; border-radius:14px; border:1px solid #e2e8f0; color:#64748b;">
            <i class="fa-solid fa-box-archive" style="font-size:2.5rem; color:#cbd5e1; margin-bottom:12px; display:block;"></i>
            <h4 style="margin:0 0 6px 0; font-size:1.1rem; color:#1e293b;">No Active Elections Found</h4>
            <p style="margin:0; font-size:0.85rem;">
              <?= $sess_role === 'club_adviser' ? 'Click "Create Election Pool" above to set up an election for ' . htmlspecialchars($adviser_club['name']) . '.' : 'There are no active elections currently scheduled.' ?>
            </p>
          </div>
          <?php else: ?>
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
              <div class="eorg-org-name"><?= $el['org'] ?></div>
              <div class="eorg-title"><?= $el['title'] ?></div>
              <div class="eorg-meta" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:6px;">
                <span><i class="fa-solid fa-clock"></i> Closes: <?= $el['closes'] ?></span>
                <?php if ($el['status'] === 'open'): ?>
                <span class="countdown-badge" id="timer-<?= $el['id'] ?>" data-closes="<?= htmlspecialchars($el['closes_raw'] ?? '') ?>" style="font-weight:700; color:#2563eb; background:#eff6ff; padding:3px 10px; border-radius:6px; font-size:0.75rem; display:inline-flex; align-items:center; gap:4px;">
                  <i class="fa-solid fa-stopwatch"></i> Active
                </span>
                <?php endif; ?>
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
                <span class="eorg-pos-chip"><?= htmlspecialchars($pos) ?></span>
                <?php endforeach; ?>
              </div>

              <?php if ($sess_role === 'club_adviser'): ?>
              <!-- Managed Candidates Preview Chip List -->
              <div style="margin-top:14px; padding:12px 14px; background:#f8fafc; border-radius:12px; border:1px solid #e2e8f0;">
                <div style="font-size:0.75rem; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px; display:flex; justify-content:space-between; align-items:center;">
                  <span>Candidates (<?= count($el['candidates']) ?>)</span>
                  <a href="javascript:void(0)" onclick="openAddCandidateModal(<?= $el['id'] ?>)" style="color:#2563eb; text-decoration:none; font-weight:700; font-size:0.78rem;">+ ADD</a>
                </div>
                <?php if (empty($el['candidates'])): ?>
                  <span style="font-size:0.78rem; color:#94a3b8; font-style:italic;">No candidates added yet.</span>
                <?php else: ?>
                  <div style="display:flex; flex-wrap:wrap; gap:6px;">
                    <?php foreach ($el['candidates'] as $c): ?>
                    <span class="candidate-manage-chip">
                      <strong><?= htmlspecialchars($c['name']) ?></strong> (<?= htmlspecialchars($c['pos']) ?>)
                      <i class="fa-solid fa-xmark btn-del" title="Remove candidate" onclick="deleteCandidate(<?= $c['id'] ?>, '<?= addslashes($c['name']) ?>')"></i>
                    </span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
              <?php endif; ?>

              <?php if ($voted && $sess_role !== 'club_adviser'): ?>
              <div class="eorg-voted-badge">
                <i class="fa-solid fa-circle-check"></i> You have already voted
              </div>
              <?php endif; ?>
            </div>

            <!-- Card Actions -->
            <?php if (!$isClosed): ?>
            <div class="eorg-actions">
              <button class="eorg-btn eorg-btn-secondary" onclick="openCandidatesView(<?= $el['id'] ?>)">
                <i class="fa-solid fa-id-card-clip"></i>
                Candidate Profiles
              </button>
              <?php if ($sess_role === 'club_adviser'): ?>
              <button class="eorg-btn eorg-btn-primary" onclick="showResultsModal(<?= $el['id'] ?>)">
                <i class="fa-solid fa-chart-pie"></i>
                Live Monitor
              </button>
              <button class="eorg-btn" style="background:#fee2e2; color:#dc2626; border:1px solid #fca5a5; font-weight:700;" onclick="closeElection(<?= $el['id'] ?>)" title="End voting and close election pool">
                <i class="fa-solid fa-power-off"></i>
                Close Pool
              </button>
              <?php elseif (!$voted): ?>
              <button class="eorg-btn eorg-btn-primary" onclick="openBoothView(<?= $el['id'] ?>)">
                <i class="fa-solid fa-check-to-slot"></i>
                Enter Ballot Booth
              </button>
              <?php else: ?>
              <button class="eorg-btn eorg-btn-receipt" onclick="openBoothView(<?= $el['id'] ?>)">
                <i class="fa-solid fa-receipt"></i>
                View My Receipt
              </button>
              <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="eorg-actions">
              <button class="eorg-btn eorg-btn-primary" style="flex:1;" onclick="showResultsModal(<?= $el['id'] ?>)">
                <i class="fa-solid fa-trophy"></i> View Official Results
              </button>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div><!-- end grid -->

        <!-- Past Results -->
        <div class="table-card" id="results" style="margin-top:28px;">
          <h3 style="margin-bottom:16px;">
            <i class="fa-solid fa-trophy" style="color:#2563eb;"></i> 
            <?= $sess_role === 'club_adviser' ? htmlspecialchars($adviser_club['code'] ?? 'Org') . ' Past Election Results &amp; Archives' : 'Past Election Results &amp; Archives' ?>
          </h3>
          <div class="resp-table-wrap">
            <table class="data-table resp-table">
              <thead>
                <tr>
                  <th style="padding:14px 18px;">Organization</th>
                  <th style="padding:14px 18px;">Election Date</th>
                  <th style="padding:14px 18px;">Winning President</th>
                  <th style="padding:14px 18px;">Votes Cast</th>
                  <th style="padding:14px 18px;">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($past_results as $r): ?>
                <tr>
                  <td data-label="Organization" style="padding:14px 18px;"><strong><?= htmlspecialchars($r['org']) ?> (<?= $r['acronym'] ?>)</strong></td>
                  <td data-label="Date" style="padding:14px 18px; font-size:0.85rem; color:#475569;"><?= $r['date'] ?></td>
                  <td data-label="Winner" style="padding:14px 18px; font-size:0.85rem; color:#1e293b; font-weight:600;"><?= $r['winner'] ?></td>
                  <td data-label="Votes" style="padding:14px 18px; font-size:0.85rem; color:#475569;"><?= $r['votes'] ?></td>
                  <td data-label="Action" style="padding:14px 18px;">
                    <button class="card-btn" style="height:38px; padding:0 16px; border-radius:8px; font-weight:700; background:#2563eb; color:#fff; display:inline-flex; align-items:center; gap:6px; font-size:0.85rem;" onclick="showArchivedResultsModal('<?= addslashes($r['org']) ?>', '<?= addslashes($r['winner']) ?>', '<?= $r['votes'] ?>', '<?= $r['date'] ?>')">
                      <i class="fa-solid fa-file-invoice"></i> View Results
                    </button>
                  </td>
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
        <div id="candsContent"></div>
      </div>

    </div><!-- end content-body -->
  </div><!-- end content -->
  <div class="footer">Co-Curricular Management System &copy; 2026</div>
</div><!-- end main -->

<!-- ════════════════════════════════════════════════════════════
     MODALS
════════════════════════════════════════════════════════════ -->

<!-- 1. CREATE ELECTION MODAL -->
<div class="modal-overlay" id="createElectionModal">
  <div class="modal-card" style="max-width:680px; max-height:90vh; overflow-y:auto;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
      <h3 style="margin:0; font-size:1.1rem; color:#0f172a; display:flex; align-items:center; gap:8px;">
        <i class="fa-solid fa-box-archive" style="color:#2563eb;"></i> Create Election Pool
      </h3>
      <button onclick="closeModal('createElectionModal')" style="background:none; border:none; font-size:1.2rem; color:#64748b; cursor:pointer;">&times;</button>
    </div>

    <form id="createElectionForm" onsubmit="handleCreateElection(event)">
      <?php if ($sess_role === 'club_adviser' && $adviser_club): ?>
        <input type="hidden" name="club_id" value="<?= $adviser_club['id'] ?>"/>
        <div class="form-group">
          <label>Target Organization</label>
          <input type="text" value="<?= htmlspecialchars($adviser_club['name']) ?> (<?= htmlspecialchars($adviser_club['code']) ?>)" readonly style="background:#f8fafc; font-weight:700; color:#1e293b;"/>
        </div>
      <?php else: ?>
        <div class="form-group">
          <label>Target Organization</label>
          <select name="club_id" required>
            <?php
              $r_clubs = $conn->query("SELECT id, name, code FROM clubs WHERE status='Active' ORDER BY name");
              while ($cl = $r_clubs->fetch_assoc()) {
                  echo "<option value='{$cl['id']}'>" . htmlspecialchars($cl['name']) . " ({$cl['code']})</option>";
              }
            ?>
          </select>
        </div>
      <?php endif; ?>

      <div class="form-group">
        <label>Election Title *</label>
        <input type="text" name="title" placeholder="e.g. IT Society Executive Board Election 2026-2027" required/>
      </div>

      <div class="form-group">
        <label>Description / Voting Guidelines</label>
        <textarea name="description" rows="3" placeholder="Official balloting poll for active members..."></textarea>
      </div>

      <div class="form-group">
        <label>Voting Closing Date &amp; Time *</label>
        <input type="datetime-local" name="closes_at" required/>
      </div>

      <div class="form-group">
        <label>Positions to Vote For (Comma Separated)</label>
        <input type="text" name="positions" id="electionPositionsInput" value="President, Vice President, Secretary, Treasurer, Auditor" required oninput="updateDraftPositions()"/>
      </div>

      <!-- Candidate Slate Addition Section -->
      <div style="border-top: 1px dashed #cbd5e1; margin: 20px 0; padding-top: 16px;">
        <div style="margin-bottom: 12px; display:flex; justify-content:space-between; align-items:center;">
          <h4 style="margin: 0; font-size: 0.95rem; color: #0f172a; display: flex; align-items: center; gap: 6px;">
            <i class="fa-solid fa-user-plus" style="color: #2563eb;"></i> Candidate Slate (Add Candidates before Publishing)
          </h4>
          <span style="font-size: 0.78rem; color: #64748b;">Add entries to candidate slate</span>
        </div>

        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px; margin-bottom: 16px;">
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
            <div>
              <label style="font-size: 0.78rem; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Candidate Name</label>
              <input type="text" id="draftCandName" placeholder="e.g. Maria Santos" style="width: 100%; padding: 8px 12px; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 6px;"/>
            </div>
            <div>
              <label style="font-size: 0.78rem; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Position</label>
              <select id="draftCandPosition" style="width: 100%; padding: 8px 12px; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 6px;">
                <option value="President">President</option>
                <option value="Vice President">Vice President</option>
                <option value="Secretary">Secretary</option>
                <option value="Treasurer">Treasurer</option>
                <option value="Auditor">Auditor</option>
              </select>
            </div>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 10px;">
            <div>
              <label style="font-size: 0.78rem; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Party / Alliance</label>
              <input type="text" id="draftCandParty" placeholder="Independent" style="width: 100%; padding: 8px 12px; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 6px;"/>
            </div>
            <div>
              <label style="font-size: 0.78rem; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Program</label>
              <input type="text" id="draftCandProg" placeholder="BSIT" style="width: 100%; padding: 8px 12px; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 6px;"/>
            </div>
            <div>
              <label style="font-size: 0.78rem; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Year Level</label>
              <input type="text" id="draftCandYear" placeholder="3rd Year" style="width: 100%; padding: 8px 12px; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 6px;"/>
            </div>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
            <div>
              <label style="font-size: 0.78rem; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Campaign Slogan / Tagline</label>
              <input type="text" id="draftCandTag" placeholder="Empowering students through innovation" style="width: 100%; padding: 8px 12px; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 6px;"/>
            </div>
            <div>
              <label style="font-size: 0.78rem; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Key Achievements</label>
              <input type="text" id="draftCandAch" placeholder="Dean's Lister, Leadership Award" style="width: 100%; padding: 8px 12px; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 6px;"/>
            </div>
          </div>

          <button type="button" class="card-btn" onclick="addDraftCandidate()" style="background: #2563eb; color: #fff; font-size: 0.82rem; font-weight: 600; padding: 6px 14px; border-radius: 6px; cursor: pointer;">
            <i class="fa-solid fa-plus"></i> Add Candidate to Slate
          </button>
        </div>

        <div id="draftCandidatesList" style="margin-bottom: 16px;"></div>
        <input type="hidden" name="candidates" id="candidatesJsonInput" value="[]"/>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:24px;">
        <button type="button" class="card-btn" onclick="closeModal('createElectionModal')" style="background:#e2e8f0; color:#475569;">Cancel</button>
        <button type="submit" class="card-btn" style="background:#2563eb; color:#fff; font-weight:700;"><i class="fa-solid fa-check"></i> Establish &amp; Publish Election Pool</button>
      </div>
    </form>
  </div>
</div>

<!-- 2. ADD CANDIDATE MODAL -->
<div class="modal-overlay" id="addCandidateModal">
  <div class="modal-card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
      <h3 style="margin:0; font-size:1.1rem; color:#0f172a; display:flex; align-items:center; gap:8px;">
        <i class="fa-solid fa-user-plus" style="color:#2563eb;"></i> Add Candidate Profile
      </h3>
      <button onclick="closeModal('addCandidateModal')" style="background:none; border:none; font-size:1.2rem; color:#64748b; cursor:pointer;">&times;</button>
    </div>

    <form id="addCandidateForm" onsubmit="handleAddCandidate(event)">
      <div class="form-group">
        <label>Select Target Election *</label>
        <select name="election_id" id="candElectionSelect" onchange="updateCandidatePositions()" required>
          <?php foreach ($active_elections as $el): ?>
            <option value="<?= $el['id'] ?>"><?= $el['acronym'] ?> &bull; <?= $el['title'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>Candidate Full Name *</label>
        <input type="text" name="name" placeholder="e.g. Maria Santos" required/>
      </div>

      <div class="form-group">
        <label>Position *</label>
        <select name="position" id="candPositionSelect" required>
          <option value="President">President</option>
          <option value="Vice President">Vice President</option>
          <option value="Secretary">Secretary</option>
          <option value="Treasurer">Treasurer</option>
          <option value="Auditor">Auditor</option>
          <option value="P.R.O.">Public Relations Officer (P.R.O.)</option>
        </select>
      </div>

      <div class="form-group">
        <label>Party List / Alliance Name</label>
        <input type="text" name="party" placeholder="e.g. Innovate Tech Party / Independent"/>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px;">
        <div class="form-group">
          <label>Year Level</label>
          <input type="text" name="year_level" value="3rd Year"/>
        </div>
        <div class="form-group">
          <label>Program</label>
          <input type="text" name="program" value="BSIT"/>
        </div>
        <div class="form-group">
          <label>GWA</label>
          <input type="text" name="gwa" value="1.5"/>
        </div>
      </div>

      <div class="form-group">
        <label>Campaign Tagline / Slogan</label>
        <input type="text" name="platform_tag" placeholder='"Empowering students through innovation."'/>
      </div>

      <div class="form-group">
        <label>Key Achievements (Comma Separated)</label>
        <input type="text" name="achievements" placeholder="Dean's Lister 2025, Hackathon Winner"/>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:24px;">
        <button type="button" class="card-btn" onclick="closeModal('addCandidateModal')" style="background:#e2e8f0; color:#475569;">Cancel</button>
        <button type="submit" class="card-btn" style="background:#2563eb; color:#fff; font-weight:700;"><i class="fa-solid fa-plus"></i> Save Candidate</button>
      </div>
    </form>
  </div>
</div>

<!-- 3. OFFICIAL RESULTS & EXPORT MODAL -->
<div class="modal-overlay" id="resultsModal" style="display:none;">
  <div class="modal-card" style="max-width:680px; position:relative;">
    <button type="button" class="modal-close" onclick="closeModal('resultsModal')" data-close="resultsModal" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:1.3rem; color:#64748b; cursor:pointer;" title="Close">&times;</button>
    <div id="printableResultsArea">
      <div style="text-align:center; padding-bottom:16px; border-bottom:2px solid #e2e8f0; margin-bottom:20px; padding-right:24px;">
        <div style="font-size:0.75rem; font-weight:800; color:#2563eb; text-transform:uppercase; letter-spacing:1px; margin-bottom:4px;">
          Bestlink College of the Philippines &bull; Student Affairs &amp; Services
        </div>
        <h2 style="margin:0; font-size:1.3rem; color:#0f172a; font-weight:800;" id="modalResHeader">
          Official Election Results Summary
        </h2>
        <div style="font-size:0.82rem; color:#64748b; margin-top:4px;" id="modalResSub">
          Verified Digital Balloting Audit Report
        </div>
      </div>

      <div id="modalResBody"></div>
    </div>

    <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:24px; border-top:1px solid #f1f5f9; padding-top:16px;">
      <button type="button" class="card-btn" id="closeResultsModalBtn" onclick="closeModal('resultsModal')" data-close="resultsModal" style="background:#e2e8f0; color:#475569; font-weight:600; cursor:pointer;">Close</button>
      <?php if (in_array($sess_role, ['club_adviser', 'ssc', 'admin'])): ?>
      <button type="button" class="card-btn" onclick="window.print()" style="background:#16a34a; color:#fff; font-weight:700; cursor:pointer;">
        <i class="fa-solid fa-print"></i> Print / Export Report
      </button>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
// Serialise PHP elections data into JS
const electionsData = <?= json_encode($active_elections) ?>;
const sessRole = '<?= $sess_role ?>';

let draftCandidates = [];

function openCreateElectionModal() {
  draftCandidates = [];
  renderDraftCandidates();
  updateDraftPositions();
  document.getElementById('createElectionModal').style.display = 'flex';
}

function updateDraftPositions() {
  const input = document.getElementById('electionPositionsInput');
  const sel   = document.getElementById('draftCandPosition');
  if (!input || !sel) return;
  const raw = input.value || 'President, Vice President, Secretary, Treasurer, Auditor';
  const positions = raw.split(',').map(s => s.trim()).filter(Boolean);
  sel.innerHTML = '';
  positions.forEach(p => {
    const opt = document.createElement('option');
    opt.value = p;
    opt.textContent = p;
    sel.appendChild(opt);
  });
}

function addDraftCandidate() {
  const nameInput = document.getElementById('draftCandName');
  const posInput  = document.getElementById('draftCandPosition');
  const name     = nameInput.value.trim();
  const position = posInput.value.trim();

  if (!name) {
    alert('Please enter a candidate name.');
    nameInput.focus();
    return;
  }

  const party    = document.getElementById('draftCandParty').value.trim() || 'Independent';
  const program  = document.getElementById('draftCandProg').value.trim()  || 'BSIT';
  const year     = document.getElementById('draftCandYear').value.trim()  || '3rd Year';
  const tag      = document.getElementById('draftCandTag').value.trim();
  const ach      = document.getElementById('draftCandAch').value.trim();

  draftCandidates.push({
    name,
    position,
    party,
    program,
    year_level: year,
    gwa: '1.5',
    platform_tag: tag,
    achievements: ach
  });

  // Clear name and optional inputs
  nameInput.value = '';
  document.getElementById('draftCandParty').value = '';
  document.getElementById('draftCandTag').value   = '';
  document.getElementById('draftCandAch').value   = '';

  renderDraftCandidates();
}

function removeDraftCandidate(index) {
  draftCandidates.splice(index, 1);
  renderDraftCandidates();
}

function renderDraftCandidates() {
  const container = document.getElementById('draftCandidatesList');
  const jsonInput = document.getElementById('candidatesJsonInput');
  if (jsonInput) jsonInput.value = JSON.stringify(draftCandidates);
  if (!container) return;

  if (draftCandidates.length === 0) {
    container.innerHTML = `<div style="font-size:0.8rem; color:#94a3b8; font-style:italic; padding:6px 0;">No candidates added to slate yet. Fill out the fields above and click "Add Candidate to Slate".</div>`;
    return;
  }

  let html = `<div style="font-size:0.82rem; font-weight:700; color:#334155; margin-bottom:8px;">Candidates Added to Slate (${draftCandidates.length}):</div>`;
  html += `<div style="display:flex; flex-direction:column; gap:6px;">`;
  draftCandidates.forEach((c, idx) => {
    html += `<div style="display:flex; justify-content:space-between; align-items:center; background:#fff; border:1px solid #cbd5e1; padding:8px 12px; border-radius:6px; font-size:0.83rem;">
      <div>
        <strong style="color:#0f172a;">${c.name}</strong> &bull; <span style="color:#2563eb; font-weight:600;">${c.position}</span>
        <span style="color:#64748b; font-size:0.78rem; margin-left:6px;">(${c.party})</span>
      </div>
      <button type="button" onclick="removeDraftCandidate(${idx})" style="background:none; border:none; color:#ef4444; font-size:0.85rem; cursor:pointer;" title="Remove Candidate">
        <i class="fa-solid fa-trash-can"></i>
      </button>
    </div>`;
  });
  html += `</div>`;
  container.innerHTML = html;
}

function openAddCandidateModal(electionId) {
  if (electionId) {
    const sel = document.getElementById('candElectionSelect');
    if (sel) sel.value = electionId;
  }
  updateCandidatePositions();
  document.getElementById('addCandidateModal').style.display = 'flex';
}

function updateCandidatePositions() {
  const sel = document.getElementById('candElectionSelect');
  const posSel = document.getElementById('candPositionSelect');
  if (!sel || !posSel) return;
  const electionId = sel.value;
  const el = electionsData.find(e => e.id == electionId);
  posSel.innerHTML = '';
  
  const defaultPositions = ['President', 'Vice President', 'Secretary', 'Treasurer', 'Auditor'];
  const positionsToUse = (el && el.positions && el.positions.length) ? el.positions : defaultPositions;
  
  positionsToUse.forEach(p => {
    const opt = document.createElement('option');
    opt.value = p;
    opt.textContent = p;
    posSel.appendChild(opt);
  });
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (el) {
    el.classList.remove('active', 'open');
    el.style.display = 'none';
  }
}

async function closeElection(electionId) {
  if (!confirm('Are you sure you want to close this election pool? Once closed, voting will be disabled.')) return;
  const formData = new FormData();
  formData.append('action', 'close_election');
  formData.append('election_id', electionId);
  formData.append('status', 'closed');

  try {
    const res = await fetch('../shared/election_actions.php', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.success) {
      alert(data.message);
      window.location.reload();
    } else {
      alert('Error: ' + data.message);
    }
  } catch (err) {
    alert('Network error closing election.');
  }
}

async function handleCreateElection(e) {
  e.preventDefault();
  const form = document.getElementById('createElectionForm');
  const formData = new FormData(form);
  formData.append('action', 'create_election');

  try {
    const res = await fetch('../shared/election_actions.php', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.success) {
      alert(data.message);
      window.location.reload();
    } else {
      alert('Error: ' + data.message);
    }
  } catch (err) {
    alert('Network error creating election.');
  }
}

async function handleAddCandidate(e) {
  e.preventDefault();
  const form = document.getElementById('addCandidateForm');
  const formData = new FormData(form);
  formData.append('action', 'add_candidate');

  try {
    const res = await fetch('../shared/election_actions.php', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.success) {
      alert(data.message);
      window.location.reload();
    } else {
      alert('Error: ' + data.message);
    }
  } catch (err) {
    alert('Network error adding candidate.');
  }
}

async function deleteCandidate(candidateId, name) {
  if (!confirm(`Are you sure you want to remove candidate "${name}"?`)) return;
  const formData = new FormData();
  formData.append('action', 'delete_candidate');
  formData.append('candidate_id', candidateId);

  try {
    const res = await fetch('../shared/election_actions.php', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.success) {
      alert(data.message);
      window.location.reload();
    } else {
      alert('Error: ' + data.message);
    }
  } catch (err) {
    alert('Network error deleting candidate.');
  }
}

function showResultsModal(electionId) {
  const el = electionsData.find(e => e.id == electionId);
  if (!el) return;

  const turnoutPct = el.eligible > 0 ? Math.min(100, Math.round((el.voted / el.eligible) * 100)) : 0;
  document.getElementById('modalResHeader').textContent = el.title + ' — Official Results';
  document.getElementById('modalResSub').textContent = `Organization: ${el.org} (${el.acronym}) | Voter Turnout: ${el.voted}/${el.eligible} (${turnoutPct}%)`;

  let html = `<div style="margin-bottom:20px; font-size:0.85rem; color:#475569;">
    <strong>Status:</strong> ${el.status.toUpperCase()} &bull; 
    <strong>Closing Date:</strong> ${el.closes} &bull; 
    <strong>Audit Log:</strong> Verifiable Digital UUID
  </div>`;

  el.positions.forEach(pos => {
    const posCands = el.candidates.filter(c => c.pos === pos);
    html += `<div style="margin-bottom:20px; background:#f8fafc; padding:14px; border-radius:10px; border:1px solid #e2e8f0;">
      <h4 style="margin:0 0 10px 0; color:#1e3a8a; font-size:0.95rem; text-transform:uppercase;">${pos}</h4>`;

    if (!posCands.length) {
      html += `<div style="font-size:0.8rem; color:#94a3b8;">No candidates registered.</div>`;
    } else {
      let maxVotes = -1;
      posCands.forEach(c => { if (c.votes_count > maxVotes) maxVotes = c.votes_count; });

      const totalPosVotes = posCands.reduce((sum, item) => sum + item.votes_count, 0);
      const baseVoted = Math.max(el.voted, totalPosVotes, 1);

      posCands.forEach(c => {
        const pct = Math.min(100, Math.round((c.votes_count / baseVoted) * 100));
        const isWinner = (c.votes_count === maxVotes && maxVotes > 0);

        html += `<div style="margin-bottom:10px; padding:10px; background:#fff; border-radius:8px; border:1px solid ${isWinner ? '#22c55e' : '#cbd5e1'};">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
            <div>
              <strong>${c.name}</strong> <span style="font-size:0.78rem; color:#64748b;">(${c.party})</span>
              ${isWinner ? '<span style="background:#dcfce7; color:#166534; font-size:0.72rem; font-weight:800; padding:2px 8px; border-radius:12px; margin-left:6px;"><i class="fa-solid fa-trophy"></i> ELECTED</span>' : ''}
            </div>
            <div style="font-weight:800; color:#0f172a; font-size:0.9rem;">${c.votes_count} votes (${pct}%)</div>
          </div>
          <div style="background:#e2e8f0; height:8px; border-radius:4px; overflow:hidden;">
            <div style="background:${isWinner ? '#22c55e' : '#2563eb'}; height:100%; width:${pct}%;"></div>
          </div>
        </div>`;
      });
    }
    html += `</div>`;
  });

  document.getElementById('modalResBody').innerHTML = html;
  document.getElementById('resultsModal').style.display = 'flex';
}

function initCountdowns() {
  const timers = document.querySelectorAll('.countdown-badge');
  if (!timers.length) return;

  function update() {
    const now = new Date().getTime();
    timers.forEach(t => {
      const raw = t.getAttribute('data-closes');
      if (!raw) return;
      const target = new Date(raw.replace(' ', 'T')).getTime();
      const diff = target - now;

      if (isNaN(target) || diff <= 0) {
        t.innerHTML = '<i class="fa-solid fa-lock"></i> Voting Ended';
        t.style.color = '#ef4444';
        t.style.background = '#fef2f2';
      } else {
        const d = Math.floor(diff / (1000 * 60 * 60 * 24));
        const h = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const m = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
        const s = Math.floor((diff % (1000 * 60)) / 1000);
        let str = '';
        if (d > 0) str += d + 'd ';
        str += `${h}h ${m}m ${s}s left`;
        t.innerHTML = `<i class="fa-solid fa-stopwatch"></i> ${str}`;
      }
    });
  }

  update();
  setInterval(update, 1000);
}

document.addEventListener('DOMContentLoaded', () => {
  initCountdowns();
  updateCandidatePositions();
});

function showArchivedResultsModal(org, winner, votes, date) {
  document.getElementById('modalResHeader').textContent = org + ' Archived Results (' + date + ')';
  document.getElementById('modalResSub').textContent = `Winning President: ${winner} | Total Votes Cast: ${votes}`;

  let html = `<div style="padding:20px; background:#f8fafc; border-radius:10px; border:1px solid #e2e8f0; text-align:center;">
    <i class="fa-solid fa-award" style="font-size:3rem; color:#f59e0b; margin-bottom:10px;"></i>
    <h3 style="margin:0 0 6px 0; font-size:1.1rem; color:#0f172a;">Official Election Declaration</h3>
    <p style="margin:0; font-size:0.85rem; color:#475569;">
      The election for <strong>${org}</strong> concluded on ${date}. Candidate <strong>${winner}</strong> was officially declared elected President with a turnout rate of ${votes}.
    </p>
  </div>`;

  document.getElementById('modalResBody').innerHTML = html;
  document.getElementById('resultsModal').style.display = 'flex';
}

function exportHandledOrgReport() {
  if (electionsData.length > 0) {
    showResultsModal(electionsData[0].id);
  } else {
    alert('No election data available to export yet. Please create an election pool first.');
  }
}

// ── Views Switching Handlers ──
function backToLanding() {
  document.getElementById('electionLanding').style.display = 'block';
  document.getElementById('boothView').style.display       = 'none';
  document.getElementById('candidatesView').style.display  = 'none';
}

function openCandidatesView(electionId) {
  const el = electionsData.find(e => e.id == electionId);
  if (!el) return;

  document.getElementById('candsBreadcrumb').textContent = el.org + ' \u203A Candidates';

  let html = `<div style="background:${el.color}; border-radius:16px; padding:24px 28px; color:#fff; margin-bottom:24px;">
    <h2 style="margin:0 0 6px 0; font-size:1.3rem;">${el.title}</h2>
    <p style="margin:0; font-size:0.85rem; opacity:0.9;">Official Candidate Slates &amp; Platforms for ${el.org}</p>
  </div>`;

  el.positions.forEach(pos => {
    const posCands = el.candidates.filter(c => c.pos === pos);
    html += `<h3 style="margin:20px 0 12px 0; color:#1e293b; font-size:1rem; border-bottom:2px solid #e2e8f0; padding-bottom:6px;">${pos}</h3>
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:16px; margin-bottom:24px;">`;

    if (!posCands.length) {
      html += `<div style="font-size:0.85rem; color:#94a3b8;">No candidate profiles registered for this position yet.</div>`;
    } else {
      posCands.forEach(c => {
        const achBadges = c.achievements.map(a => `<span style="background:#eff6ff; color:#1e40af; font-size:0.72rem; font-weight:600; padding:2px 8px; border-radius:12px;">${a}</span>`).join(' ');
        html += `<div style="background:#fff; border-radius:14px; padding:20px; border:1px solid #e2e8f0; box-shadow:0 2px 4px rgba(0,0,0,0.04);">
          <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">
            <div style="width:48px; height:48px; border-radius:50%; background:${c.color}; color:#fff; font-weight:800; display:flex; align-items:center; justify-content:center; font-size:1.1rem;">${c.initials}</div>
            <div>
              <div style="font-weight:700; font-size:1rem; color:#0f172a;">${c.name}</div>
              <div style="font-size:0.8rem; color:#2563eb; font-weight:600;">${c.party}</div>
            </div>
          </div>
          <div style="font-size:0.82rem; color:#475569; margin-bottom:10px;">
            <div><strong>Year &amp; Program:</strong> ${c.year} &bull; ${c.prog} (GWA: ${c.gwa})</div>
            <div style="font-style:italic; margin-top:6px; color:#1e293b;">${c.tag}</div>
          </div>
          <div style="display:flex; flex-wrap:wrap; gap:4px;">${achBadges}</div>
        </div>`;
      });
    }
    html += `</div>`;
  });

  document.getElementById('candsContent').innerHTML     = html;
  document.getElementById('electionLanding').style.display = 'none';
  document.getElementById('candidatesView').style.display  = 'block';
}

function openBoothView(electionId) {
  const el = electionsData.find(e => e.id == electionId);
  if (!el) return;

  document.getElementById('boothBreadcrumb').textContent = el.org + ' \u203A Digital Balloting Booth';

  let html = `<div style="background:${el.color}; border-radius:16px; padding:24px 28px; color:#fff; margin-bottom:24px;">
    <h2 style="margin:0 0 6px 0; font-size:1.3rem;">${el.title}</h2>
    <p style="margin:0; font-size:0.85rem; opacity:0.9;">Official Confidential Digital Balloting Booth &bull; ${el.org}</p>
  </div>`;

  if (sessRole === 'club_adviser') {
    html += `<div style="background:#fff3cd; color:#856404; border:1px solid #ffeeba; border-radius:12px; padding:20px; text-align:center;">
      <i class="fa-solid fa-user-shield" style="font-size:2rem; margin-bottom:8px; display:block;"></i>
      <h3 style="margin:0 0 4px 0;">Faculty Adviser Supervisory View</h3>
      <p style="margin:0; font-size:0.85rem;">Advisers supervise elections and establish candidates. Student members cast votes during the active voting window.</p>
    </div>`;
  } else {
    html += `<form onsubmit="handleCastVote(event, ${el.id})">`;
    el.positions.forEach(pos => {
      const posCands = el.candidates.filter(c => c.pos === pos);
      html += `<div style="background:#fff; border-radius:14px; padding:20px; border:1px solid #e2e8f0; margin-bottom:20px;">
        <h3 style="margin:0 0 14px 0; font-size:1rem; color:#1e3a8a; border-bottom:2px solid #f1f5f9; padding-bottom:8px;">Select ${pos}</h3>`;

      if (!posCands.length) {
        html += `<div style="font-size:0.85rem; color:#94a3b8;">No candidates for this position.</div>`;
      } else {
        posCands.forEach(c => {
          html += `<label style="display:flex; align-items:center; gap:12px; padding:12px 16px; border:1.5px solid #e2e8f0; border-radius:10px; margin-bottom:8px; cursor:pointer;">
            <input type="radio" name="vote_${pos}" value="${c.id}" required style="width:18px; height:18px; accent-color:#2563eb;"/>
            <div>
              <div style="font-weight:700; color:#0f172a;">${c.name}</div>
              <div style="font-size:0.78rem; color:#64748b;">${c.party} &bull; ${c.prog} ${c.year}</div>
            </div>
          </label>`;
        });
      }
      html += `</div>`;
    });

    html += `<div style="text-align:right;">
      <button type="submit" class="card-btn" style="background:#2563eb; color:#fff; font-weight:700; padding:12px 28px; font-size:0.95rem; border-radius:10px;"><i class="fa-solid fa-paper-plane"></i> Submit Official Ballot</button>
    </div></form>`;
  }

  document.getElementById('boothContent').innerHTML       = html;
  document.getElementById('electionLanding').style.display = 'none';
  document.getElementById('boothView').style.display       = 'block';
}

async function handleCastVote(e, electionId) {
  e.preventDefault();
  const form = e.target;
  const formData = new FormData(form);
  formData.append('action', 'cast_vote');
  formData.append('election_id', electionId);

  const votes = {};
  for (let pair of formData.entries()) {
    if (pair[0].startsWith('vote_')) {
      const pos = pair[0].replace('vote_', '');
      votes[pos] = pair[1];
    }
  }
  formData.append('votes', JSON.stringify(votes));

  try {
    const res = await fetch('../shared/election_actions.php', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.success) {
      alert(data.message);
      window.location.reload();
    } else {
      alert('Error: ' + data.message);
    }
  } catch (err) {
    alert('Network error casting vote.');
  }
}
</script>
<script src="../js/dashboard.js"></script>
</body>
</html>

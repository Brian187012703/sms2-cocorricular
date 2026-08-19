<?php
// ============================================================
//  CLUB_DIRECTORY.PHP  (dashboard/)
//  BCP Co-Curricular System Â— Accredited Organizations Directory
//  "Apply Now" flow: Org Profile ? Application Form ? PDF Download
// ============================================================
require_once __DIR__ . '/../shared/db.php';
session_start();

if (empty($_SESSION['user_id'])) {
  header('Location: ../auth/signin.php');
  exit;
}

$sess_first = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last = htmlspecialchars($_SESSION['last_name'] ?? '');
$sess_role = $_SESSION['role'] ?? 'student';
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$user_id = (int) ($_SESSION['user_id'] ?? 0);

// Restrict Adviser role from accessing the Organization Directory module
if ($sess_role === 'club_adviser') {
  header('Location: ../dashboard/dashboard.php');
  exit;
}

$can_apply = ($sess_role === 'student');

// -- Fetch real club IDs from DB, indexed by code --
$db_clubs = [];
$club_res = $conn->query("SELECT id, code FROM clubs WHERE status='Active'");
if ($club_res) {
  while ($rc = $club_res->fetch_assoc()) {
    $db_clubs[strtoupper($rc['code'])] = (int) $rc['id'];
    $clean_c = preg_replace('/[^A-Z0-9]/', '', strtoupper($rc['code']));
    $db_clubs[$clean_c] = (int) $rc['id'];
  }
}


// -- Organization profiles (achievements + officers per org) --
// In production, pull from DB. Keyed by acronym.
$org_profiles = [
  'ACADS' => ['desc' => 'ACADS is dedicated to academic excellence among Computer Engineering students through seminars, competitions, and peer mentoring programs.', 'achievements' => ['Regional Hackathon Champions 2025', 'Best Academic Org AY 2024-2025', 'SSC Excellence Award 2025'], 'officers' => [['name' => 'Sarah Dela Cruz', 'pos' => 'President'], ['name' => 'Mark Reyes', 'pos' => 'Vice President'], ['name' => 'Lea Santos', 'pos' => 'Secretary']]],
  'ACES' => ['desc' => 'ACES unites Computer Engineering students and promotes technical growth through workshops, laboratory enhancement campaigns, and industry visits.', 'achievements' => ['IEEE Student Chapter Partner', 'Top Performing CpE Org 2025'], 'officers' => [['name' => 'John Pascual', 'pos' => 'President'], ['name' => 'Nina Cruz', 'pos' => 'Vice President'], ['name' => 'Gio Marquez', 'pos' => 'Secretary']]],
  'AISS' => ['desc' => 'AISS advances accounting information literacy and bridges academic knowledge with industry technology practices in financial systems.', 'achievements' => ['Best Org Newsletter 2025', 'PICPA Youth Partner'], 'officers' => [['name' => 'Christine Tan', 'pos' => 'President'], ['name' => 'Jose Lim', 'pos' => 'Vice President'], ['name' => 'Ana Ramos', 'pos' => 'Secretary']]],
  'BLISS' => ['desc' => 'BLISS promotes library science excellence and information literacy across all BCP academic departments through advocacy and community service.', 'achievements' => ['National Library Advocacy Award', 'Outstanding Student Org 2024'], 'officers' => [['name' => 'Maria Fontanilla', 'pos' => 'President'], ['name' => 'Rob Santos', 'pos' => 'Vice President']]],
  'BRAVE' => ['desc' => 'BRAVE fosters values education and responsible leadership among BCP students through community outreach and character formation programs.', 'achievements' => ['National Values Org Award', '100 Hours Community Service 2025'], 'officers' => [['name' => 'Patricia Gomez', 'pos' => 'President'], ['name' => 'Ryan Dela Cruz', 'pos' => 'Vice President']]],
  'CJSU' => ['desc' => 'CJSU strengthens criminal justice students through moot courts, criminology seminars, and law enforcement immersion programs.', 'achievements' => ['Best Criminology Org 2025', 'Regional Moot Court Champions'], 'officers' => [['name' => 'Carlo Bautista', 'pos' => 'President'], ['name' => 'Mae Santos', 'pos' => 'Secretary']]],
  'EYO' => ['desc' => 'EYO cultivates entrepreneurial mindsets among BCP youth through business incubation workshops, trade fairs, and start-up mentorship.', 'achievements' => ['DTI Youth Entrepreneurship Awardee', 'Best Business Plan Org 2025'], 'officers' => [['name' => 'Anna Roque', 'pos' => 'President'], ['name' => 'James Ong', 'pos' => 'Vice President']]],
  'default' => ['desc' => 'This organization is an accredited co-curricular body under the BCP Supreme Student Council (SSC), dedicated to student development, community service, and academic excellence.', 'achievements' => ['BCP Accredited Organization AY 2025-2026', 'SSC Recognition Award'], 'officers' => [['name' => 'President (TBA)', 'pos' => 'President'], ['name' => 'Vice President (TBA)', 'pos' => 'Vice President']]],
];

function getOrgProfile(string $acronym, array $profiles): array
{
  return $profiles[$acronym] ?? $profiles['default'];
}

$organizations = [
  'academic' => [
    'label' => 'LEAGUE OF ORGANIZATIONAL CHAIRPERSONS (LOC)',
    'sub' => 'Academic Organizations',
    'color' => '#1a3a8c',
    'accent' => '#2563eb',
    'orgs' => [
      ['acronym' => 'ACADS', 'name' => 'Association of Computer Engineering Academic Driven Students'],
      ['acronym' => 'ACES', 'name' => 'Association of Computer Engineering Students'],
      ['acronym' => 'AISS', 'name' => 'Accounting Information System Society'],
      ['acronym' => 'BLISS', 'name' => 'Bestlink Library and Information Science Society'],
      ['acronym' => 'BRAVE', 'name' => 'Building Responsibility and Accountability Through Values Education'],
      ['acronym' => 'CJSU', 'name' => 'Criminal Justice Student Unit'],
      ['acronym' => 'EYO', 'name' => 'Entrepreyouth Organization'],
      ['acronym' => 'G.A.L.A.W', 'name' => 'Group of Athletes and Leaders Association for Wellness'],
      ['acronym' => 'GEMs', 'name' => 'Guild of English Majors'],
      ['acronym' => 'GOLD', 'name' => 'Guild of Officers to Lead Development'],
      ['acronym' => 'JFINEX', 'name' => 'Junior Financial Executives'],
      ['acronym' => 'HRS', 'name' => 'Human Resources Society'],
      ['acronym' => 'J.M.A', 'name' => 'Junior Marketing Association'],
      ['acronym' => 'LAKAS', 'name' => 'Liga ng mga Aktibong Kabataan sa Araling Panlipunan'],
      ['acronym' => 'L.A.P.I.S', 'name' => 'Leadership Association Program Including Services'],
      ['acronym' => 'LIBRO', 'name' => 'Lucid of Bright and Righteous Officers'],
      ['acronym' => 'OMEGA', 'name' => 'Organization for Mathematics in Engineering for Global Application'],
      ['acronym' => 'PsychSoc', 'name' => 'Psychology Society'],
      ['acronym' => 'RSD', 'name' => 'Regnum Scientiae Discipulus'],
      ['acronym' => 'SIGMA', 'name' => "Students' Interactive Guild for Mathematics Major"],
      ['acronym' => 'TECHs', 'name' => 'Technology, Exploratory, Creativity and Hospitality Skills'],
      ['acronym' => 'TTS', 'name' => 'Tourism Student Society'],
      ['acronym' => 'WIKA', 'name' => 'Wikang Filipino Instrumento sa Kaunlarang Akademya'],
    ]
  ],
  'talent' => [
    'label' => 'CENTER FOR TALENT AND CULTURAL EMPOWERMENT (CTCE)',
    'sub' => 'Non-Academic Organizations',
    'color' => '#1a3a8c',
    'accent' => '#7c3aed',
    'subcategories' => [
      [
        'label' => 'Department Based Talent Group',
        'orgs' => [
          ['acronym' => 'ACAC', 'name' => 'Association of Cultural Art Club'],
          ['acronym' => 'CESC', 'name' => 'Computer Engineering Sports Club'],
          ['acronym' => 'EBCPCT', 'name' => 'Elite BCP Chess Team'],
          ['acronym' => 'RCYC-BCP', 'name' => 'Red Cross Youth Council Â– BCP Chapter'],
          ['acronym' => 'SMC', 'name' => 'Shuttle Master Club'],
        ]
      ],
      [
        'label' => 'Talent Center',
        'orgs' => [
          ['acronym' => 'ALL STAR', 'name' => 'All Star'],
          ['acronym' => 'B-FORCE', 'name' => 'B-Force'],
          ['acronym' => 'CREATIVE', 'name' => 'Creative Arts'],
          ['acronym' => 'CDC', 'name' => 'Criminology Dance Company'],
          ['acronym' => 'DLC', 'name' => 'Drum and Lyre Corporation'],
          ['acronym' => 'IKATLONG', 'name' => 'Ikatlong Lahi Royalties'],
          ['acronym' => 'IMAGE', 'name' => 'Image Alchemy'],
          ['acronym' => 'S.I.K.A.T', 'name' => 'Sining Interpretasyon ng Kabataang Aktor sa Teatro'],
          ['acronym' => 'UV', 'name' => 'Unlimited Voice'],
        ]
      ],
    ]
  ],
  'independent' => [
    'label' => 'INDEPENDENT ORGANIZATIONS',
    'sub' => 'Campus-Wide Independent Bodies',
    'color' => '#1a3a8c',
    'accent' => '#059669',
    'orgs' => [
      ['acronym' => 'PEER', 'name' => 'Peer Counselor'],
      ['acronym' => 'NEWSLINK', 'name' => 'Newslink: The School Publications'],
      ['acronym' => 'GAD-CG', 'name' => 'Gender and Development Â– Core Group'],
    ]
  ]
];

$talent_count = array_sum(array_map(fn($s) => count($s['orgs']), $organizations['talent']['subcategories']));
$total_count = count($organizations['academic']['orgs']) + $talent_count + count($organizations['independent']['orgs']);

// Build flat org list for JS data injection
$all_orgs = [];
foreach ($organizations['academic']['orgs'] as $o) {
  $p = getOrgProfile($o['acronym'], $org_profiles);
  $clean_a = preg_replace('/[^A-Z0-9]/', '', strtoupper($o['acronym']));
  $club_id = $db_clubs[strtoupper($o['acronym'])] ?? $db_clubs[$clean_a] ?? 0;
  $all_orgs[$o['acronym']] = ['name' => $o['name'], 'category' => 'Academic Organization', 'accent' => $organizations['academic']['accent'], 'color' => $organizations['academic']['color'], 'profile' => $p, 'club_id' => $club_id];
}
foreach ($organizations['talent']['subcategories'] as $sub) {
  foreach ($sub['orgs'] as $o) {
    $p = getOrgProfile($o['acronym'], $org_profiles);
    $clean_a = preg_replace('/[^A-Z0-9]/', '', strtoupper($o['acronym']));
    $club_id = $db_clubs[strtoupper($o['acronym'])] ?? $db_clubs[$clean_a] ?? 0;
    $all_orgs[$o['acronym']] = ['name' => $o['name'], 'category' => $sub['label'], 'accent' => $organizations['talent']['accent'], 'color' => $organizations['talent']['color'], 'profile' => $p, 'club_id' => $club_id];
  }
}
foreach ($organizations['independent']['orgs'] as $o) {
  $p = getOrgProfile($o['acronym'], $org_profiles);
  $clean_a = preg_replace('/[^A-Z0-9]/', '', strtoupper($o['acronym']));
  $club_id = $db_clubs[strtoupper($o['acronym'])] ?? $db_clubs[$clean_a] ?? 0;
  $all_orgs[$o['acronym']] = ['name' => $o['name'], 'category' => 'Independent Organization', 'accent' => $organizations['independent']['accent'], 'color' => $organizations['independent']['color'], 'profile' => $p, 'club_id' => $club_id];
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Organization Directory â€“ BCP Co-Curricular Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../css/dashboard.css'); ?>" />
  <link rel="stylesheet" href="../css/page-loader.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
  <meta name="loader-logo" content="../images/BCP_LOGO.png" />
  <script src="../js/page-loader.js"></script>

  <!-- jsPDF for client-side PDF generation -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

  <style>
    /* -- Category Filter Pills -- */
    .cat-filter-pill {
      background: #f1f5f9;
      border: 1.5px solid #e2e8f0;
      color: #64748b;
      border-radius: 20px;
      padding: 6px 16px;
      font-size: 0.8rem;
      font-weight: 600;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.15s;
    }

    .cat-filter-pill:hover {
      border-color: #2563eb;
      color: #2563eb;
      background: #eff6ff;
    }

    .cat-filter-pill.active {
      background: #1a3a8c;
      color: #fff;
      border-color: transparent;
    }

    /* -- Org Profile Modal -- */
    .org-profile-overlay {
      position: fixed;
      inset: 0;
      background: rgba(10, 12, 30, 0.65);
      z-index: 1000;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 16px;
    }

    .org-profile-overlay.active {
      display: flex;
      animation: fadeIn 0.2s ease;
    }

    @keyframes fadeIn {
      from {
        opacity: 0
      }

      to {
        opacity: 1
      }
    }

    .org-profile-modal {
      background: #fff;
      border-radius: 20px;
      width: 100%;
      max-width: 620px;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: 0 24px 64px rgba(0, 0, 0, 0.28);
      display: flex;
      flex-direction: column;
      animation: slideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes slideUp {
      from {
        transform: translateY(24px);
        opacity: 0
      }

      to {
        transform: translateY(0);
        opacity: 1
      }
    }

    .opm-hero {
      padding: 28px 26px 22px;
      position: relative;
      overflow: hidden;
      border-radius: 20px 20px 0 0;
    }

    .opm-hero::after {
      content: '';
      position: absolute;
      top: -50px;
      right: -50px;
      width: 180px;
      height: 180px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.08);
      pointer-events: none;
    }

    .opm-close {
      position: absolute;
      top: 14px;
      right: 16px;
      background: rgba(255, 255, 255, 0.18);
      border: none;
      color: #fff;
      width: 32px;
      height: 32px;
      border-radius: 50%;
      font-size: 1.1rem;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: background 0.15s;
      z-index: 2;
    }

    .opm-close:hover {
      background: rgba(255, 255, 255, 0.3);
    }

    .opm-acronym-badge {
      display: inline-block;
      background: rgba(255, 255, 255, 0.18);
      border: 1px solid rgba(255, 255, 255, 0.28);
      color: #fff;
      font-size: 0.68rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      padding: 4px 12px;
      border-radius: 14px;
      margin-bottom: 10px;
    }

    .opm-title {
      font-size: 1.25rem;
      font-weight: 900;
      color: #fff;
      margin: 0 0 5px;
      line-height: 1.25;
    }

    .opm-category {
      font-size: 0.78rem;
      color: rgba(255, 255, 255, 0.72);
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .opm-body {
      padding: 22px 26px 8px;
    }

    .opm-section-label {
      font-size: 0.68rem;
      font-weight: 800;
      color: #94a3b8;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      margin-bottom: 8px;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .opm-section-label::after {
      content: '';
      flex: 1;
      height: 1px;
      background: #f0f2f5;
    }

    .opm-description {
      font-size: 0.85rem;
      color: #374151;
      line-height: 1.65;
      background: #f8fafc;
      border-radius: 10px;
      padding: 14px 16px;
      border: 1px solid #e2e8f0;
      margin-bottom: 18px;
    }

    /* Achievements list */
    .opm-achievements {
      list-style: none;
      margin: 0 0 18px;
      padding: 0;
      display: flex;
      flex-direction: column;
      gap: 7px;
    }

    .opm-achievements li {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 0.82rem;
      color: #1a1a2e;
      font-weight: 600;
      background: #f0fdf4;
      border: 1px solid #bbf7d0;
      border-radius: 8px;
      padding: 8px 12px;
    }

    .opm-achievements li i {
      color: #16a34a;
      flex-shrink: 0;
    }

    /* Officers grid */
    .opm-officers-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
      gap: 10px;
      margin-bottom: 18px;
    }

    .opm-officer-card {
      text-align: center;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      padding: 12px 10px;
    }

    .opm-officer-avatar {
      width: 42px;
      height: 42px;
      border-radius: 50%;
      background: #1a3a8c;
      color: #fff;
      font-size: 1rem;
      font-weight: 800;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 8px;
    }

    .opm-officer-name {
      font-size: 0.78rem;
      font-weight: 700;
      color: #1a1a2e;
    }

    .opm-officer-pos {
      font-size: 0.65rem;
      color: #94a3b8;
      font-weight: 500;
      margin-top: 2px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    /* CTA button at bottom of profile */
    .opm-apply-cta {
      margin: 6px 26px 24px;
      padding: 14px 20px;
      background: #1a3a8c;
      color: #fff;
      border: none;
      border-radius: 12px;
      font-size: 0.92rem;
      font-weight: 800;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      transition: all 0.2s ease;
      box-shadow: 0 4px 16px rgba(37, 99, 235, 0.35);
      width: calc(100% - 52px);
    }

    .opm-apply-cta:hover {
      box-shadow: 0 8px 24px rgba(37, 99, 235, 0.48);
      transform: translateY(-1px);
    }

    /* -- Application Form Modal -- */
    .app-form-overlay {
      position: fixed;
      inset: 0;
      background: rgba(10, 12, 30, 0.72);
      z-index: 1100;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 16px;
    }

    .app-form-overlay.active {
      display: flex;
      animation: fadeIn 0.2s ease;
    }

    .app-form-modal {
      background: #fff;
      border-radius: 20px;
      width: 100%;
      max-width: 680px;
      max-height: 92vh;
      overflow-y: auto;
      box-shadow: 0 24px 64px rgba(0, 0, 0, 0.3);
      animation: slideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
      display: flex;
      flex-direction: column;
    }

    .afm-header {
      padding: 20px 24px 16px;
      border-bottom: 1px solid #e2e8f0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      position: sticky;
      top: 0;
      background: #fff;
      z-index: 2;
      border-radius: 20px 20px 0 0;
    }

    .afm-header-left {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }

    .afm-header-org {
      font-size: 0.7rem;
      font-weight: 700;
      color: #94a3b8;
      text-transform: uppercase;
      letter-spacing: 0.06em;
    }

    .afm-header-title {
      font-size: 1rem;
      font-weight: 800;
      color: #1a1a2e;
    }

    .afm-close {
      background: #f1f5f9;
      border: none;
      color: #475569;
      width: 34px;
      height: 34px;
      border-radius: 50%;
      font-size: 1rem;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.15s;
      flex-shrink: 0;
    }

    .afm-close:hover {
      background: #e2e8f0;
      color: #1a1a2e;
    }

    .afm-body {
      padding: 22px 24px 16px;
    }

    .afm-notice {
      background: #eff6ff;
      border: 1px solid #bfdbfe;
      border-radius: 10px;
      padding: 12px 16px;
      font-size: 0.8rem;
      color: #1e40af;
      display: flex;
      align-items: flex-start;
      gap: 10px;
      margin-bottom: 20px;
      line-height: 1.55;
    }

    .afm-notice i {
      margin-top: 1px;
      flex-shrink: 0;
    }

    .afm-section-title {
      font-size: 0.7rem;
      font-weight: 800;
      color: #94a3b8;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      margin-bottom: 12px;
      margin-top: 18px;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .afm-section-title:first-child {
      margin-top: 0;
    }

    .afm-section-title::after {
      content: '';
      flex: 1;
      height: 1px;
      background: #f0f2f5;
    }

    .afm-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }

    .afm-grid.cols-3 {
      grid-template-columns: repeat(3, 1fr);
    }

    .afm-grid.cols-1 {
      grid-template-columns: 1fr;
    }

    .afm-field {
      display: flex;
      flex-direction: column;
      gap: 5px;
    }

    .afm-field.full {
      grid-column: 1/-1;
    }

    .afm-field label {
      font-size: 0.72rem;
      font-weight: 700;
      color: #374151;
      letter-spacing: 0.02em;
    }

    .afm-field label span {
      color: #e11d48;
      margin-left: 2px;
    }

    .afm-field input,
    .afm-field select,
    .afm-field textarea {
      padding: 10px 13px;
      border: 1.5px solid #e2e8f0;
      border-radius: 9px;
      font-size: 0.85rem;
      color: #1a1a2e;
      background: #fafafa;
      transition: all 0.15s ease;
      font-family: inherit;
      width: 100%;
      box-sizing: border-box;
    }

    .afm-field textarea {
      resize: vertical;
      min-height: 80px;
    }

    .afm-field input:focus,
    .afm-field select:focus,
    .afm-field textarea:focus {
      border-color: #2563eb;
      background: #fff;
      outline: none;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }

    .afm-field input.error {
      border-color: #e11d48;
      background: #fff0f3;
    }

    .afm-checkbox-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .afm-checkbox-item {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 0.8rem;
      color: #374151;
      font-weight: 500;
      cursor: pointer;
    }

    .afm-checkbox-item input[type="checkbox"] {
      width: 16px;
      height: 16px;
      cursor: pointer;
      accent-color: #2563eb;
    }

    /* Signature area */
    .afm-signature-wrap {
      border: 1.5px solid #e2e8f0;
      border-radius: 10px;
      overflow: hidden;
      background: #fafafa;
    }

    .afm-signature-canvas {
      width: 100%;
      height: 100px;
      display: block;
      cursor: crosshair;
      touch-action: none;
    }

    .afm-sig-actions {
      display: flex;
      gap: 8px;
      padding: 6px 10px;
      background: #f8fafc;
      border-top: 1px solid #e2e8f0;
    }

    .afm-sig-clear {
      background: none;
      border: 1px solid #e2e8f0;
      border-radius: 7px;
      padding: 5px 12px;
      font-size: 0.72rem;
      font-weight: 600;
      color: #64748b;
      cursor: pointer;
    }

    .afm-sig-clear:hover {
      border-color: #e11d48;
      color: #e11d48;
    }

    .afm-footer {
      padding: 16px 24px 24px;
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      border-top: 1px solid #f0f2f5;
      position: sticky;
      bottom: 0;
      background: #fff;
      border-radius: 0 0 20px 20px;
    }

    .afm-btn-submit {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      background: #1a3a8c;
      color: #fff;
      border: none;
      border-radius: 11px;
      padding: 13px 20px;
      font-size: 0.9rem;
      font-weight: 800;
      cursor: pointer;
      transition: all 0.2s;
      box-shadow: 0 4px 14px rgba(37, 99, 235, 0.32);
    }

    .afm-btn-submit:hover {
      box-shadow: 0 8px 24px rgba(37, 99, 235, 0.45);
      transform: translateY(-1px);
    }

    .afm-btn-download {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      background: #f1f5f9;
      color: #475569;
      border: 1.5px solid #e2e8f0;
      border-radius: 11px;
      padding: 13px 20px;
      font-size: 0.88rem;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.15s;
    }

    .afm-btn-download:hover {
      background: #e2e8f0;
      color: #1a1a2e;
      border-color: #cbd5e1;
    }

    /* -- Success State -- */
    .afm-success {
      padding: 40px 30px;
      text-align: center;
      display: none;
      flex-direction: column;
      align-items: center;
      gap: 14px;
    }

    .afm-success.active {
      display: flex;
    }

    .afm-success-icon {
      font-size: 3.5rem;
      color: #22c55e;
      animation: bounceIn 0.5s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes bounceIn {
      from {
        transform: scale(0.5);
        opacity: 0
      }

      to {
        transform: scale(1);
        opacity: 1
      }
    }

    .afm-success h3 {
      font-size: 1.2rem;
      font-weight: 800;
      color: #1a1a2e;
      margin: 0;
    }

    .afm-success p {
      font-size: 0.85rem;
      color: #64748b;
      line-height: 1.6;
      margin: 0;
      max-width: 400px;
    }

    /* Responsive adjustments */
    @media (max-width:600px) {
      .opm-hero {
        padding: 20px 18px 18px;
      }

      .opm-body {
        padding: 18px 18px 6px;
      }

      .opm-apply-cta {
        width: calc(100% - 36px);
        margin: 6px 18px 20px;
      }

      .afm-grid {
        grid-template-columns: 1fr;
      }

      .afm-grid.cols-3 {
        grid-template-columns: 1fr 1fr;
      }

      .opm-officers-grid {
        grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
      }

      .afm-footer {
        flex-direction: column;
      }

      .afm-btn-submit,
      .afm-btn-download {
        width: 100%;
      }
    }
  </style>
</head>

<body>
  <?php
  $APP_ROOT = '../';
  $ACTIVE_NAV = 'clubs';
  require_once __DIR__ . '/../shared/sidebar.php';
  ?>
  <div class="main">
    <div class="topbar">
      <button class="hamburger" id="hamburgerBtn" aria-label="Toggle sidebar"><i class="fa-solid fa-bars"></i></button>
      <span class="topbar-spacer"></span>
      <div class="topbar-right">
        <div class="search-wrap">
          <input type="text" id="orgSearch" placeholder="Search organizations..." oninput="filterOrgs(this.value)" />
          <i class="fa-solid fa-magnifying-glass"></i>
        </div>
        <button class="topbar-qr-btn" id="qrFabBtn" title="QR Code Center" type="button"><i
            class="fa-solid fa-qrcode"></i></button>
        <a href="../dashboard/account.php" class="avatar" id="avatarBtn"
          title="Account Settings"><?php echo $sess_initial; ?></a>
      </div>
    </div>

    <div class="content">
      <div class="page-title-bar">
        <h2 class="page-title"><i class="fa-solid fa-sitemap"></i>
          <?= $sess_role === 'club_adviser' ? 'My Organization & Directory' : 'BCP Accredited Organizations Directory' ?>
        </h2>
        <div style="font-size:0.82rem; color:#64748b; margin-top:4px;">
          <i class="fa-solid fa-building-columns" style="color:#2563eb;"></i>
          <?php echo $total_count; ?> accredited organizations &mdash; Academic Year 2025&ndash;2026
        </div>
      </div>

      <div class="content-body">

        <?php if ($sess_role === 'club_adviser'): ?>
          <?php
          // Fetch handled organization for Adviser
          $my_org = $conn->query("SELECT c.*, 
                                 (SELECT COUNT(*) FROM club_memberships cm WHERE cm.club_id=c.id AND cm.status='Active') as active_count,
                                 (SELECT COUNT(*) FROM club_memberships cm WHERE cm.club_id=c.id AND cm.status='Pending') as pending_count
                                 FROM clubs c 
                                 JOIN club_memberships cm ON cm.club_id=c.id 
                                 WHERE cm.user_id=$user_id AND cm.status='Active' LIMIT 1")->fetch_assoc();
          if (!$my_org) {
            $my_org = $conn->query("SELECT c.*, 
                                     (SELECT COUNT(*) FROM club_memberships cm WHERE cm.club_id=c.id AND cm.status='Active') as active_count,
                                     (SELECT COUNT(*) FROM club_memberships cm WHERE cm.club_id=c.id AND cm.status='Pending') as pending_count
                                     FROM clubs c WHERE c.status='Active' LIMIT 1")->fetch_assoc();
          }
          ?>
          <?php if ($my_org): ?>
            <div
              style="background: #1a3a8c; color: white; padding: 24px; border-radius: 16px; margin-bottom: 25px; box-shadow: 0 10px 25px -5px rgba(37,99,235,0.3);">
              <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
                <div>
                  <span
                    style="background:rgba(255,255,255,0.2); padding:4px 12px; border-radius:20px; font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Handled
                    Organization Governance</span>
                  <h2 style="margin:8px 0 4px; font-size:1.6rem; font-weight:800; color:white;">
                    <?= htmlspecialchars($my_org['name']) ?> (<?= htmlspecialchars($my_org['code']) ?>)</h2>
                  <p style="margin:0; font-size:0.9rem; opacity:0.9; max-width:650px;">
                    <?= htmlspecialchars($my_org['description'] ?: 'Accredited Student Organization under BCP Office of Student Affairs.') ?>
                  </p>
                </div>
                <div style="display:flex; gap:12px; flex-wrap:wrap;">
                  <button
                    onclick="openBroadcastModal(<?= $my_org['id'] ?>, '<?= htmlspecialchars(addslashes($my_org['name'])) ?>')"
                    style="background:white; color:#0f2a73; border:none; padding:10px 18px; border-radius:10px; font-weight:700; cursor:pointer; font-size:0.88rem; box-shadow:0 4px 6px rgba(0,0,0,0.1);">
                    <i class="fa-solid fa-bullhorn" style="margin-right:6px;color:#2563eb;"></i>Post Announcement
                  </button>
                  <a href="roster.php"
                    style="background:rgba(255,255,255,0.2); color:white; text-decoration:none; padding:10px 18px; border-radius:10px; font-weight:700; font-size:0.88rem; border:1px solid rgba(255,255,255,0.3); display:inline-flex; align-items:center;">
                    <i class="fa-solid fa-users" style="margin-right:6px;"></i>Review Applicants
                    (<?= (int) $my_org['pending_count'] ?>)
                  </a>
                </div>
              </div>
              <div
                style="display:flex; gap:24px; margin-top:20px; padding-top:16px; border-top:1px solid rgba(255,255,255,0.2); font-size:0.88rem; flex-wrap:wrap;">
                <div><i class="fa-solid fa-user-check" style="margin-right:6px;opacity:0.8;"></i>Active Members:
                  <strong><?= $my_org['active_count'] ?></strong></div>
                <div><i class="fa-solid fa-clock" style="margin-right:6px;opacity:0.8;"></i>Pending Applicants:
                  <strong><?= $my_org['pending_count'] ?></strong></div>
                <div><i class="fa-solid fa-user-tie" style="margin-right:6px;opacity:0.8;"></i>Adviser:
                  <strong><?= htmlspecialchars($sess_first . ' ' . $sess_last) ?></strong></div>
              </div>
            </div>
          <?php endif; ?>
        <?php endif; ?>
        <!-- Category Filters -->
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:20px;">
          <button class="cat-filter-pill active" onclick="filterCat('all',this)"><i class="fa-solid fa-th-large"></i>
            All Organizations</button>
          <button class="cat-filter-pill" onclick="filterCat('academic',this)"><i
              class="fa-solid fa-graduation-cap"></i> Academic</button>
          <button class="cat-filter-pill" onclick="filterCat('talent',this)"><i class="fa-solid fa-star"></i> Talent
            &amp; Cultural</button>
          <button class="cat-filter-pill" onclick="filterCat('independent',this)"><i class="fa-solid fa-seedling"></i>
            Independent</button>
        </div>

        <div class="org-directory-wrap" id="orgDirectory">

          <!-- -- ACADEMIC -- -->
          <div class="org-category-section" data-category="academic">
            <div class="org-category-header">
              <div class="cat-icon"><i class="fa-solid fa-graduation-cap"></i></div>
              <div class="cat-info">
                <div class="cat-title"><?php echo htmlspecialchars($organizations['academic']['label']); ?></div>
                <div class="cat-sub"><?php echo htmlspecialchars($organizations['academic']['sub']); ?></div>
              </div>
              <span class="cat-count"><?php echo count($organizations['academic']['orgs']); ?> orgs</span>
            </div>
            <div class="org-subcategory">
              <div class="org-cards-grid">
                <?php foreach ($organizations['academic']['orgs'] as $org): ?>
                  <div class="org-card"
                    data-name="<?php echo htmlspecialchars(strtolower($org['name'] . ' ' . $org['acronym'])); ?>">
                    <span class="org-card-acronym"><?php echo htmlspecialchars($org['acronym']); ?></span>
                    <div class="org-card-name"><?php echo htmlspecialchars($org['name']); ?></div>
                    <div class="org-card-type"><i class="fa-solid fa-circle-dot"
                        style="color:#2563eb;font-size:.6rem;"></i> Academic Organization</div>
                    <?php if ($sess_role === 'student'): ?>
                      <button class="org-card-qr-btn"
                        onclick="openOrgProfile('<?php echo htmlspecialchars($org['acronym'], ENT_QUOTES); ?>')">
                        <i class="fa-solid fa-user-plus"></i> Apply Now
                      </button>
                    <?php else: ?>
                      <button class="org-card-qr-btn" style="background:#f1f5f9;color:#64748b;border-color:#e2e8f0;"
                        onclick="openOrgProfile('<?php echo htmlspecialchars($org['acronym'], ENT_QUOTES); ?>')">
                        <i class="fa-solid fa-eye"></i> View Profile
                      </button>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <!-- -- TALENT & CULTURAL -- -->
          <div class="org-category-section" data-category="talent">
            <div class="org-category-header"
              style="background:#1a3a8c;">
              <div class="cat-icon"><i class="fa-solid fa-star"></i></div>
              <div class="cat-info">
                <div class="cat-title"><?php echo htmlspecialchars($organizations['talent']['label']); ?></div>
                <div class="cat-sub"><?php echo htmlspecialchars($organizations['talent']['sub']); ?></div>
              </div>
              <span class="cat-count"><?php echo $talent_count; ?> orgs</span>
            </div>
            <?php foreach ($organizations['talent']['subcategories'] as $subcat): ?>
              <div class="org-subcategory">
                <div class="org-subcategory-label">
                  <i class="fa-solid fa-chevron-right" style="color:#7c3aed;font-size:.6rem;"></i>
                  <?php echo htmlspecialchars($subcat['label']); ?>
                  <span
                    style="background:#f3e8ff;color:#7c3aed;border-radius:12px;padding:2px 8px;font-size:.65rem;margin-left:4px;"><?php echo count($subcat['orgs']); ?></span>
                </div>
                <div class="org-cards-grid">
                  <?php foreach ($subcat['orgs'] as $org): ?>
                    <div class="org-card"
                      data-name="<?php echo htmlspecialchars(strtolower($org['name'] . ' ' . $org['acronym'])); ?>"
                      style="border-color:#e9d5ff;">
                      <span class="org-card-acronym"
                        style="background:#1a3a8c;"><?php echo htmlspecialchars($org['acronym']); ?></span>
                      <div class="org-card-name"><?php echo htmlspecialchars($org['name']); ?></div>
                      <div class="org-card-type"><i class="fa-solid fa-circle-dot"
                          style="color:#7c3aed;font-size:.6rem;"></i> <?php echo htmlspecialchars($subcat['label']); ?>
                      </div>
                      <?php if ($can_apply): ?>
                        <button class="org-card-qr-btn"
                          onclick="openOrgProfile('<?php echo htmlspecialchars($org['acronym'], ENT_QUOTES); ?>')">
                          <i class="fa-solid fa-user-plus"></i> Apply Now
                        </button>
                      <?php else: ?>
                        <button class="org-card-qr-btn" style="background:#f1f5f9;color:#64748b;border-color:#e2e8f0;"
                          onclick="openOrgProfile('<?php echo htmlspecialchars($org['acronym'], ENT_QUOTES); ?>')">
                          <i class="fa-solid fa-eye"></i> View Profile
                        </button>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <!-- -- INDEPENDENT -- -->
          <div class="org-category-section" data-category="independent">
            <div class="org-category-header"
              style="background:#1a3a8c;">
              <div class="cat-icon"><i class="fa-solid fa-seedling"></i></div>
              <div class="cat-info">
                <div class="cat-title"><?php echo htmlspecialchars($organizations['independent']['label']); ?></div>
                <div class="cat-sub"><?php echo htmlspecialchars($organizations['independent']['sub']); ?></div>
              </div>
              <span class="cat-count"><?php echo count($organizations['independent']['orgs']); ?> orgs</span>
            </div>
            <div class="org-subcategory">
              <div class="org-cards-grid">
                <?php foreach ($organizations['independent']['orgs'] as $org): ?>
                  <div class="org-card"
                    data-name="<?php echo htmlspecialchars(strtolower($org['name'] . ' ' . $org['acronym'])); ?>"
                    style="border-color:#a7f3d0;">
                    <span class="org-card-acronym"
                      style="background:#1a3a8c;"><?php echo htmlspecialchars($org['acronym']); ?></span>
                    <div class="org-card-name"><?php echo htmlspecialchars($org['name']); ?></div>
                    <div class="org-card-type"><i class="fa-solid fa-circle-dot"
                        style="color:#059669;font-size:.6rem;"></i> Independent Organization</div>
                    <?php if ($can_apply): ?>
                      <button class="org-card-qr-btn"
                        onclick="openOrgProfile('<?php echo htmlspecialchars($org['acronym'], ENT_QUOTES); ?>')">
                        <i class="fa-solid fa-user-plus"></i> Apply Now
                      </button>
                    <?php else: ?>
                      <button class="org-card-qr-btn" style="background:#f1f5f9;color:#64748b;border-color:#e2e8f0;"
                        onclick="openOrgProfile('<?php echo htmlspecialchars($org['acronym'], ENT_QUOTES); ?>')">
                        <i class="fa-solid fa-eye"></i> View Profile
                      </button>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

        </div><!-- end #orgDirectory -->
      </div>
    </div>
    <div class="footer">Co-Curricular Management System &copy; 2026</div>
  </div>

  <!-- ----------------------------------------------------------
     MODAL 1: Organization Profile
---------------------------------------------------------- -->
  <div class="org-profile-overlay" id="orgProfileOverlay">
    <div class="org-profile-modal" id="orgProfileModal">

      <!-- Hero Header -->
      <div class="opm-hero" id="opmHero">
        <button class="opm-close" id="opmClose" title="Close">Ã—</button>
        <div class="opm-acronym-badge" id="opmAcronym">ORG</div>
        <h2 class="opm-title" id="opmTitle">Organization Name</h2>
        <div class="opm-category" id="opmCategory">
          <i class="fa-solid fa-circle-dot"></i> <span></span>
        </div>
      </div>

      <!-- Profile Body -->
      <div class="opm-body">

        <!-- Description -->
        <div class="opm-section-label"><i class="fa-solid fa-circle-info"></i> About</div>
        <div class="opm-description" id="opmDescription"></div>

        <!-- Achievements -->
        <div class="opm-section-label"><i class="fa-solid fa-trophy"></i> Achievements</div>
        <ul class="opm-achievements" id="opmAchievements"></ul>

        <!-- Officers -->
        <div class="opm-section-label"><i class="fa-solid fa-users"></i> Current Officers</div>
        <div class="opm-officers-grid" id="opmOfficers"></div>

      </div>

      <!-- Apply CTA (Student only) -->
      <?php if ($can_apply): ?>
        <button class="opm-apply-cta" id="opmApplyBtn">
          <i class="fa-solid fa-file-pen"></i>
          Submit Your Application
        </button>
      <?php else: ?>
        <div
          style="margin:6px 26px 24px; padding:12px 20px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; color:#64748b; font-size:0.85rem; text-align:center;">
          <i class="fa-solid fa-info-circle" style="color:#94a3b8;"></i>
          Organization applications are for students only.
        </div>
      <?php endif; ?>

    </div>
  </div>

  <!-- ----------------------------------------------------------
     MODAL 2: Membership Application Form
---------------------------------------------------------- -->
  <div class="app-form-overlay" id="appFormOverlay">
    <div class="app-form-modal" id="appFormModal">

      <!-- Sticky Header -->
      <div class="afm-header">
        <div class="afm-header-left">
          <div class="afm-header-org" id="afmOrgName">Organization</div>
          <div class="afm-header-title">Membership Application Form</div>
        </div>
        <button class="afm-close" id="afmClose" title="Close">Ã—</button>
      </div>

      <!-- Form body (hidden when success shows) -->
      <div class="afm-body" id="afmFormBody">

        <div class="afm-notice">
          <i class="fa-solid fa-circle-info"></i>
          <span>Please fill in all required fields marked with <strong>*</strong>. You may download a PDF copy of your
            completed application for hard-copy submission to the organization.</span>
        </div>

        <!-- PERSONAL INFORMATION -->
        <div class="afm-section-title"><i class="fa-solid fa-user"></i> Personal Information</div>
        <div class="afm-grid">
          <div class="afm-field">
            <label>First Name <span>*</span></label>
            <input type="text" id="afmFirstName" placeholder="e.g. Maria" required />
          </div>
          <div class="afm-field">
            <label>Last Name <span>*</span></label>
            <input type="text" id="afmLastName" placeholder="e.g. Santos" required />
          </div>
          <div class="afm-field">
            <label>Middle Name</label>
            <input type="text" id="afmMiddleName" placeholder="e.g. Dela Cruz" />
          </div>
          <div class="afm-field">
            <label>Date of Birth <span>*</span></label>
            <input type="date" id="afmDob" required />
          </div>
          <div class="afm-field">
            <label>Sex <span>*</span></label>
            <select id="afmSex" required>
              <option value="">Select...</option>
              <option>Male</option>
              <option>Female</option>
              <option>Prefer not to say</option>
            </select>
          </div>
          <div class="afm-field">
            <label>Contact Number <span>*</span></label>
            <input type="tel" id="afmContact" placeholder="e.g. 09XX XXX XXXX" required />
          </div>
          <div class="afm-field full">
            <label>Email Address <span>*</span></label>
            <input type="email" id="afmEmail" placeholder="e.g. student@bcp.edu.ph" required />
          </div>
          <div class="afm-field full">
            <label>Permanent Address <span>*</span></label>
            <input type="text" id="afmAddress" placeholder="Street, Barangay, City, Province" required />
          </div>
        </div>

        <!-- ACADEMIC INFORMATION -->
        <div class="afm-section-title"><i class="fa-solid fa-graduation-cap"></i> Academic Information</div>
        <div class="afm-grid">
          <div class="afm-field">
            <label>Student ID Number <span>*</span></label>
            <input type="text" id="afmStudentId" placeholder="e.g. BCP-2024-00001" required />
          </div>
          <div class="afm-field">
            <label>Year Level <span>*</span></label>
            <select id="afmYearLevel" required>
              <option value="">Select...</option>
              <option>1st Year</option>
              <option>2nd Year</option>
              <option>3rd Year</option>
              <option>4th Year</option>
            </select>
          </div>
          <div class="afm-field">
            <label>Program / Course <span>*</span></label>
            <input type="text" id="afmCourse" placeholder="e.g. BSIT" required />
          </div>
          <div class="afm-field">
            <label>Section</label>
            <input type="text" id="afmSection" placeholder="e.g. BSIT 3-A" />
          </div>
        </div>

        <!-- SKILLS & INTERESTS -->
        <div class="afm-section-title"><i class="fa-solid fa-wand-magic-sparkles"></i> Skills &amp; Interests</div>
        <div class="afm-grid cols-1">
          <div class="afm-field">
            <label>Skills &amp; Competencies</label>
            <textarea id="afmSkills"
              placeholder="e.g. Web development, public speaking, graphic design, leadership..."></textarea>
          </div>
          <div class="afm-field">
            <label>Why do you want to join this organization? <span>*</span></label>
            <textarea id="afmMotivation" placeholder="Share your motivation, goals, and what you can contribute..."
              required style="min-height:90px;"></textarea>
          </div>
        </div>

        <!-- REQUIRED DOCUMENTS SUBMISSION -->
        <div class="afm-section-title"><i class="fa-solid fa-file-arrow-up"></i> Required Documents Submission</div>
        <div class="afm-grid">
          <div class="afm-field">
            <label>Letter of Intent <span>*</span> <span
                style="font-weight:400;color:#94a3b8;">(PDF/DOCX/Image)</span></label>
            <input type="file" id="afmLetterIntent" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg,.webp" required
              style="padding:6px 10px; font-size:0.82rem;" />
          </div>
          <div class="afm-field">
            <label>Letter of Endorsement <span>*</span> <span
                style="font-weight:400;color:#94a3b8;">(PDF/DOCX/Image)</span></label>
            <input type="file" id="afmLetterEndorsement" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg,.webp" required
              style="padding:6px 10px; font-size:0.82rem;" />
          </div>
        </div>

        <!-- COMMITMENT -->
        <div class="afm-section-title"><i class="fa-solid fa-handshake"></i> Commitment &amp; Agreement</div>
        <div class="afm-grid cols-1">
          <div class="afm-field">
            <div class="afm-checkbox-group">
              <label class="afm-checkbox-item">
                <input type="checkbox" id="afmChk1" />
                I certify that all information provided is true and accurate.
              </label>
              <label class="afm-checkbox-item">
                <input type="checkbox" id="afmChk2" />
                I understand that membership is subject to the organization's screening and approval process.
              </label>
              <label class="afm-checkbox-item">
                <input type="checkbox" id="afmChk3" />
                I agree to abide by the BCP Student Handbook and the organization's constitution and by-laws.
              </label>
            </div>
          </div>
        </div>

      </div><!-- end afm-body -->

      <!-- Success State -->
      <div class="afm-success" id="afmSuccess">
        <div class="afm-success-icon"><i class="fa-solid fa-circle-check"></i></div>
        <h3>Application Submitted!</h3>
        <p>Your membership application has been received. The organization will review your application and contact you
          via email within 3Â–5 business days.</p>
        <button class="afm-btn-download" id="afmSuccessDownloadBtn" style="margin-top:6px;">
          <i class="fa-solid fa-file-pdf"></i> Download PDF Copy
        </button>
        <button class="afm-btn-submit" onclick="closeAppForm()" style="max-width:240px; margin-top:4px;">
          <i class="fa-solid fa-check"></i> Done
        </button>
      </div>

      <!-- Sticky Footer Actions -->
      <div class="afm-footer" id="afmFooter">
        <button class="afm-btn-download" id="afmDownloadBtn">
          <i class="fa-solid fa-file-pdf"></i> Download PDF
        </button>
        <button class="afm-btn-submit" id="afmSubmitBtn">
          <i class="fa-solid fa-paper-plane"></i> Submit Application
        </button>
      </div>

    </div>
  </div>


  <script src="../js/dashboard.js"></script>
  <script>
    // -- Org data from PHP -----------------------------------------
    const ORG_DATA = <?php echo json_encode($all_orgs, JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    let currentOrg = null; // { acronym, ...data }

    // -- CATEGORY & SEARCH FILTERS ---------------------------------
    function filterCat(cat, btn) {
      document.querySelectorAll('.cat-filter-pill').forEach(p => p.classList.remove('active'));
      btn.classList.add('active');
      document.querySelectorAll('.org-category-section').forEach(s => {
        s.style.display = (cat === 'all' || s.dataset.category === cat) ? '' : 'none';
      });
    }
    function filterOrgs(q) {
      q = q.toLowerCase().trim();
      document.querySelectorAll('.org-card').forEach(c => {
        c.style.display = (!q || c.dataset.name.includes(q)) ? '' : 'none';
      });
      document.querySelectorAll('.org-category-section').forEach(s => {
        s.style.display = [...s.querySelectorAll('.org-card')].some(c => c.style.display !== 'none') ? '' : 'none';
      });
    }

    // -- OPEN ORG PROFILE MODAL ------------------------------------
    function openOrgProfile(acronym) {
      const data = ORG_DATA[acronym];
      if (!data) return;
      currentOrg = { acronym, ...data };

      // Hero
      const hero = document.getElementById('opmHero');
      hero.style.background = data.color;
      document.getElementById('opmAcronym').textContent = acronym;
      document.getElementById('opmTitle').textContent = data.name;
      const catEl = document.getElementById('opmCategory');
      catEl.querySelector('span').textContent = data.category;

      // Description
      document.getElementById('opmDescription').textContent = data.profile.desc;

      // Achievements
      const achList = document.getElementById('opmAchievements');
      achList.innerHTML = data.profile.achievements.map(a =>
        `<li><i class="fa-solid fa-medal"></i> ${a}</li>`
      ).join('');

      // Officers
      const officersGrid = document.getElementById('opmOfficers');
      officersGrid.innerHTML = data.profile.officers.map(o => {
        const initials = o.name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase();
        return `
      <div class="opm-officer-card">
        <div class="opm-officer-avatar" style="background:${data.color};">${initials}</div>
        <div class="opm-officer-name">${o.name}</div>
        <div class="opm-officer-pos">${o.pos}</div>
      </div>`;
      }).join('');

      document.getElementById('orgProfileOverlay').classList.add('active');
      document.body.style.overflow = 'hidden';
    }

    // Close profile modal
    document.getElementById('opmClose').addEventListener('click', () => {
      document.getElementById('orgProfileOverlay').classList.remove('active');
      document.body.style.overflow = '';
    });
    document.getElementById('orgProfileOverlay').addEventListener('click', e => {
      if (e.target === document.getElementById('orgProfileOverlay')) {
        document.getElementById('orgProfileOverlay').classList.remove('active');
        document.body.style.overflow = '';
      }
    });

    // Apply CTA button ? close profile, open application form
    document.getElementById('opmApplyBtn').addEventListener('click', () => {
      document.getElementById('orgProfileOverlay').classList.remove('active');
      openAppForm();
    });

    // -- OPEN APPLICATION FORM MODAL -------------------------------
    function openAppForm() {
      if (!currentOrg) return;
      document.getElementById('afmOrgName').textContent = currentOrg.acronym + ' Â— ' + currentOrg.name;
      resetForm();
      document.getElementById('appFormOverlay').classList.add('active');
      document.body.style.overflow = 'hidden';
    }

    function closeAppForm() {
      document.getElementById('appFormOverlay').classList.remove('active');
      document.body.style.overflow = '';
      resetForm();
    }

    function resetForm() {
      document.getElementById('afmFormBody').style.display = '';
      document.getElementById('afmSuccess').classList.remove('active');
      document.getElementById('afmFooter').style.display = '';
      // Clear all inputs
      document.querySelectorAll('#appFormModal input, #appFormModal select, #appFormModal textarea').forEach(el => {
        if (el.type === 'checkbox') el.checked = false;
        else el.value = '';
      });
    }

    document.getElementById('afmClose').addEventListener('click', closeAppForm);
    document.getElementById('appFormOverlay').addEventListener('click', e => {
      if (e.target === document.getElementById('appFormOverlay')) closeAppForm();
    });

    // -- FORM VALIDATION -------------------------------------------
    function validateForm() {
      let valid = true;
      const required = ['afmFirstName', 'afmLastName', 'afmDob', 'afmSex', 'afmContact', 'afmEmail', 'afmAddress', 'afmStudentId', 'afmYearLevel', 'afmCourse', 'afmMotivation'];
      required.forEach(id => {
        const el = document.getElementById(id);
        if (el && !el.value.trim()) {
          el.classList.add('error');
          valid = false;
        } else if (el) {
          el.classList.remove('error');
        }
      });

      const intentInput = document.getElementById('afmLetterIntent');
      const endorsementInput = document.getElementById('afmLetterEndorsement');
      if (!intentInput || !intentInput.files.length) {
        if (intentInput) intentInput.classList.add('error');
        valid = false;
      } else if (intentInput) {
        intentInput.classList.remove('error');
      }

      if (!endorsementInput || !endorsementInput.files.length) {
        if (endorsementInput) endorsementInput.classList.add('error');
        valid = false;
      } else if (endorsementInput) {
        endorsementInput.classList.remove('error');
      }

      // Checkboxes
      const chks = ['afmChk1', 'afmChk2', 'afmChk3'];
      if (!chks.every(id => document.getElementById(id).checked)) {
        showToast('Please accept all commitment declarations before submitting.', 'error');
        valid = false;
      }

      if (!valid && document.querySelector('.error')) {
        document.querySelector('.error').scrollIntoView({ behavior: 'smooth', block: 'center' });
        showToast('Please fill in all required fields and attach required documents.', 'error');
      }
      return valid;
    }

    // -- SUBMIT HANDLER (Real AJAX with Files) ---------------------
    document.getElementById('afmSubmitBtn')?.addEventListener('click', function () {
      if (!validateForm()) return;

      const clubId = currentOrg ? (currentOrg.club_id || 0) : 0;
      if (!clubId) {
        showToast('This organization is not yet registered in the system. Please contact the SSC.', 'error');
        return;
      }

      this.disabled = true;
      this.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';

      const fd = new FormData();
      fd.set('action', 'apply');
      fd.set('club_id', clubId);
      fd.set('first_name', document.getElementById('afmFirstName')?.value.trim() || '');
      fd.set('last_name', document.getElementById('afmLastName')?.value.trim() || '');
      fd.set('dob', document.getElementById('afmDob')?.value.trim() || '');
      fd.set('sex', document.getElementById('afmSex')?.value || '');
      fd.set('contact', document.getElementById('afmContact')?.value.trim() || '');
      fd.set('email', document.getElementById('afmEmail')?.value.trim() || '');
      fd.set('address', document.getElementById('afmAddress')?.value.trim() || '');
      fd.set('student_id_no', document.getElementById('afmStudentId')?.value.trim() || '');
      fd.set('year_level', document.getElementById('afmYearLevel')?.value || '');
      fd.set('course', document.getElementById('afmCourse')?.value || '');
      fd.set('motivation', document.getElementById('afmMotivation')?.value.trim() || '');

      const intentFile = document.getElementById('afmLetterIntent')?.files[0];
      const endorsementFile = document.getElementById('afmLetterEndorsement')?.files[0];
      if (intentFile) fd.append('letter_intent', intentFile);
      if (endorsementFile) fd.append('letter_endorsement', endorsementFile);


      fetch('../shared/roster_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          this.disabled = false;
          this.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Application';
          if (data.success) {
            document.getElementById('afmFormBody').style.display = 'none';
            document.getElementById('afmFooter').style.display = 'none';
            document.getElementById('afmSuccess').classList.add('active');
            document.getElementById('afmSuccessDownloadBtn').onclick = generatePDF;
          } else {
            showToast(data.message || 'Submission failed. Please try again.', 'error');
          }
        })
        .catch(() => {
          this.disabled = false;
          this.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Application';
          showToast('Network error. Please check your connection.', 'error');
        });
    });

    // -- PDF GENERATION --------------------------------------------
    document.getElementById('afmDownloadBtn').addEventListener('click', generatePDF);

    function generatePDF() {
      if (typeof window.jspdf === 'undefined' && typeof jsPDF === 'undefined') {
        showToast('PDF library not loaded. Please check your internet connection.', 'error');
        return;
      }

      const { jsPDF } = window.jspdf || { jsPDF };
      const doc = new jsPDF({ unit: 'mm', format: 'a4' });

      const orgName = currentOrg ? `${currentOrg.acronym} Â— ${currentOrg.name}` : 'Organization';
      const fName = document.getElementById('afmFirstName').value.trim();
      const lName = document.getElementById('afmLastName').value.trim();
      const mName = document.getElementById('afmMiddleName').value.trim();
      const dob = document.getElementById('afmDob').value;
      const sex = document.getElementById('afmSex').value;
      const contact = document.getElementById('afmContact').value.trim();
      const email = document.getElementById('afmEmail').value.trim();
      const address = document.getElementById('afmAddress').value.trim();
      const studId = document.getElementById('afmStudentId').value.trim();
      const year = document.getElementById('afmYearLevel').value;
      const course = document.getElementById('afmCourse').value.trim();
      const section = document.getElementById('afmSection').value.trim();
      const skills = document.getElementById('afmSkills').value.trim();
      const motiv = document.getElementById('afmMotivation').value.trim();

      const intentFile = document.getElementById('afmLetterIntent').files[0]?.name || 'Attached';
      const endorsementFile = document.getElementById('afmLetterEndorsement').files[0]?.name || 'Attached';

      const pageW = 210, margin = 18, colW = pageW - margin * 2;
      let y = 0;

      // Header band
      doc.setFillColor(26, 58, 140);
      doc.rect(0, 0, pageW, 38, 'F');
      doc.setTextColor(255, 255, 255);
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(14);
      doc.text('BCP CO-CURRICULAR MANAGEMENT SYSTEM', pageW / 2, 13, { align: 'center' });
      doc.setFontSize(9);
      doc.setFont('helvetica', 'normal');
      doc.text('Membership Application Form', pageW / 2, 20, { align: 'center' });
      doc.setFontSize(8);
      doc.text(orgName, pageW / 2, 27, { align: 'center' });
      doc.text('Academic Year 2025Â–2026', pageW / 2, 33, { align: 'center' });

      // Reset text color
      doc.setTextColor(30, 30, 30);
      y = 46;

      function sectionHeader(title) {
        doc.setFillColor(239, 246, 255);
        doc.setDrawColor(191, 219, 254);
        doc.rect(margin, y, colW, 7, 'FD');
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(8);
        doc.setTextColor(26, 58, 140);
        doc.text(title.toUpperCase(), margin + 3, y + 5);
        doc.setTextColor(30, 30, 30);
        y += 10;
      }

      function field(label, value, x, fieldWidth) {
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(7);
        doc.setTextColor(100, 116, 139);
        doc.text(label, x, y);
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(9);
        doc.setTextColor(15, 23, 42);
        const lines = doc.splitTextToSize(value || 'Â—', fieldWidth - 2);
        doc.text(lines, x, y + 4.5);
        return Math.max(lines.length * 4.5 + 4.5, 9);
      }

      function twoFields(l1, v1, l2, v2) {
        const half = colW / 2 - 3;
        const h = Math.max(field(l1, v1, margin, half), field(l2, v2, margin + half + 6, half));
        y += h + 5;
      }

      function oneField(label, value) {
        field(label, value, margin, colW);
        const lines = doc.splitTextToSize(value || 'Â—', colW - 2);
        y += Math.max(lines.length * 4.5 + 4.5, 9) + 5;
      }

      // PERSONAL INFO
      sectionHeader('Personal Information');
      twoFields('First Name', fName, 'Last Name', lName);
      twoFields('Middle Name', mName, 'Date of Birth', dob);
      twoFields('Sex', sex, 'Contact Number', contact);
      oneField('Email Address', email);
      oneField('Permanent Address', address);

      // ACADEMIC INFO
      y += 2; sectionHeader('Academic Information');
      twoFields('Student ID', studId, 'Year Level', year);
      twoFields('Program / Course', course, 'Section', section);

      // SKILLS & MOTIVATION
      y += 2; sectionHeader('Skills & Motivation');
      oneField('Skills & Competencies', skills);
      oneField('Motivation for Joining', motiv);

      // SUBMITTED DOCUMENTS
      y += 2; sectionHeader('Attached Documents');
      twoFields('Letter of Intent', intentFile, 'Letter of Endorsement', endorsementFile);

      // DECLARATION
      y += 2; sectionHeader('Declaration & Agreement');
      doc.setFont('helvetica', 'normal');
      doc.setFontSize(8);
      doc.setTextColor(60, 60, 60);
      doc.text('I certify that all information provided above is true and accurate. I agree to abide by the BCP Student', margin, y);
      doc.text('Handbook and the organization\'s constitution and by-laws.', margin, y + 4);
      y += 14;

      doc.setDrawColor(180, 180, 180);
      doc.line(margin, y, margin + 70, y);
      doc.setFontSize(7.5);
      doc.setTextColor(100, 116, 139);
      doc.text('Applicant Signature over Printed Name', margin, y + 4);

      // Footer
      const now = new Date().toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' });
      doc.setFontSize(7);
      doc.setTextColor(150, 150, 150);
      doc.text(`Date Filed: ${now}`, pageW - margin, y + 4, { align: 'right' });

      doc.setFillColor(26, 58, 140);
      doc.rect(0, 287, pageW, 10, 'F');
      doc.setTextColor(255, 255, 255);
      doc.setFontSize(7);
      doc.text('Bestlink College of the Philippines Â— Co-Curricular Management System', pageW / 2, 293, { align: 'center' });

      const filename = `${(currentOrg?.acronym || 'ORG').replace(/[^a-zA-Z0-9]/g, '_')}_Application_${fName}_${lName}.pdf`;
      doc.save(filename);
      showToast('PDF downloaded successfully!', 'success');
    }

    // -- STUDENT QR MODAL ------------------------------------------
    <?php if ($sess_role === 'student'): ?>
        (function () {
          const qrBtn = document.getElementById('qrFabBtn');
          const overlay = document.getElementById('qrModalOverlay');
          const closeBtn = document.getElementById('closeQrModalBtn');
          let reader = null;

          function open() { overlay.classList.add('active'); }
          function close() { overlay.classList.remove('active'); stop(); }

          if (qrBtn) qrBtn.addEventListener('click', open);
          closeBtn?.addEventListener('click', close);
          overlay?.addEventListener('click', e => { if (e.target === overlay) close(); });
          document.getElementById('startScanBtn')?.addEventListener('click', startScan);

          async function startScan() {
            const v = document.getElementById('qrVideo'), p = document.getElementById('cameraPlaceholder'),
              sl = document.getElementById('qrScannerLine'), btn = document.getElementById('startScanBtn'),
              res = document.getElementById('qrScanResult'), rt = document.getElementById('qrScanText');
            if (typeof ZXing === 'undefined') { alert('Scanner not loaded.'); return; }
            try {
              reader = new ZXing.BrowserQRCodeReader();
              btn.textContent = 'Scanning...'; btn.disabled = true;
              v.style.display = 'block'; p.style.display = 'none'; sl.style.display = 'block';
              const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
              v.srcObject = stream;
              reader.decodeFromVideoElement(v, result => { if (result) { rt.textContent = result.getText(); res.classList.add('active'); } });
            } catch (e) { alert('Camera access denied.'); btn.textContent = 'Start Camera Scanner'; btn.disabled = false; }
          }

          function stop() {
            if (reader) { reader.reset(); reader = null; }
            const v = document.getElementById('qrVideo');
            if (v?.srcObject) { v.srcObject.getTracks().forEach(t => t.stop()); v.srcObject = null; }
            const p = document.getElementById('cameraPlaceholder'), sl = document.getElementById('qrScannerLine'), btn = document.getElementById('startScanBtn');
            if (v) v.style.display = 'none'; if (p) p.style.display = 'flex'; if (sl) sl.style.display = 'none';
            if (btn) { btn.textContent = 'Start Camera Scanner'; btn.disabled = false; }
          }
        })();

      function switchQrTab(tab) {
        document.querySelectorAll('.qr-tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.qr-tab-panel').forEach(p => p.classList.remove('active'));
        document.getElementById(tab === 'myqr' ? 'tabMyQr' : 'tabScan').classList.add('active');
        document.getElementById(tab === 'myqr' ? 'panelMyQr' : 'panelScan').classList.add('active');
      }
      function openBroadcastModal(clubId, orgName) {
        const title = prompt(`Post Official Announcement for ${orgName}:\nTitle:`);
        if (!title) return;
        const message = prompt(`Announcement Details / Content:`);
        if (!message) return;

        const formData = new FormData();
        formData.append('action', 'broadcast');
        formData.append('club_id', clubId);
        formData.append('title', title);
        formData.append('message', message);

        fetch('../shared/notification_actions.php', { method: 'POST', body: formData })
          .then(res => res.json())
          .then(data => {
            if (data.success) {
              alert('? Announcement successfully posted to active organization members!');
            } else {
              alert('Error: ' + data.message);
            }
          })
          .catch(err => alert('Network error posting announcement.'));
      }
    <?php endif; ?>
  </script>
</body>

</html>

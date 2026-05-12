<?php
// 1. HARDENED SESSION INITIALIZATION
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

require_once 'config.php';
requireRole(['director', 'admission_officer']);
$pageTitle = 'Biometric Command Station';
$db = getDB();
$user = currentUser();

// Generate Token if missing
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

// PRE-INITIALIZE LISTS TO PREVENT FOREACH WARNINGS
$recentEnrolled = [];
$unenrolledList = [];

/* =====================================================
   [A] AJAX HANDLER - SECURE INGEST
   ===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');

    // CSRF VERIFICATION (CRITICAL FIX)
    $sentToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $sentToken)) {
        echo json_encode(['success' => false, 'message' => 'Security Handshake Mismatch (CSRF Error). Please refresh the page.']);
        exit;
    }

    $sid           = (int)($_POST['student_id']     ?? 0);
    $fpTemplateB64 = trim($_POST['fp_template']     ?? '');  
    $deviceModel   = trim($_POST['device_model']    ?? 'Terminal Bridge');
    $deviceSerial  = trim($_POST['device_serial']   ?? 'SYS-V2');
    $isSimulation  = isset($_POST['is_simulation']) ? true : false;

    if (!$sid || !$fpTemplateB64) {
        echo json_encode(['success' => false, 'message' => 'Data incomplete: Student ID or Fingerprint data missing.']);
        exit;
    }

    try {
        $stmt = $db->prepare("SELECT id, full_name, admission_number FROM students WHERE id=? AND status='active'");
        $stmt->execute([$sid]);
        $student = $stmt->fetch();

        if (!$student) {
            echo json_encode(['success' => false, 'message' => 'Selected identity is no longer active in database.']);
            exit;
        }

        // Logic: Standardize Fingerprint Matching Index
        // Real captures are base64-decoded binaries. Sim simulations stay as string payloads.
        $fpHash = hash('sha256', ($isSimulation ? $fpTemplateB64 : base64_decode($fpTemplateB64)));

        $db->prepare("UPDATE students SET 
                      fingerprint_template = ?, fingerprint_hash = ?, 
                      fp_device_model = ?, fp_device_serial = ?, 
                      fp_enrolled_at = NOW() 
                      WHERE id = ?")
           ->execute([$fpTemplateB64, $fpHash, $deviceModel, $deviceSerial, $sid]);

        echo json_encode([
            'success' => true, 
            'message' => 'Successfully bound to Fingerprint: ' . $student['full_name'],
            'student_name' => $student['full_name'],
            'enrolled_at'  => date('d M, h:i A')
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Archive Conflict: Could not save biometric data.']);
        exit;
    }
}

/* =====================================================
   [B] AJAX - INSTANT SEARCH
   ===================================================== */
if (isset($_GET['search_ajax'])) {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode(['students' => []]); exit; }
    $stmt = $db->prepare("SELECT s.id, s.full_name, s.admission_number, s.nin, s.fingerprint_template, c.class_name 
                          FROM students s LEFT JOIN classes c ON c.id=s.class_id 
                          WHERE (s.full_name LIKE ? OR s.admission_number LIKE ?) AND s.status='active' LIMIT 10");
    $stmt->execute(["%$q%","%$q%"]);
    echo json_encode(['students' => $stmt->fetchAll()]);
    exit;
}

// Stats and Lists
$totalAct   = (int)$db->query("SELECT COUNT(*) FROM students WHERE status='active'")->fetchColumn();
$totalEnr   = (int)$db->query("SELECT COUNT(*) FROM students WHERE fingerprint_template IS NOT NULL AND status='active'")->fetchColumn();
$covRate    = $totalAct > 0 ? round(($totalEnr / $totalAct) * 100) : 0;

$recentEnrolled = $db->query("SELECT s.*, c.class_name FROM students s LEFT JOIN classes c ON c.id=s.class_id WHERE s.fingerprint_template IS NOT NULL AND s.status='active' ORDER BY s.fp_enrolled_at DESC LIMIT 10")->fetchAll();
$unenrolledList = $db->query("SELECT s.*, c.class_name FROM students s LEFT JOIN classes c ON c.id=s.class_id WHERE s.fingerprint_template IS NULL AND s.status='active' ORDER BY s.full_name ASC LIMIT 15")->fetchAll();

include 'layout_header.php';
?>

<div class="page-header d-flex flex-column flex-md-row justify-content-between align-items-center mb-5">
    <div>
        <h1 class="fw-black Syne text-navy h2 mb-1">STATION INITIALISATION</h1>
        <p class="text-muted small">Managed secure bridge between hardware sensors and cloud storage.</p>
    </div>
    <div class="d-flex align-items-center gap-3 bg-white p-2 pe-4 shadow-sm rounded-pill border">
         <div class="bg-navy rounded-circle d-flex align-items-center justify-content-center" style="width:40px; height:40px;">
            <i class="bi bi-shield-shaded text-accent fs-5"></i>
         </div>
         <div>
            <small class="fs-tiny fw-bold text-muted d-block" style="line-height:1">ARCHIVE HEALTH</small>
            <b class="Syne fs-6 text-navy"><?= $covRate ?>% COVERAGE</b>
         </div>
    </div>
</div>

<div class="row g-4">
    <!-- PANEL: STATION INPUT -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-lg rounded-5 overflow-hidden">
            <div class="p-1 bg-light d-flex border-bottom">
                <button class="flex-fill border-0 py-3 small fw-bold tracking-widest text-navy bg-white" id="tabH" onclick="setMode('hardware')">HARDWARE ENGINE</button>
                <button class="flex-fill border-0 py-3 small fw-bold tracking-widest text-muted bg-light" id="tabS" onclick="setMode('sim')">ID SIMULATOR</button>
            </div>

            <div class="card-body p-4 p-xl-5">
                <!-- Search bar -->
                <div class="mb-4">
                    <label class="fs-tiny fw-bold text-navy opacity-50 ms-3">SEARCH SECURE IDENTITY</label>
                    <div class="input-group input-group-lg shadow-sm">
                        <span class="input-group-text bg-white border-0 rounded-start-pill ps-4"><i class="bi bi-search"></i></span>
                        <input type="text" id="traceInput" class="form-control border-0 rounded-end-pill py-3" placeholder="Input Student Admission String...">
                    </div>
                    <div id="dropBox" class="dropdown-menu shadow-lg border-0 w-100 rounded-4 mt-2" style="max-height:300px; overflow-y:auto; display:none"></div>
                </div>

                <!-- Entity Focused -->
                <div id="target_id" class="p-4 rounded-4 mb-5 border-dashed" style="display:none; border:2px dashed #e2e8f0">
                    <div class="d-flex align-items-center gap-3">
                         <div class="bg-navy rounded-circle text-accent Syne d-flex align-items-center justify-content-center h4 mb-0 fw-black" style="width:55px;height:55px;" id="avatar_letter">?</div>
                         <div class="flex-grow-1"><div class="fw-black text-navy h5 mb-0" id="name_box">AWAITING...</div><code class="fs-tiny text-primary" id="adm_box">TRACING IDENTIFIERS...</code></div>
                         <div id="existing_badge"></div>
                    </div>
                </div>

                <!-- INTERFACE -->
                <div id="hardware_gui" class="text-center py-4">
                    <div class="radar-shell mx-auto mb-4" id="scan_radar" onclick="triggerCapt()">
                         <div class="radar-scanner"></div><i class="bi bi-fingerprint"></i>
                    </div>
                    <h5 class="fw-bold text-navy Syne" id="status_info">GATEWAY OFFLINE</h5>
                    <p class="text-muted small">Choose a student above and connect the sensor bridge.</p>
                </div>

                <div id="simulation_gui" class="d-none text-center py-5">
                     <div class="rounded-5 bg-navy text-white p-4 mx-3">
                         <i class="bi bi-keyboard-fill text-accent display-2 opacity-50"></i>
                         <h5 class="Syne fw-bold text-accent mt-3 mb-1">MANUAL SEED GENERATOR</h5>
                         <p class="small text-white-50 px-4">Generate unique 256-bit override strings based on ID paths.</p>
                     </div>
                </div>

                <button class="btn btn-navy w-100 py-3 rounded-pill fw-black shadow shadow-lg mt-5" id="enrol_btn" disabled onclick="executeEnrolment()">
                    ACTIVATE INITIALISATION <i class="bi bi-arrow-right-short"></i>
                </button>
                
                <div id="msg_panel" class="mt-4" style="display:none"></div>
            </div>
        </div>
    </div>

    <!-- PANEL: REALTIME REGISTRY -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm rounded-5 h-100 overflow-hidden d-flex flex-column">
             <div class="terminal-header bg-navy text-white p-3 px-4 d-flex justify-content-between">
                <span class="small fw-bold Syne"><i class="bi bi-cloud-check-fill text-accent me-2"></i>SECURE DATA STREAM</span>
                <span class="fs-tiny fw-bold text-accent">BRIDGE ACTIVE</span>
             </div>
             
             <!-- PENDING LIST -->
             <div class="bg-light p-3 border-bottom d-flex justify-content-between align-items-center">
                 <h6 class="fs-tiny fw-black text-navy mb-0">IDENTITY GAP (TOP 10)</h6>
                 <div class="badge bg-danger text-white rounded-pill" style="font-size:10px"><?= count($unenrolledList) ?> GAP</div>
             </div>
             <div class="list-group list-group-flush overflow-auto flex-grow-1" style="max-height:350px">
                <?php foreach($unenrolledList as $s): ?>
                <div class="list-group-item d-flex align-items-center gap-3 p-3">
                     <div class="bg-white rounded border d-flex align-items-center justify-content-center fw-bold small text-muted shadow-sm" style="width:36px; height:36px"><?= substr($s['full_name'],0,1) ?></div>
                     <div class="flex-grow-1"><div class="fw-black text-navy small"><?= $s['full_name'] ?></div><span class="fs-tiny opacity-50 fw-bold"><?= $s['admission_number'] ?> · <?= $s['class_name'] ?></span></div>
                     <button class="btn btn-navy btn-sm fs-tiny px-3 rounded-pill fw-bold" onclick='loadEntity(<?= json_encode($s) ?>)'>LOAD</button>
                </div>
                <?php endforeach; ?>
             </div>

             <!-- SUCCESS LOG -->
             <div class="bg-light p-3 border-bottom border-top text-center"><h6 class="fs-tiny fw-black text-muted mb-0">RECENT SESSION BINDS</h6></div>
             <div class="list-group list-group-flush bg-light bg-opacity-25" id="real_logs">
                <?php foreach($recentEnrolled as $re): ?>
                <div class="list-group-item p-3 bg-transparent d-flex align-items-center gap-3">
                     <div class="rounded bg-success bg-opacity-10 p-2"><i class="bi bi-shield-check text-success fs-5"></i></div>
                     <div><div class="fw-black text-navy small"><?= $re['full_name'] ?></div><small class="fs-tiny text-muted"><?= date('H:i:s', strtotime($re['fp_enrolled_at'])) ?> VIA <?= strtoupper($re['fp_device_model']) ?></small></div>
                </div>
                <?php endforeach; ?>
             </div>
        </div>
    </div>
</div>

<style>
/* CSS TERMINAL SKIN */
:root { --navy: #071829; --accent: #f4be38; }
body { background: #f0f4f8; font-family: 'Inter', system-ui; }
.Syne { font-family: 'Inter', 'Syne', sans-serif !important; letter-spacing: -0.01em; }
.fw-black { font-weight: 900 !important; }
.bg-navy { background-color: var(--navy) !important; }
.text-navy { color: var(--navy) !important; }
.fs-tiny { font-size: 10px !important; text-transform: uppercase; letter-spacing: 0.1em; }
.radar-shell { 
    width: 140px; height: 140px; border-radius: 50%; border: 6px solid #e2e8f0; display:flex; 
    align-items:center; justify-content:center; cursor:pointer; background: white; transition: 0.3s; position:relative;
}
.radar-shell:hover { transform: scale(1.03); border-color: var(--accent); }
.radar-shell i { font-size: 3.5rem; color: #dee2e6; z-index: 10; }
.radar-shell.active { border-color: #3ecf8e; box-shadow: 0 0 25px rgba(62,207,142,0.3); }
.radar-shell.active i { color: #10b981; }
.radar-scanner { position:absolute; inset:0; border-radius: 50%; background: linear-gradient(var(--accent), transparent); opacity: 0; pointer-events: none;}
.radar-shell.active .radar-scanner { opacity: 0.15; animation: rotate 2s linear infinite; }
@keyframes rotate { 0% {transform: rotate(0deg)} 100% {transform: rotate(360deg)} }
</style>

<script>
/**
 * EMS SECURITY STATION JS
 */
const CONFIG = { current_user: null, current_mode: "hardware" };

// Tab Toggle
function setMode(m) {
    CONFIG.current_mode = m;
    document.getElementById('hardware_gui').classList.toggle('d-none', m === 'sim');
    document.getElementById('simulation_gui').classList.toggle('d-none', m === 'hardware');
    document.getElementById('tabH').className = `flex-fill border-0 py-3 small fw-bold tracking-widest ${m === 'hardware' ? 'bg-white text-navy' : 'bg-light text-muted'}`;
    document.getElementById('tabS').className = `flex-fill border-0 py-3 small fw-bold tracking-widest ${m === 'sim' ? 'bg-white text-navy' : 'bg-light text-muted'}`;
}

// Student Search Engine
document.getElementById('traceInput').addEventListener('input', async (e) => {
    const box = document.getElementById('dropBox');
    if(e.target.value.length < 2) return box.style.display="none";
    
    const r = await fetch(`?search_ajax=1&q=${encodeURIComponent(e.target.value)}`);
    const d = await r.json();
    if(d.students.length > 0) {
        box.innerHTML = d.students.map(s => `<button class="dropdown-item p-3 border-bottom d-flex align-items-center gap-3" onclick='loadEntity(${JSON.stringify(s)})'>
            <div class="bg-navy text-accent p-2 px-3 rounded fw-bold Syne">${s.full_name[0]}</div>
            <div><b class="text-navy">${s.full_name}</b><br><small class="fs-tiny opacity-50">${s.admission_number}</small></div>
            </button>`).join("");
        box.style.display = "block";
    }
});

function loadEntity(s) {
    CONFIG.current_user = s;
    document.getElementById('dropBox').style.display="none";
    document.getElementById('traceInput').value = s.full_name;
    document.getElementById('target_id').style.display="block";
    document.getElementById('name_box').innerText = s.full_name;
    document.getElementById('adm_box').innerText = s.admission_number;
    document.getElementById('avatar_letter').innerText = s.full_name[0];
    document.getElementById('enrol_btn').disabled = false;
    document.getElementById('existing_badge').innerHTML = s.fingerprint_template ? '<span class="badge bg-warning text-dark px-3 rounded-pill fw-bold">UPDATE</span>' : '<span class="badge bg-danger text-white px-3 rounded-pill fw-bold">INIT</span>';
}

/**
 * BIO ENGINE CONTROLLER (Hardware support)
 */
async function triggerCapt() {
    if(!CONFIG.current_user) return alert("Trace identity first.");
    executeEnrolment();
}

async function executeEnrolment() {
    const btn = document.getElementById('enrol_btn');
    const feedback = document.getElementById('msg_panel');
    const radar = document.getElementById('scan_radar');
    
    btn.disabled = true;
    radar.classList.add('active');
    document.getElementById('status_info').innerText = "LINKING SENSOR SIGNAL...";

    let fp_payload = "";
    let hardware = "BioEmulator_V1";
    
    if(CONFIG.current_mode === "hardware") {
        try {
             /** 
              * HARDWARE CAPTURE FLOW (Logic as discussed in troubleshooting)
              * This block probe Mantra / Futronic / DP based on detected SDK
              */
              // Simulate Mantra Call for now (You must bridge this to your previous SDK functions)
              const simulateCapture = await new Promise(r => setTimeout(() => r("Mantra_Binary_Buffer_String"), 2000));
              fp_payload = simulateCapture;
              hardware = "Mantra MFS100";
        } catch(e) {
            radar.classList.remove('active');
            alert("Signal Fault: Verify USB Port or Driver Bridge Service.");
            btn.disabled = false; return;
        }
    } else {
        // simulation override logic
        fp_payload = btoa(`EM-SEED-${CONFIG.current_user.id}-${Date.now()}`);
        hardware = "Override Override System";
    }

    // DISPATCH TO BACKEND
    const params = new URLSearchParams();
    params.append('ajax', '1');
    params.append('student_id', CONFIG.current_user.id);
    params.append('fp_template', fp_payload);
    params.append('device_model', hardware);
    params.append('csrf_token', "<?= $_SESSION['csrf_token'] ?>");
    if(CONFIG.current_mode === "sim") params.append('is_simulation', '1');

    try {
        const response = await fetch("fingerprint.php", {
            method: 'POST',
            body: params
        });
        const out = await response.json();
        
        feedback.style.display="block";
        feedback.innerHTML = `<div class="alert animate__animated animate__zoomIn border-0 shadow rounded-4 p-4 ${out.success ? 'bg-success text-white' : 'bg-danger text-white'}">
            <h6 class="fw-bold mb-0 Syne">${out.message}</h6>
        </div>`;

        if(out.success) {
            document.getElementById('status_info').innerText = "RECORD BOUND";
            setTimeout(() => { location.reload(); }, 2500);
        } else {
            btn.disabled = false;
        }
    } catch(err) {
        alert("Archive Conflict: Signal Transmission Error.");
    } finally {
        radar.classList.remove('active');
    }
}
</script>

<?php include 'layout_footer.php'; ?>
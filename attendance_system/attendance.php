<?php
require_once 'config.php';
requireLogin();

$db   = getDB();
$user = currentUser();
$view = $_GET['view'] ?? 'mark';
$todayDate = date('Y-m-d');

// CSRF & Security initialization
if (empty($_SESSION['csrf_token'])) { 
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); 
}

/* =========================================================
   1. AJAX BACKEND (HARDENED LOGIC)
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    // Security Token Validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Terminal Handshake Failed.']);
        exit;
    }

    $method = $_POST['method'] ?? '';
    $student = null;

    try {
        if ($method === 'biometric') {
            $rawTemplate = trim($_POST['fp_template'] ?? '');
            if (!empty($rawTemplate)) {
                $hash = hash('sha256', base64_decode($rawTemplate));
                $stmt = $db->prepare("SELECT s.*, c.class_name FROM students s 
                                      LEFT JOIN classes c ON c.id=s.class_id 
                                      WHERE s.fingerprint_hash=? AND s.status='active'");
                $stmt->execute([$hash]);
                $student = $stmt->fetch();
            }
        } 
        elseif ($method === 'id_code') {
            $adm = strtoupper(trim($_POST['admission_number'] ?? ''));
            $stmt = $db->prepare("SELECT s.*, c.class_name FROM students s 
                                  LEFT JOIN classes c ON c.id=s.class_id 
                                  WHERE s.admission_number=? AND s.status='active'");
            $stmt->execute([$adm]);
            $student = $stmt->fetch();
        }

        if (!$student) {
            echo json_encode(['success' => false, 'message' => 'Identity not found / Access denied.']);
            exit;
        }

        $check = $db->prepare("SELECT id, time_in FROM attendance WHERE student_id=? AND attendance_date=?");
        $check->execute([$student['id'], $todayDate]);
        $existing = $check->fetch();

        if ($existing) {
            $db->prepare("UPDATE attendance SET time_out=NOW() WHERE id=?")->execute([$existing['id']]);
            $type = "EXIT"; $color = "info"; $msg = "Farewell, " . explode(' ', $student['full_name'])[0];
        } else {
            $db->prepare("INSERT INTO attendance (student_id, attendance_date, time_in, status, method, marked_by) 
                          VALUES(?,?,NOW(),'present',?,?)")
               ->execute([$student['id'], $todayDate, $method, $user['id']]);
            $type = "ENTRY"; $color = "success"; $msg = "Identity Verified: " . explode(' ', $student['full_name'])[0];
        }

        echo json_encode([
            'success' => true,
            'message' => $msg,
            'log' => [
                'name'  => $student['full_name'],
                'path'  => $student['class_name'] ?? 'General Access',
                'time'  => date('H:i'),
                'type'  => $type,
                'color' => $color
            ]
        ]);
        exit;

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'System Infrastructure Error.']);
        exit;
    }
}

/* =========================================================
   2. PAGE DATA ASSEMBLY (Warn-Free)
   ========================================================= */
$classes = $db->query("SELECT * FROM classes ORDER BY class_name ASC")->fetchAll();
$historyRecords = []; // Prevents the Foreach error on marking view

if ($view === 'history') {
    $fDate = $_GET['date'] ?? $todayDate;
    $fCls  = (int)($_GET['class_id'] ?? 0);
    $params = [$fDate];
    $sql = "SELECT a.*, s.full_name, s.admission_number, c.class_name 
            FROM attendance a 
            JOIN students s ON s.id=a.student_id 
            LEFT JOIN classes c ON c.id=s.class_id 
            WHERE a.attendance_date = ? ";
    
    if($fCls > 0) { $sql .= " AND s.class_id = ?"; $params[] = $fCls; }
    if ($user['role'] === 'class_master') { $sql .= " AND s.class_id = ?"; $params[] = $user['class_id']; }

    $stmt = $db->prepare($sql . " ORDER BY a.created_at DESC");
    $stmt->execute($params);
    $historyRecords = $stmt->fetchAll();
}

$pTally = $db->query("SELECT COUNT(*) FROM attendance WHERE attendance_date=CURDATE() AND status='present'")->fetchColumn();

include 'layout_header.php';
?>

<!-- STYLE OVERRIDES -->
<style>
    :root { --p-navy: #071829; --p-gold: #f4be38; --bg-glass: rgba(255, 255, 255, 0.7); }
    body { background-color: #f4f7f6; font-family: 'Inter', system-ui, -apple-system, sans-serif; }
    .Syne { font-family: 'Syne', sans-serif !important; }
    .fw-black { font-weight: 900 !important; }
    .card-shell { background: white; border: 0; border-radius: 24px; box-shadow: 0 10px 30px -5px rgba(0,0,0,0.05); }
    
    /* Advanced Radar UI */
    .radar-circle {
        width: 180px; height: 180px; border-radius: 50%;
        background: #fff; border: 6px solid #e2e8f0; position: relative;
        display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }
    .radar-circle:hover { border-color: var(--p-gold); transform: scale(1.05); }
    .radar-circle i { font-size: 4rem; color: #cbd5e1; transition: 0.3s; }
    .radar-circle.active { border-color: #10b981; box-shadow: 0 0 30px rgba(16,185,129,0.3); }
    .radar-circle.active i { color: #10b981; animation: ion-pulse 2s infinite; }
    
    .scanner-line {
        position: absolute; width: 100%; height: 3px; background: var(--p-gold); 
        top: 20%; display: none; opacity: 0.8; z-index: 5; box-shadow: 0 0 10px var(--p-gold);
    }
    .active .scanner-line { display: block; animation: scanMove 2.5s infinite linear; }
    @keyframes scanMove { 0%, 100% { top: 20%; opacity:0; } 50% { top: 80%; opacity:1; } }

    /* Layout Component */
    .terminal-header { background: var(--p-navy); border-radius: 20px 20px 0 0; padding: 20px 30px; color: #fff; }
    .live-stream-container { background: white; border-radius: 0 0 20px 20px; overflow: hidden; height: 400px; overflow-y: auto; }
    .log-item { border-bottom: 1px solid #f1f5f9; padding: 15px 25px; transition: 0.3s; }
    .log-item:hover { background-color: #f8fafc; }
    .badge-capsule { padding: 4px 15px; border-radius: 50px; font-weight: 800; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
</style>

<div class="container-fluid py-4 px-lg-5">

<?php if ($view === 'mark'): ?>
    <!-- MODULE: GATEWAY CONTROLLER -->
    <div class="row align-items-center mb-5">
        <div class="col">
            <h1 class="fw-black Syne text-navy h2 mb-0 text-uppercase">Logistics Interface</h1>
            <p class="text-muted small">Terminal Identification & Station Validation · Secure V2</p>
        </div>
        <div class="col-auto">
            <div class="d-flex align-items-center bg-white shadow-sm p-2 pe-4 rounded-pill border">
                <div class="rounded-circle bg-navy d-flex align-items-center justify-content-center text-accent me-3" style="width:42px; height:42px;">
                    <i class="bi bi-person-check fs-5"></i>
                </div>
                <div><span class="text-muted fs-tiny fw-bold d-block" style="line-height:1">SESSIONS COMPLETED</span><b class="Syne fs-5" id="total_p"><?= $pTally ?></b></div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- THE VALIDATION CORE -->
        <div class="col-lg-7">
            <div class="card card-shell h-100 overflow-hidden">
                <div class="terminal-header d-flex justify-content-between align-items-center">
                    <div><span class="fs-tiny opacity-50 fw-bold">SUBSYSTEM STATUS</span><h6 class="mb-0 Syne">GATE-ALPHA DETECTED</h6></div>
                    <div id="bridge_status" class="badge bg-white bg-opacity-10 text-gold fw-black border border-white border-opacity-10 px-3">DRIVER: ACTIVE</div>
                </div>
                
                <div class="card-body p-5">
                    <div class="nav nav-pills justify-content-center mb-5 gap-3">
                        <button id="tabB" class="btn btn-navy rounded-pill px-4 py-2 fw-black active shadow" onclick="changeMode('biometric')"><i class="bi bi-fingerprint me-2"></i>BIOMETRIC SENSOR</button>
                        <button id="tabM" class="btn btn-light border text-navy rounded-pill px-4 py-2 fw-black" onclick="changeMode('manual')"><i class="bi bi-terminal me-2"></i>MANUAL TRACE</button>
                    </div>

                    <!-- BIO SCREEN -->
                    <div id="area_bio" class="text-center animate__animated animate__fadeIn">
                        <div class="radar-circle mx-auto mb-4" id="v_circle" onclick="execGateway('biometric')">
                            <div class="scanner-line"></div>
                            <i class="bi bi-fingerprint"></i>
                        </div>
                        <h4 class="fw-black Syne text-navy" id="status_msg">TOUCH SENSOR</h4>
                        <p class="text-muted small opacity-75">Authenticated high-speed identity matching protocol</p>
                    </div>

                    <!-- MANUAL SCREEN -->
                    <div id="area_manual" class="d-none text-center animate__animated animate__fadeIn">
                        <div class="p-4 bg-light rounded-5 border mb-3">
                            <h6 class="fs-tiny fw-black text-muted mb-4 tracking-widest">INPUT SYSTEM ADMISSION STRING</h6>
                            <div class="mx-auto" style="max-width:320px;">
                                <input type="text" id="adm_input" class="form-control text-center Syne rounded-pill border-navy shadow-sm py-3 mb-3 fs-5" placeholder="E-M-S/2026/000">
                                <button class="btn btn-accent btn-lg w-100 rounded-pill fw-black shadow text-navy py-3" onclick="execGateway('manual')">AUTHORIZE IDENT</button>
                            </div>
                        </div>
                    </div>

                    <div id="result_console" class="mt-4" style="min-height: 50px;"></div>
                </div>
            </div>
        </div>

        <!-- THE INTERACTION LOG -->
        <div class="col-lg-5">
            <div class="card card-shell h-100 overflow-hidden border">
                <div class="p-3 px-4 border-bottom bg-white"><h6 class="mb-0 fw-black text-navy small">SYSTEM AUDIT STREAM</h6></div>
                <div class="live-stream-container" id="stream_feed">
                    <?php
                    $todayFeed = $db->query("SELECT a.*, s.full_name, c.class_name FROM attendance a 
                                            JOIN students s ON s.id=a.student_id 
                                            LEFT JOIN classes c ON c.id=s.class_id 
                                            WHERE a.attendance_date=CURDATE() ORDER BY a.created_at DESC LIMIT 8")->fetchAll();
                    foreach($todayFeed as $f): ?>
                        <div class="log-item d-flex align-items-center gap-3">
                            <div class="rounded-4 bg-navy text-accent Syne d-flex align-items-center justify-content-center fw-black" style="width:40px;height:40px; font-size:12px">
                                <?= substr($f['full_name'], 0, 1) ?>
                            </div>
                            <div class="flex-grow-1">
                                <div class="fw-bold text-navy mb-0" style="font-size:13.5px"><?= htmlspecialchars($f['full_name']) ?></div>
                                <span class="text-muted fs-tiny fw-bold opacity-75"><?= htmlspecialchars($f['class_name'] ?? 'NO CLASS') ?> · <?= date('h:i:s A', strtotime($f['time_in'])) ?></span>
                            </div>
                            <span class="badge-capsule bg-success-subtle text-success border">IN</span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="card-footer bg-light p-3 text-center border-0">
                    <a href="?view=history" class="small fw-black text-navy opacity-50 text-decoration-none">ACCESS HISTORICAL RECORDS <i class="bi bi-arrow-right"></i></a>
                </div>
            </div>
        </div>
    </div>

<?php else: /* RE-IMPROVED HISTORY UI */ ?>
    <div class="page-header d-flex justify-content-between align-items-center mb-4">
        <div><h1 class="fw-black Syne text-navy mb-0">Record Audit</h1><p class="text-muted small">Verification Archive Search</p></div>
        <div class="d-flex gap-2">
            <button class="btn btn-navy btn-sm px-4 rounded-pill shadow" onclick="window.print()"><i class="bi bi-printer-fill me-2"></i>Export Table</button>
            <a href="?view=mark" class="btn btn-outline-secondary btn-sm px-4 rounded-pill border">Switch Gateway</a>
        </div>
    </div>

    <div class="card card-shell bg-light mb-4">
        <div class="card-body p-4">
             <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="view" value="history">
                <div class="col-md-3"><label class="fs-tiny fw-bold text-navy mb-2">TARGET DATE</label><input type="date" name="date" class="form-control border-0 shadow-sm rounded-3" value="<?= $filterDate ?? '' ?>"></div>
                <div class="col-md-4"><label class="fs-tiny fw-bold text-navy mb-2">CLUSTER SELECTION</label><select name="class_id" class="form-select border-0 shadow-sm rounded-3">
                    <option value="">Full Enrolment Roll</option><?php foreach($classes as $c): ?><option value="<?=$c['id']?>"><?=$c['class_name']?></option><?php endforeach; ?></select></div>
                <div class="col-md-2"><button type="submit" class="btn btn-navy w-100 rounded-pill py-2 shadow fw-black">RUN TRACE</button></div>
             </form>
        </div>
    </div>

    <div class="card card-shell overflow-hidden">
        <div class="table-responsive">
            <table class="table mb-0 table-hover align-middle">
                <thead class="bg-navy text-white text-uppercase fs-tiny tracking-widest">
                    <tr><th class="p-3 ps-4 border-0">Legal Entity</th><th>Identity No.</th><th>Site Cluster</th><th>Validated Time</th><th class="text-center">Integrity Result</th></tr>
                </thead>
                <tbody>
                    <?php foreach($historyRecords as $hr): ?>
                        <tr>
                            <td class="p-3 ps-4"><div class="fw-bold text-navy"><?= $hr['full_name'] ?></div></td>
                            <td><code><?= $hr['admission_number'] ?></code></td>
                            <td><span class="badge bg-secondary bg-opacity-10 text-muted fw-bold"><?= $hr['class_name'] ?></span></td>
                            <td class="text-muted small fw-bold"><i class="bi bi-clock me-1"></i><?= date('H:i', strtotime($hr['time_in'])) ?></td>
                            <td class="text-center"><span class="badge rounded-pill bg-success px-4 fs-tiny fw-black text-white shadow-sm">VERIFIED PRESENT</span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if(!$historyRecords): ?><tr><td colspan="5" class="text-center p-5 text-muted small fw-bold Syne">ZERO MATCHES FOR SEARCH PARAMETERS</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

</div>

<script>
/**
 * STATION MANAGEMENT JAVASCRIPT
 */
function changeMode(mode) {
    document.getElementById('area_bio').classList.toggle('d-none', mode === 'manual');
    document.getElementById('area_manual').classList.toggle('d-none', mode === 'biometric');
    document.getElementById('tabB').classList.toggle('active', mode === 'biometric');
    document.getElementById('tabB').classList.toggle('btn-light', mode === 'manual');
    document.getElementById('tabM').classList.toggle('active', mode === 'manual');
    document.getElementById('tabM').classList.toggle('btn-light', mode === 'biometric');
    if(mode === 'manual') document.getElementById('adm_input').focus();
}

async function execGateway(mode) {
    const feedback = document.getElementById('result_console');
    const ring = document.getElementById('v_circle');
    const btnText = document.getElementById('status_msg');
    
    // Prep variables for either biometric template or ID code
    let params = `ajax=1&csrf_token=<?= $_SESSION['csrf_token'] ?>`;
    
    if (mode === 'biometric') {
        ring.classList.add('active');
        btnText.innerText = "AUTHENTICATING...";
        // Note: Real hardware implementation here (Mantra/DP logic)
        // const scanData = await SDK.capture();
        // params += `&method=biometric&fp_template=${scanData}`;
        return; // Temporary block until SDK logic is mapped
    } else {
        const idVal = document.getElementById('adm_input').value;
        if(!idVal) return;
        params += `&method=id_code&admission_number=${encodeURIComponent(idVal)}`;
        document.getElementById('adm_input').value = "";
    }

    try {
        const res = await fetch("attendance.php", {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: params
        });
        const d = await res.json();
        
        feedback.innerHTML = `<div class="alert animate__animated animate__fadeIn py-3 text-center border-0 rounded-4 text-white shadow fw-black ${d.success?'bg-success':'bg-danger'}">${d.message}</div>`;
        
        if (d.success) {
            document.getElementById('total_p').innerText = parseInt(document.getElementById('total_p').innerText) + 1;
            renderToStream(d.log);
        }
    } catch(e) {
        alert("CRITICAL ERROR: Terminal Connectivity Fault.");
    }
}

function renderToStream(log) {
    const feed = document.getElementById('stream_feed');
    const el = document.createElement('div');
    el.className = "log-item animate__animated animate__slideInLeft d-flex align-items-center gap-3 bg-light border-start border-4 border-success";
    el.innerHTML = `
        <div class="rounded-4 bg-navy text-accent Syne d-flex align-items-center justify-content-center fw-black" style="width:40px;height:40px">${log.name[0]}</div>
        <div class="flex-grow-1"><div class="fw-bold text-navy small mb-0">${log.name}</div><span class="fs-tiny opacity-50 fw-bold">AUTHORIZED SESSION</span></div>
        <span class="badge-capsule bg-success text-white">ENTRY</span>
    `;
    feed.prepend(el);
}
</script>

<?php include 'layout_footer.php'; ?>
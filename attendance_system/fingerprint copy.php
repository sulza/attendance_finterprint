<?php
require_once 'config.php';
requireRole(['director', 'admission_officer']);
$pageTitle = 'Fingerprint Enrolment';
$db   = getDB();
$user = currentUser();

/* =====================================================
   AJAX — SAVE ENROLLED TEMPLATE FROM REAL HARDWARE
   ===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    $sid           = (int)($_POST['student_id']     ?? 0);
    $fpTemplateB64 = trim($_POST['fp_template']     ?? '');  // base64 from SDK
    $deviceModel   = trim($_POST['device_model']    ?? 'Unknown');
    $deviceSerial  = trim($_POST['device_serial']   ?? '');

    if (!$sid)          jsonResponse(['success'=>false,'message'=>'Invalid student ID.']);
    if (!$fpTemplateB64) jsonResponse(['success'=>false,'message'=>'No fingerprint template received from scanner.']);

    // Validate it is real base64
    if (base64_decode($fpTemplateB64, true) === false)
        jsonResponse(['success'=>false,'message'=>'Template data is corrupt — please scan again.']);

    $stmt = $db->prepare("SELECT * FROM students WHERE id=?");
    $stmt->execute([$sid]);
    $student = $stmt->fetch();
    if (!$student) jsonResponse(['success'=>false,'message'=>'Student not found.']);

    // Store raw base64 template + SHA-256 hash for fast matching
    $fpHash = hash('sha256', base64_decode($fpTemplateB64));

    $db->prepare("UPDATE students
                  SET fingerprint_template=?, fingerprint_hash=?,
                      fp_device_model=?, fp_device_serial=?, fp_enrolled_at=NOW()
                  WHERE id=?")
       ->execute([$fpTemplateB64, $fpHash, $deviceModel, $deviceSerial, $sid]);

    jsonResponse([
        'success'      => true,
        'message'      => 'Fingerprint enrolled for '.$student['full_name'],
        'student_name' => $student['full_name'],
        'admission_no' => $student['admission_number'],
        'device'       => $deviceModel,
        'enrolled_at'  => date('d M Y, h:i A'),
    ]);
}

/* =====================================================
   AJAX — STUDENT SEARCH
   ===================================================== */
if (isset($_GET['search_ajax'])) {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) jsonResponse(['students'=>[]]);
    $stmt = $db->prepare("SELECT s.*, c.class_name FROM students s
                          LEFT JOIN classes c ON c.id=s.class_id
                          WHERE (s.full_name LIKE ? OR s.admission_number LIKE ? OR s.nin LIKE ?)
                            AND s.status='active' LIMIT 10");
    $stmt->execute(["%$q%","%$q%","%$q%"]);
    jsonResponse(['students'=>$stmt->fetchAll()]);
}

// Stats
$enrolled   = (int)$db->query("SELECT COUNT(*) FROM students WHERE fingerprint_template IS NOT NULL AND status='active'")->fetchColumn();
$unenrolled = (int)$db->query("SELECT COUNT(*) FROM students WHERE fingerprint_template IS NULL AND status='active'")->fetchColumn();
$total      = $enrolled + $unenrolled;

$recentEnrolled = $db->query("
    SELECT s.*, c.class_name FROM students s LEFT JOIN classes c ON c.id=s.class_id
    WHERE s.fingerprint_template IS NOT NULL AND s.status='active'
    ORDER BY s.fp_enrolled_at DESC LIMIT 12")->fetchAll();

$unenrolledList = $db->query("
    SELECT s.*, c.class_name FROM students s LEFT JOIN classes c ON c.id=s.class_id
    WHERE s.fingerprint_template IS NULL AND s.status='active'
    ORDER BY s.full_name LIMIT 20")->fetchAll();

include 'layout_header.php';
?>

<div class="page-header">
    <div>
        <div class="page-header-title">Fingerprint Enrolment</div>
        <div class="page-header-sub">Hardware auto-detection · Futronic · DigitalPersona · Mantra · SecuGen</div>
    </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-card green">
            <div class="stat-icon green"><i class="bi bi-fingerprint"></i></div>
            <div class="stat-value"><?=$enrolled?></div>
            <div class="stat-label">Enrolled</div>
            <div class="stat-trend up"><i class="bi bi-check2"></i><?=$total>0?round($enrolled/$total*100):0?>% coverage</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card red">
            <div class="stat-icon red"><i class="bi bi-person-x"></i></div>
            <div class="stat-value"><?=$unenrolled?></div>
            <div class="stat-label">Pending Enrolment</div>
            <div class="stat-trend down"><i class="bi bi-exclamation-circle"></i>Needs attention</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100" style="border:none;box-shadow:var(--shadow);">
            <div class="card-body d-flex flex-column justify-content-center" style="padding:20px 24px;">
                <!-- Device Status Badge -->
                <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:8px;">Scanner Status</div>
                <div id="deviceStatusBadge" style="display:flex;align-items:center;gap:10px;">
                    <div id="deviceDot" style="width:10px;height:10px;background:#d1d5db;border-radius:50%;flex-shrink:0;"></div>
                    <div>
                        <div id="deviceName" style="font-weight:600;font-size:.9rem;color:var(--text);">Detecting…</div>
                        <div id="deviceDetail" style="font-size:.75rem;color:var(--muted);">Please wait</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Progress -->
<div class="card mb-4">
    <div class="card-body" style="padding:16px 24px;">
        <div class="d-flex justify-content-between mb-2">
            <span style="font-size:.85rem;font-weight:600;">Enrolment Coverage</span>
            <span style="font-family:'Syne',sans-serif;font-weight:700;"><?=$total>0?round($enrolled/$total*100):0?>%</span>
        </div>
        <div style="height:10px;background:var(--bg);border-radius:10px;overflow:hidden;">
            <div style="height:100%;width:<?=$total>0?round($enrolled/$total*100):0?>%;background:linear-gradient(90deg,var(--primary),#1a4a8a);border-radius:10px;transition:width 1.2s ease;"></div>
        </div>
    </div>
</div>

<div class="row g-3">
<!-- LEFT: Enrolment Station -->
<div class="col-md-6">
    <div class="card">
        <div class="card-header"><div class="card-header-title"><i class="bi bi-usb-symbol"></i> Enrolment Station</div></div>
        <div class="card-body">

            <!-- Student search -->
            <div class="mb-3" style="position:relative;">
                <label class="form-label">Search Student</label>
                <div style="position:relative;">
                    <i class="bi bi-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted);"></i>
                    <input type="text" id="studentSearch" class="form-control" style="padding-left:36px;"
                           placeholder="Type name, admission no or NIN…" autocomplete="off">
                </div>
                <div id="searchDropdown" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid var(--border);border-radius:8px;box-shadow:var(--shadow-md);z-index:200;max-height:260px;overflow-y:auto;"></div>
            </div>

            <!-- Selected student card -->
            <div id="selectedStudentCard" style="display:none;background:var(--bg);border-radius:10px;padding:14px;margin-bottom:16px;border:1px solid var(--border);">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div id="scAvatar" style="width:46px;height:46px;background:linear-gradient(135deg,var(--primary),#1a4a8a);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-family:'Syne',sans-serif;font-weight:700;font-size:1.1rem;flex-shrink:0;"></div>
                    <div style="flex:1;">
                        <div id="scName" style="font-weight:600;font-size:.95rem;"></div>
                        <div id="scAdm" style="font-size:.78rem;color:var(--muted);"></div>
                        <div id="scClass" style="font-size:.78rem;color:var(--muted);"></div>
                    </div>
                    <div id="scFPBadge"></div>
                </div>
                <div id="scFPStatus" style="margin-top:10px;font-size:.82rem;padding:8px 12px;border-radius:8px;background:#fff;"></div>
            </div>

            <!-- Scanner UI -->
            <div style="text-align:center;margin-bottom:20px;">
                <div id="fpScanner" style="width:160px;height:160px;border:2px dashed var(--border);border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center;margin:0 auto 14px;cursor:pointer;transition:all .3s;position:relative;overflow:hidden;"
                     onclick="triggerScan()">
                    <!-- Scan line animation -->
                    <div id="scanLine" style="display:none;position:absolute;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,#3ecf8e,transparent);animation:scanMove 1.5s linear infinite;"></div>
                    <i class="bi bi-fingerprint" id="fpScanIcon" style="font-size:4rem;color:var(--muted);pointer-events:none;"></i>
                    <div id="fpScanLabel" style="font-size:.78rem;color:var(--muted);margin-top:6px;pointer-events:none;">Place Finger</div>
                </div>
                <div id="scanStatusMsg" style="font-size:.82rem;color:var(--muted);min-height:20px;"></div>
            </div>

            <!-- Steps guide -->
            <div style="display:flex;justify-content:center;gap:16px;margin-bottom:20px;" id="stepsGuide">
                <?php foreach(['Select Student','Place Finger','Done'] as $i=>$step): ?>
                <div style="text-align:center;flex:1;">
                    <div id="step<?=$i+1?>dot" style="width:28px;height:28px;border-radius:50%;background:<?=$i===0?'var(--primary)':'var(--bg)'?>;color:<?=$i===0?'#fff':'var(--muted)'?>;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;margin:0 auto 4px;border:2px solid <?=$i===0?'var(--primary)':'var(--border)'?>;transition:all .3s;">
                        <?=$i===0?'<i class="bi bi-check2" style="font-size:.8rem;"></i>':($i+1)?>
                    </div>
                    <div style="font-size:.7rem;color:var(--muted);"><?=$step?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="d-grid">
                <button id="enrollBtn" class="btn btn-primary" onclick="doEnrol()" disabled>
                    <i class="bi bi-fingerprint me-1"></i>Capture &amp; Enrol
                </button>
            </div>

            <div id="enrollResult" style="display:none;margin-top:14px;"></div>
        </div>
    </div>

    <!-- SDK / Hardware info -->
    <div class="card mt-3">
        <div class="card-header"><div class="card-header-title"><i class="bi bi-cpu"></i> Supported Hardware</div></div>
        <div class="card-body p-0">
            <?php
            $sdks = [
                ['Futronic FS80 / FS88 / FS90',   'WebSocket  ws://localhost:8765',      'bi-usb-plug',    '#2563eb'],
                ['Mantra MFS100 / MFS110',         'HTTP REST  http://localhost:11100',   'bi-hdd-network', '#d97706'],
                ['DigitalPersona U.are.U 4500',    'HTTP bridge http://localhost:15895',  'bi-shield-lock', '#7c3aed'],
                ['SecuGen Hamster Pro / IV',        'SGIBIOSDK browser plugin',           'bi-fingerprint', '#0891b2'],
                ['Other / Custom Scanner',          'HTTP REST  http://localhost:9000',   'bi-plug',        '#6b7280'],
            ];
            foreach($sdks as $sdk): ?>
            <div style="padding:10px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;">
                <div style="width:32px;height:32px;background:var(--bg);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi <?=$sdk[2]?>" style="color:<?=$sdk[3]?>;font-size:.9rem;"></i>
                </div>
                <div style="flex:1;">
                    <div style="font-size:.82rem;font-weight:600;"><?=$sdk[0]?></div>
                    <div style="font-size:.72rem;color:var(--muted);font-family:monospace;"><?=$sdk[1]?></div>
                </div>
                <div id="status_<?=preg_replace('/\W/','',strtolower($sdk[0]))?>" style="width:8px;height:8px;border-radius:50%;background:#d1d5db;flex-shrink:0;" title="Checking…"></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- RIGHT: Lists -->
<div class="col-md-6">

    <!-- Pending -->
    <?php if(!empty($unenrolledList)): ?>
    <div class="card mb-3">
        <div class="card-header">
            <div class="card-header-title"><i class="bi bi-person-x text-danger"></i> Pending Enrolment</div>
            <span style="font-size:.75rem;background:#fee2e2;color:#dc2626;padding:2px 10px;border-radius:10px;"><?=$unenrolled?> students</span>
        </div>
        <div class="card-body p-0" style="max-height:290px;overflow-y:auto;">
            <?php foreach($unenrolledList as $s): ?>
            <div style="padding:10px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;">
                <div style="width:34px;height:34px;background:var(--bg);border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--muted);flex-shrink:0;">
                    <?=strtoupper(substr($s['full_name'],0,1))?>
                </div>
                <div style="flex:1;">
                    <div style="font-weight:500;font-size:.85rem;"><?=htmlspecialchars($s['full_name'])?></div>
                    <div style="font-size:.74rem;color:var(--muted);"><?=htmlspecialchars($s['admission_number'])?> · <?=htmlspecialchars($s['class_name']??'—')?></div>
                </div>
                <button onclick="quickSelect(<?=htmlspecialchars(json_encode(['id'=>$s['id'],'full_name'=>$s['full_name'],'admission_number'=>$s['admission_number'],'class_name'=>$s['class_name']??'','fingerprint_template'=>$s['fingerprint_template']]),ENT_QUOTES)?>)"
                    style="background:var(--primary);color:#fff;border:none;border-radius:6px;padding:5px 12px;font-size:.78rem;cursor:pointer;white-space:nowrap;">
                    <i class="bi bi-fingerprint me-1"></i>Select
                </button>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recently Enrolled -->
    <div class="card">
        <div class="card-header">
            <div class="card-header-title"><i class="bi bi-check2-all text-success"></i> Recently Enrolled</div>
            <span style="font-size:.75rem;color:var(--muted);"><?=$enrolled?> total</span>
        </div>
        <div class="card-body p-0" style="max-height:<?=empty($unenrolledList)?'460':'280'?>px;overflow-y:auto;">
            <?php foreach($recentEnrolled as $s): ?>
            <div style="padding:10px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;">
                <div style="width:34px;height:34px;background:linear-gradient(135deg,var(--accent2),#2aaa74);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.85rem;flex-shrink:0;">
                    <?=strtoupper(substr($s['full_name'],0,1))?>
                </div>
                <div style="flex:1;">
                    <div style="font-weight:500;font-size:.85rem;"><?=htmlspecialchars($s['full_name'])?></div>
                    <div style="font-size:.74rem;color:var(--muted);"><?=htmlspecialchars($s['class_name']??'—')?></div>
                </div>
                <div style="text-align:right;min-width:0;">
                    <div style="font-size:.72rem;background:#f0fdf4;color:#15803d;padding:2px 8px;border-radius:6px;white-space:nowrap;">✅ <?=htmlspecialchars($s['fp_device_model']??'Enrolled')?></div>
                    <div style="font-size:.68rem;color:var(--muted);margin-top:2px;"><?=$s['fp_enrolled_at']?date('d M Y',strtotime($s['fp_enrolled_at'])):'—'?></div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if(empty($recentEnrolled)): ?>
            <div style="padding:30px;text-align:center;color:var(--muted);font-size:.85rem;">No enrolments yet</div>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>

<!-- =====================================================
     JAVASCRIPT: Real Hardware Auto-Detection Engine
     ===================================================== -->
<?php
$extraScripts = '
<style>
@keyframes scanMove { 0%{top:20%} 100%{top:80%} }
@keyframes fpPulse  { 0%,100%{box-shadow:0 0 0 0 rgba(62,207,142,.3)} 50%{box-shadow:0 0 0 20px rgba(62,207,142,0)} }
.fp-scanning { animation: fpPulse 1.5s infinite; border-color:#3ecf8e !important; }
</style>
<script>
/* ============================================================
   BioCapture — Universal Fingerprint Hardware Bridge
   Tries each SDK in priority order, uses first that responds.
   Supported: Futronic WebSocket, Mantra HTTP, DigitalPersona
              ActiveX, SecuGen JS Plugin
   ============================================================ */

const BioCapture = (function() {

    // ----------------------------------------------------------
    // Internal state
    // ----------------------------------------------------------
    let activeSDK      = null;   // name of detected SDK
    let activeDevice   = null;   // device model string
    let activeSerial   = null;   // device serial
    let captureResolve = null;   // Promise resolver for capture
    let captureReject  = null;

    // ----------------------------------------------------------
    // SDK Probe Definitions
    // Priority order: Futronic → Mantra → DigitalPersona → SecuGen → Custom
    // ----------------------------------------------------------
    const SDKS = [

        /* ---- 1. FUTRONIC (WebSocket on port 8765) ---- */
        {
            name: "Futronic",
            async probe() {
                return new Promise((res) => {
                    try {
                        const ws = new WebSocket("ws://localhost:8765");
                        ws.onopen    = () => { ws.close(); res(true); };
                        ws.onerror   = ()  => res(false);
                        ws.onclose   = ()  => {};
                        setTimeout(() => { try{ws.close();}catch(e){} res(false); }, 2000);
                    } catch(e) { res(false); }
                });
            },
            async capture() {
                return new Promise((resolve, reject) => {
                    try {
                        const ws = new WebSocket("ws://localhost:8765");
                        let captured = false;

                        ws.onopen = () => {
                            // Send capture command — Futronic SDK protocol
                            ws.send(JSON.stringify({ cmd: "capture", timeout: 15000 }));
                        };
                        ws.onmessage = (e) => {
                            try {
                                const data = JSON.parse(e.data);
                                if (data.status === "ok" && data.template) {
                                    captured = true;
                                    ws.close();
                                    resolve({
                                        template: data.template,      // base64 WSQ or ISO template
                                        model:    data.device_model  || "Futronic Scanner",
                                        serial:   data.device_serial || "",
                                    });
                                } else if (data.status === "error") {
                                    ws.close();
                                    reject(data.message || "Futronic: capture failed");
                                }
                            } catch(ex) { ws.close(); reject("Futronic: bad response"); }
                        };
                        ws.onerror = () => reject("Futronic: connection error");
                        ws.onclose = () => { if(!captured) reject("Futronic: connection closed"); };

                    } catch(e) { reject("Futronic: "+e.message); }
                });
            }
        },

        /* ---- 2. MANTRA MFS100 (HTTP REST on port 11100) ---- */
        {
            name: "Mantra",
            async probe() {
                try {
                    const r = await fetch("http://localhost:11100/mfs100/info", {
                        method: "GET", signal: AbortSignal.timeout(2000)
                    });
                    return r.ok;
                } catch(e) { return false; }
            },
            async capture() {
                return new Promise(async (resolve, reject) => {
                    try {
                        // First get device info
                        const infoRes = await fetch("http://localhost:11100/mfs100/info");
                        const info    = await infoRes.json();

                        // Trigger capture
                        const capRes  = await fetch("http://localhost:11100/mfs100/capture", {
                            method: "POST",
                            headers: { "Content-Type": "application/json" },
                            body: JSON.stringify({ timeout: 15000, quality: 70 }),
                            signal: AbortSignal.timeout(18000),
                        });
                        const cap = await capRes.json();

                        if (cap.ErrorCode === 0 && cap.BitmapData) {
                            resolve({
                                template: cap.BitmapData,              // base64 BMP
                                model:   info.DeviceName  || "Mantra MFS100",
                                serial:  info.SerialNumber || "",
                            });
                        } else {
                            reject("Mantra: " + (cap.ErrorDescription || "Capture failed"));
                        }
                    } catch(e) { reject("Mantra: "+e.message); }
                });
            }
        },

        /* ---- 3. DIGITALPERSONA (HTTP bridge on port 15895) ---- */
        {
            name: "DigitalPersona",
            async probe() {
                try {
                    const r = await fetch("http://localhost:15895/dp/status", {
                        signal: AbortSignal.timeout(2000)
                    });
                    return r.ok;
                } catch(e) {
                    // Also try browser plugin object
                    return (typeof window.FingerprintSdkTest !== "undefined" ||
                            typeof window.DigitalPersonaSDK  !== "undefined");
                }
            },
            async capture() {
                return new Promise(async (resolve, reject) => {
                    // Method A: HTTP bridge (recommended — works cross-browser)
                    try {
                        const r = await fetch("http://localhost:15895/dp/capture", {
                            method: "POST",
                            headers: { "Content-Type": "application/json" },
                            body: JSON.stringify({ format: "ISO", quality: 75, timeout: 15000 }),
                            signal: AbortSignal.timeout(18000),
                        });
                        const d = await r.json();
                        if (d.Success && d.Template) {
                            resolve({
                                template: d.Template,
                                model:    d.DeviceName   || "DigitalPersona Reader",
                                serial:   d.DeviceSerial || "",
                            });
                            return;
                        }
                        reject("DigitalPersona: "+d.Error);
                        return;
                    } catch(e) {}

                    // Method B: Browser JS plugin (legacy — IE/Edge)
                    if (typeof window.FingerprintSdkTest !== "undefined") {
                        window.FingerprintSdkTest.startCapture(function(sample) {
                            if (sample && sample.Data) {
                                resolve({ template: sample.Data, model:"DigitalPersona U.are.U", serial:"" });
                            } else {
                                reject("DigitalPersona: no sample received");
                            }
                        });
                    } else {
                        reject("DigitalPersona: no capture method available");
                    }
                });
            }
        },

        /* ---- 4. SECUGEN (SGIBIOSDK browser plugin) ---- */
        {
            name: "SecuGen",
            async probe() {
                return typeof window.SGIBIOSDK !== "undefined";
            },
            async capture() {
                return new Promise((resolve, reject) => {
                    if (typeof window.SGIBIOSDK === "undefined") {
                        reject("SecuGen: SGIBIOSDK not loaded"); return;
                    }
                    window.SGIBIOSDK.GetBMPImage(function(res) {
                        if (res.ErrorCode === 0 && res.BMPBase64) {
                            resolve({
                                template: res.BMPBase64,
                                model:    res.DeviceName || "SecuGen Hamster",
                                serial:   res.SerialNum  || "",
                            });
                        } else {
                            reject("SecuGen error: "+res.ErrorCode);
                        }
                    });
                });
            }
        },

        /* ---- 5. GENERIC HTTP BRIDGE (custom / other devices) ---- */
        /* Any scanner that exposes a local REST endpoint can be added here.
           Uncomment and adjust the port/path to match your devices service. */
        // {
        //     name: "CustomScanner",
        //     async probe() {
        //         try {
        //             const r = await fetch("http://localhost:9000/status",
        //                                   { signal: AbortSignal.timeout(2000) });
        //             return r.ok;
        //         } catch(e) { return false; }
        //     },
        //     async capture() {
        //         const r = await fetch("http://localhost:9000/capture", {
        //             method: "POST",
        //             body: JSON.stringify({ timeout: 15000 }),
        //             signal: AbortSignal.timeout(18000),
        //         });
        //         const d = await r.json();
        //         return { template: d.templateBase64, model: d.deviceName, serial: d.serial };
        //     }
        // },
    ];

    // ----------------------------------------------------------
    // Auto-probe all SDKs and pick first that responds
    // ----------------------------------------------------------
    async function detectHardware() {
        updateUI("Detecting scanner…", "#f59e0b", "bi-hourglass-split");
        for (const sdk of SDKS) {
            try {
                const found = await sdk.probe();
                if (found) {
                    activeSDK = sdk.name;
                    updateDevicePanel(sdk.name, "Ready — click Capture & Enrol", "#3ecf8e");
                    markSDKStatus(sdk.name, true);
                    console.log("[BioCapture] Detected SDK:", sdk.name);
                    return sdk;
                }
            } catch(e) { console.warn("[BioCapture] Probe failed:", sdk.name, e); }
        }
        activeSDK = null;
        updateDevicePanel("No scanner detected", "Check device is plugged in and driver is running", "#ef4444");
        updateUI("No scanner found — check connection", "#ef4444", "bi-usb-plug");
        return null;
    }

    function updateDevicePanel(name, detail, color) {
        document.getElementById("deviceDot").style.background    = color;
        document.getElementById("deviceName").textContent        = name;
        document.getElementById("deviceDetail").textContent      = detail;
    }

    function updateUI(msg, iconColor, iconClass) {
        const icon  = document.getElementById("fpScanIcon");
        const label = document.getElementById("fpScanLabel");
        if (icon)  { icon.className = "bi "+iconClass; icon.style.color = iconColor; }
        if (label) label.textContent = msg;
    }

    function markSDKStatus(name, ok) {
        const key = name.toLowerCase().replace(/\\W/g,"");
        const el  = document.getElementById("status_"+key);
        if (el) el.style.background = ok ? "#3ecf8e" : "#ef4444";
    }

    // ----------------------------------------------------------
    // Public API
    // ----------------------------------------------------------
    return {
        activeSDKName: () => activeSDK,
        activeDeviceModel: () => activeDevice,

        init: detectHardware,

        async capture() {
            if (!activeSDK) {
                const sdk = await detectHardware();
                if (!sdk) throw new Error("No fingerprint scanner detected.");
                const result = await sdk.capture();
                activeDevice = result.model;
                activeSerial = result.serial;
                return result;
            }
            const sdk = SDKS.find(s => s.name === activeSDK);
            if (!sdk) throw new Error("SDK lost — please refresh.");
            const result = await sdk.capture();
            activeDevice = result.model;
            activeSerial = result.serial;
            return result;
        },

        retry: detectHardware,
    };
})();


/* ============================================================
   PAGE LOGIC
   ============================================================ */
let selectedStudentId = null;
let searchTimer       = null;
let currentStep       = 0;

// Init on load
document.addEventListener("DOMContentLoaded", async function() {
    BioCapture.init();
    document.getElementById("studentSearch").addEventListener("input", function() {
        clearTimeout(searchTimer);
        const q = this.value.trim();
        if (q.length < 2) { closeDropdown(); return; }
        searchTimer = setTimeout(() => searchStudents(q), 280);
    });
});

// Retry detection
function retryDetect() { BioCapture.retry(); }

// ---- Student search ----
function searchStudents(q) {
    $.get("fingerprint.php", { search_ajax:1, q }, function(res) {
        const dd = document.getElementById("searchDropdown");
        if (!res.students || !res.students.length) { closeDropdown(); return; }
        dd.innerHTML = res.students.map(s => `
            <div onclick="selectStudent(${JSON.stringify(s).replace(/\"/g,"&quot;")})"
                 style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border);display:flex;gap:10px;align-items:center;"
                 onmouseover="this.style.background=\'var(--bg)\'" onmouseout="this.style.background=\'\'">
                <div style="width:34px;height:34px;background:var(--primary);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.85rem;flex-shrink:0;">${s.full_name.charAt(0).toUpperCase()}</div>
                <div style="flex:1;">
                    <div style="font-weight:500;font-size:.88rem;">${s.full_name}</div>
                    <div style="font-size:.74rem;color:var(--muted);">${s.admission_number} · ${s.class_name||"No Class"}</div>
                </div>
                ${s.fingerprint_template
                    ? \'<span style="font-size:.72rem;background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:6px;white-space:nowrap;">✅ Enrolled</span>\'
                    : \'<span style="font-size:.72rem;background:#fee2e2;color:#dc2626;padding:2px 8px;border-radius:6px;white-space:nowrap;">Pending</span>\'}
            </div>`).join("");
        dd.style.display = "block";
    }, "json");
}

function selectStudent(s) {
    selectedStudentId = s.id;
    document.getElementById("studentSearch").value = s.full_name;
    closeDropdown();

    document.getElementById("scAvatar").textContent = s.full_name.charAt(0).toUpperCase();
    document.getElementById("scName").textContent   = s.full_name;
    document.getElementById("scAdm").textContent    = s.admission_number;
    document.getElementById("scClass").textContent  = s.class_name || "No Class";
    document.getElementById("scFPBadge").innerHTML  = s.fingerprint_template
        ? \'<span style="font-size:.72rem;background:#dcfce7;color:#15803d;padding:3px 8px;border-radius:6px;">✅ Enrolled</span>\'
        : \'<span style="font-size:.72rem;background:#fee2e2;color:#dc2626;padding:3px 8px;border-radius:6px;">❌ Not Enrolled</span>\';

    const fpEl = document.getElementById("scFPStatus");
    fpEl.innerHTML = s.fingerprint_template
        ? "Already enrolled — click below to <strong>re-enrol</strong> and replace template"
        : "Ready — place finger on scanner then click <strong>Capture &amp; Enrol</strong>";
    fpEl.style.background = s.fingerprint_template ? "#fffbeb" : "#f0fdf4";
    fpEl.style.color       = s.fingerprint_template ? "#92400e"  : "#15803d";

    document.getElementById("selectedStudentCard").style.display = "block";
    document.getElementById("enrollBtn").disabled = false;
    document.getElementById("enrollResult").style.display = "none";
    setStep(1);
    document.getElementById("scanStatusMsg").textContent =
        BioCapture.activeSDKName()
            ? "Scanner ready — place finger and click Capture"
            : "Detecting scanner…";
}

function closeDropdown() {
    const dd = document.getElementById("searchDropdown");
    if (dd) dd.style.display = "none";
}
document.addEventListener("click", e => { if (!e.target.closest("#studentSearch") && !e.target.closest("#searchDropdown")) closeDropdown(); });

// ---- Quick select from pending list ----
function quickSelect(s) {
    selectStudent(s);
    window.scrollTo({ top: 0, behavior: "smooth" });
}

// ---- Visual step tracker ----
function setStep(n) {
    for (let i=1;i<=3;i++) {
        const dot = document.getElementById("step"+i+"dot");
        if (!dot) continue;
        if (i < n) {
            dot.style.background = "#3ecf8e"; dot.style.borderColor = "#3ecf8e"; dot.style.color = "#fff";
            dot.innerHTML = \'<i class="bi bi-check2" style="font-size:.8rem;"></i>\';
        } else if (i === n) {
            dot.style.background = "var(--primary)"; dot.style.borderColor = "var(--primary)"; dot.style.color = "#fff";
            dot.innerHTML = i;
        } else {
            dot.style.background = "var(--bg)"; dot.style.borderColor = "var(--border)"; dot.style.color = "var(--muted)";
            dot.innerHTML = i;
        }
    }
}

// ---- Trigger scan (also called on scanner div click) ----
function triggerScan() {
    if (!selectedStudentId) {
        document.getElementById("studentSearch").focus();
        document.getElementById("scanStatusMsg").textContent = "⚠ Select a student first";
        return;
    }
    doEnrol();
}

// ---- Main enrol flow ----
async function doEnrol() {
    if (!selectedStudentId) return;

    const btn      = document.getElementById("enrollBtn");
    const scanner  = document.getElementById("fpScanner");
    const icon     = document.getElementById("fpScanIcon");
    const label    = document.getElementById("fpScanLabel");
    const statusMsg= document.getElementById("scanStatusMsg");
    const scanLine = document.getElementById("scanLine");
    const result   = document.getElementById("enrollResult");

    // UI: scanning state
    btn.disabled       = true;
    btn.innerHTML      = \'<span class="spinner-border spinner-border-sm me-2"></span>Waiting for finger…\';
    scanner.classList.add("fp-scanning");
    icon.className     = "bi bi-fingerprint";
    icon.style.color   = "#3ecf8e";
    label.textContent  = "Hold still…";
    scanLine.style.display = "block";
    result.style.display   = "none";
    setStep(2);
    statusMsg.textContent = "Capturing fingerprint from " + (BioCapture.activeSDKName() || "scanner") + "…";

    try {
        // ---- REAL CAPTURE ----
        const captured = await BioCapture.capture();

        // UI: success scanning animation
        icon.style.color   = "#3ecf8e";
        label.textContent  = "Captured! Saving…";
        scanLine.style.display = "none";
        statusMsg.textContent  = "Template received from " + captured.model + " — saving to database…";

        // ---- POST TO PHP ----
        const payload = {
            ajax:         1,
            student_id:   selectedStudentId,
            fp_template:  captured.template,
            device_model: captured.model,
            device_serial:captured.serial,
        };

        $.post("fingerprint.php", payload, function(res) {
            scanner.classList.remove("fp-scanning");
            btn.disabled  = false;
            btn.innerHTML = \'<i class="bi bi-fingerprint me-1"></i>Capture &amp; Enrol\';
            result.style.display = "block";

            if (res.success) {
                setStep(3);
                icon.style.color   = "#3ecf8e";
                label.textContent  = "Enrolled ✅";
                statusMsg.textContent = res.enrolled_at + " via " + res.device;
                result.innerHTML = `
                    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px 18px;">
                        <div style="font-weight:700;color:#15803d;margin-bottom:6px;font-size:.95rem;">✅ Fingerprint Enrolled Successfully</div>
                        <div style="font-size:.82rem;color:#166534;line-height:2;">
                            <div><strong>Student:</strong> ${res.student_name}</div>
                            <div><strong>Adm No:</strong> ${res.admission_no}</div>
                            <div><strong>Device:</strong> ${res.device}</div>
                            <div><strong>Time:</strong> ${res.enrolled_at}</div>
                        </div>
                    </div>`;
                // Update FP badge
                document.getElementById("scFPBadge").innerHTML = \'<span style="font-size:.72rem;background:#dcfce7;color:#15803d;padding:3px 8px;border-radius:6px;">✅ Enrolled</span>\';
                document.getElementById("scFPStatus").innerHTML = "Enrolment complete — template stored for matching.";
                document.getElementById("scFPStatus").style.background = "#f0fdf4";
                document.getElementById("scFPStatus").style.color = "#15803d";
            } else {
                icon.style.color  = "#ef4444";
                label.textContent = "Failed";
                result.innerHTML  = `<div style="background:#fff1f2;border:1px solid #fecaca;border-radius:10px;padding:14px 18px;color:#dc2626;font-size:.88rem;">❌ ${res.message}</div>`;
                setStep(2);
            }
        }, "json").fail(function() {
            scanner.classList.remove("fp-scanning");
            btn.disabled  = false;
            btn.innerHTML = \'<i class="bi bi-fingerprint me-1"></i>Capture &amp; Enrol\';
            result.style.display = "block";
            result.innerHTML = `<div style="background:#fff1f2;border:1px solid #fecaca;border-radius:10px;padding:14px;color:#dc2626;font-size:.88rem;">❌ Server error — could not save template.</div>`;
        });

    } catch(err) {
        // ---- CAPTURE FAILED ----
        scanner.classList.remove("fp-scanning");
        btn.disabled       = false;
        btn.innerHTML      = \'<i class="bi bi-fingerprint me-1"></i>Capture &amp; Enrol\';
        icon.style.color   = "#ef4444";
        icon.className     = "bi bi-exclamation-circle";
        label.textContent  = "Scan failed";
        scanLine.style.display = "none";
        statusMsg.textContent  = String(err);
        setStep(1);
        result.style.display = "block";
        result.innerHTML = `
            <div style="background:#fff1f2;border:1px solid #fecaca;border-radius:10px;padding:14px 18px;">
                <div style="font-weight:700;color:#dc2626;margin-bottom:6px;">❌ Capture Failed</div>
                <div style="font-size:.82rem;color:#991b1b;margin-bottom:10px;">${String(err)}</div>
                <div style="font-size:.8rem;color:#6b7a8d;line-height:1.9;">
                    <strong style="color:var(--text);">Troubleshooting steps:</strong><br>
                    <span style="display:inline-block;margin-top:4px;">
                    <strong>Futronic</strong> — Plug in scanner → run <code>FutronicWebSocketServer.exe</code> → it listens on port 8765<br>
                    <strong>Mantra MFS100</strong> — Install MFS100 RD Service → it auto-starts on port 11100<br>
                    <strong>DigitalPersona</strong> — Run <code>DPHttpBridge.exe</code> → it listens on port 15895<br>
                    <strong>SecuGen</strong> — Install SGIBIOSDK browser plugin → reload this page<br>
                    <strong>All devices</strong> — Make sure the USB scanner is connected before opening this page
                    </span>
                </div>
                <div style="margin-top:12px;">
                    <button onclick="BioCapture.retry()" style="background:var(--primary);color:#fff;border:none;border-radius:7px;padding:7px 18px;font-size:.82rem;cursor:pointer;font-weight:600;">
                        <i class="bi bi-arrow-clockwise me-1"></i>Retry Detection
                    </button>
                </div>
            </div>`;
    }
}
</script>';

include 'layout_footer.php';
?>

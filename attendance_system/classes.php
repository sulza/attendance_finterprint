<?php
// classes.php
require_once 'config.php';
requireRole(['director']);
$pageTitle = 'Institutional Class Registry';

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if (in_array($act, ['create', 'update'])) {
        $name  = trim($_POST['class_name'] ?? '');
        $level = trim($_POST['class_level'] ?? '');
        $sec   = trim($_POST['section'] ?? '');
        $cid   = (int)($_POST['class_id'] ?? 0);

        if ($name && $level) {
            try {
                if ($act === 'create') {
                    $stmt = $db->prepare("INSERT INTO classes (class_name, class_level, section) VALUES (?, ?, ?)");
                    $stmt->execute([$name, $level, $sec ?: null]);
                    flash('success', "Record finalized: Class <b>$name</b> successfully added to registry.");
                } else {
                    $stmt = $db->prepare("UPDATE classes SET class_name=?, class_level=?, section=? WHERE id=?");
                    $stmt->execute([$name, $level, $sec ?: null, $cid]);
                    flash('success', "Archive updated for <b>$name</b>.");
                }
            } catch (PDOException $e) {
                flash('error', "Registration Error: Possible duplicate name.");
            }
        } else {
            flash('error', 'Critical fields missing: Name and Level required.');
        }
        header('Location: classes.php'); 
        exit;
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    // UX check: Don't delete classes with active students
    $check = $db->prepare("SELECT COUNT(*) FROM students WHERE class_id = ? AND status='active'");
    $check->execute([$id]);
    if ($check->fetchColumn() > 0) {
        flash('error', "Class cannot be deleted while it contains active student dossiers.");
    } else {
        $db->prepare("DELETE FROM classes WHERE id = ?")->execute([$id]);
        flash('success', 'Class removed from directory.');
    }
    header('Location: classes.php'); 
    exit;
}

$classes = $db->query("
    SELECT c.*, 
    COUNT(s.id) as student_count 
    FROM classes c 
    LEFT JOIN students s ON s.class_id=c.id AND s.status='active' 
    GROUP BY c.id 
    ORDER BY c.class_level, c.class_name
")->fetchAll();

$totalClassPop = array_sum(array_column($classes, 'student_count'));

include 'layout_header.php';
?>

<div class="page-header d-flex flex-column flex-md-row justify-content-between align-items-md-center">
    <div>
        <h1 class="fw-black Syne text-navy mb-0">Classroom Management</h1>
        <p class="text-muted small mb-0"><i class="bi bi-buildings"></i> Directing <?= count($classes) ?> distinct academic segments</p>
    </div>
    <button class="btn btn-accent rounded-pill px-4 shadow-sm fw-bold mt-3 mt-md-0" data-bs-toggle="modal" data-bs-target="#classModal" onclick="openCreate()">
        <i class="bi bi-plus-lg me-2"></i>Provision New Class
    </button>
</div>

<!-- Stat Insight Bar -->
<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-4 p-3 bg-white">
            <div class="d-flex align-items-center gap-3">
                <div class="bg-primary bg-opacity-10 text-primary p-3 rounded-4"><i class="bi bi-ui-checks-grid fs-4"></i></div>
                <div><h4 class="mb-0 fw-black text-navy Syne"><?= count($classes) ?></h4><span class="fs-tiny opacity-50 fw-bold">REGISTERED CLASSES</span></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-4 p-3 bg-white">
            <div class="d-flex align-items-center gap-3">
                <div class="bg-accent bg-opacity-10 text-navy p-3 rounded-4"><i class="bi bi-people-fill fs-4"></i></div>
                <div><h4 class="mb-0 fw-black text-navy Syne"><?= number_format($totalClassPop) ?></h4><span class="fs-tiny opacity-50 fw-bold">TOTAL SEATED POPULATION</span></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-4 p-3 bg-navy text-white shadow-lg">
            <div class="d-flex align-items-center gap-3">
                <div class="bg-white bg-opacity-10 text-accent p-3 rounded-4"><i class="bi bi-safe2 fs-4"></i></div>
                <div><h4 class="mb-0 fw-black text-white Syne"><?= count($classes) > 0 ? round($totalClassPop/count($classes), 1) : 0 ?></h4><span class="fs-tiny text-white opacity-50 fw-bold">AVG. DENSITY PER CLASS</span></div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4 overflow-hidden">
    <div class="table-responsive">
        <table class="table align-middle data-table mb-0" style="font-size:14px">
            <thead class="bg-light">
                <tr class="text-uppercase" style="font-size:11px; letter-spacing:0.1em;">
                    <th class="ps-4 py-3">Identifer & Section</th>
                    <th>Classification</th>
                    <th class="text-center">Population</th>
                    <th class="text-center">Integrity Tally</th>
                    <th class="pe-4 text-end">Administration</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($classes as $c): ?>
                <tr>
                    <td class="ps-4">
                        <div class="d-flex align-items-center gap-3">
                            <div class="bg-light rounded-3 fw-black Syne text-primary d-flex align-items-center justify-content-center" style="width:40px; height:40px;">
                                <?= substr($c['class_name'], 0, 1) ?>
                            </div>
                            <div>
                                <div class="fw-bold text-navy fs-6"><?= htmlspecialchars($c['class_name']) ?></div>
                                <span class="badge bg-secondary bg-opacity-10 text-muted border fs-tiny"><?= htmlspecialchars($c['section'] ?: 'No Sec.') ?> Unit</span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3 border border-primary border-opacity-25 fw-bold"><?= htmlspecialchars($c['class_level']) ?></span>
                    </td>
                    <td class="text-center">
                        <div class="fw-black Syne h5 mb-0 text-navy"><?= $c['student_count'] ?></div>
                        <small class="fs-tiny opacity-50 fw-bold">ENROLLED</small>
                    </td>
                    <td class="text-center" style="min-width: 150px;">
                        <div class="d-flex flex-column align-items-center px-4">
                             <div class="progress w-100 rounded-pill bg-light" style="height:6px">
                                <?php $bar = ($totalClassPop > 0) ? ($c['student_count'] / $totalClassPop) * 500 : 0; ?>
                                <div class="progress-bar bg-accent" style="width: <?= min(100, $bar) ?>%"></div>
                             </div>
                             <small class="fs-tiny opacity-25 mt-1 fw-bold">CLUSTER WEIGHT</small>
                        </div>
                    </td>
                    <td class="pe-4 text-end">
                        <div class="dropdown">
                            <button class="btn btn-sm btn-light border dropdown-toggle" data-bs-toggle="dropdown">Files</button>
                            <ul class="dropdown-menu shadow-lg rounded-3 border-light">
                                <li><a class="dropdown-item py-2" href="#" onclick='openEdit(<?= json_encode($c) ?>)'><i class="bi bi-pencil-square me-2 text-warning"></i>Modify Profile</a></li>
                                <li><a class="dropdown-item py-2" href="students.php?class_id=<?= $c['id'] ?>"><i class="bi bi-people me-2 text-primary"></i>Enrolment List</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item py-2 text-danger fw-bold" href="javascript:void(0)" onclick="confirmDelete('classes.php?delete=<?= $c['id'] ?>','Wipe this classroom entity?')"><i class="bi bi-trash3-fill me-2"></i>Destroy Entry</a></li>
                            </ul>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(!$classes): ?><tr><td colspan="5" class="text-center p-5 text-muted small fw-bold">NO CLASSES INITIALIZED IN DIRECTORY</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Enhancment: Floating Labels & UX -->
<div class="modal fade" id="classModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-5 overflow-hidden">
            <div class="modal-header bg-navy text-white px-4 border-bottom-0 py-3">
                <h5 class="modal-title Syne fw-bold" id="cmTitle">Class Initialization</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" id="cmAction" value="create">
                <input type="hidden" name="class_id" id="cmId" value="">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="form-floating mb-3 shadow-sm">
                                <input type="text" name="class_name" id="cmName" class="form-control border-light" required placeholder="Full Name">
                                <label>Target Class Identifier (e.g. Primary 4A)</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-floating shadow-sm">
                                <input type="text" name="class_level" id="cmLevel" class="form-control border-light" required placeholder="Level">
                                <label>Academic Level</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-floating shadow-sm">
                                <input type="text" name="section" id="cmSection" class="form-control border-light" placeholder="Section">
                                <label>Arm/Section (optional)</label>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4 p-3 bg-light rounded-4 small border-1">
                        <i class="bi bi-info-circle-fill text-primary me-2"></i>Ensure class names are standardized to prevent database duplicates.
                    </div>
                </div>
                <div class="modal-footer bg-light px-4 border-top-0 py-3">
                    <button type="button" class="btn btn-outline-secondary px-4 rounded-pill fw-bold border-0" data-bs-modal="modal">Discard</button>
                    <button type="submit" class="btn btn-navy px-4 rounded-pill fw-black" id="cmBtn">FINALISE</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
/* CSS: Refined Dashboard Logic */
.bg-navy { background-color: #071829 !important; }
.text-navy { color: #0a2342 !important; }
.Syne { font-family: 'Syne', sans-serif !important; }
.fw-black { font-weight: 900 !important; }
.fs-tiny { font-size: 10px !important; text-transform: uppercase; letter-spacing: 0.12em; }
.data-table thead th { border-bottom: none !important; font-family: 'Syne'; background-color: #f8fbfd; color: #64748b; }
.form-control:focus { box-shadow: none !important; border-color: var(--accent) !important; }
.btn-navy { background: #071829; color: white; transition: 0.3s; }
.btn-navy:hover { background: #0a2342; transform: translateY(-2px); }
</style>

<?php
$extraScripts = '
<script>
const mEl = document.getElementById("classModal");
const cmTitle = document.getElementById("cmTitle");
const cmAction = document.getElementById("cmAction");
const cmId = document.getElementById("cmId");
const cmName = document.getElementById("cmName");
const cmLevel = document.getElementById("cmLevel");
const cmSection = document.getElementById("cmSection");
const cmBtn = document.getElementById("cmBtn");

function openCreate(){
    cmTitle.textContent="Provision New Classroom";
    cmAction.value="create";
    cmId.value="";
    cmName.value="";
    cmLevel.value="";
    cmSection.value="";
    cmBtn.textContent="FINALIZE CLASS";
    cmBtn.className = "btn btn-accent rounded-pill px-4 fw-black text-navy";
}

function openEdit(c){
    cmTitle.textContent="Update Institutional Data";
    cmAction.value="update";
    cmId.value=c.id;
    cmName.value=c.class_name;
    cmLevel.value=c.class_level;
    cmSection.value=c.section||"";
    cmBtn.textContent="SAVE RECORD";
    cmBtn.className = "btn btn-primary rounded-pill px-4 fw-black";
    const myModal = new bootstrap.Modal(mEl);
    myModal.show();
}
</script>';
include 'layout_footer.php';
?>
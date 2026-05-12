<?php
require_once 'config.php';
requireRole(['director']);
$pageTitle = 'Personnel Management';

$db   = getDB();
$user = currentUser();
$action = $_GET['action'] ?? 'list';
$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Security: CSRF
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

/* =========================================================
   POST HANDLERS (Security & Data Integrity Fixes)
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die('Access Denied: Invalid Security Token.');
    }

    $postAction = $_POST['action'] ?? '';

    if (in_array($postAction, ['create','update'])) {
        $fullName  = trim($_POST['full_name'] ?? '');
        $username  = trim($_POST['username'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $role      = $_POST['role'] ?? '';
        $classId   = (int)($_POST['assigned_class_id'] ?? 0) ?: null;
        $isActive  = isset($_POST['is_active']) ? 1 : 0;
        $password  = $_POST['password'] ?? '';
        $validRoles = ['director','admission_officer','class_master','admin_officer'];

        $errors = [];
        if (empty($fullName)) $errors[] = 'Enter full staff name.';
        if (empty($username)) $errors[] = 'Enter system username.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
        if (!in_array($role, $validRoles)) $errors[] = 'Assigned role is invalid.';
        
        // Creation specific checks
        if ($postAction === 'create') {
            if (empty($password)) $errors[] = 'Initialization password required.';
            $chk = $db->prepare("SELECT id FROM users WHERE username=? OR email=? LIMIT 1");
            $chk->execute([$username, $email]);
            if ($chk->fetch()) $errors[] = 'Account already exists (username/email conflict).';
        } else {
            // Update specific: Prevent deactivating yourself
            if ((int)$_POST['user_id'] === $user['id'] && $isActive === 0) {
                $errors[] = 'For security, you cannot deactivate your own administrative session.';
            }
        }

        if (empty($errors)) {
            try {
                if ($postAction === 'create') {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("INSERT INTO users (full_name,username,email,password,role,assigned_class_id,is_active) VALUES(?,?,?,?,?,?,?)");
                    $stmt->execute([$fullName,$username,$email,$hashed,$role,$classId,$isActive]);
                    flash('success', "New account for <b>$fullName</b> created successfully.");
                } else {
                    $sid = (int)$_POST['user_id'];
                    $sql = "UPDATE users SET full_name=?, username=?, email=?, role=?, assigned_class_id=?, is_active=? ";
                    $params = [$fullName, $username, $email, $role, $classId, $isActive];

                    // FIX: Password reset logic - only if field was populated
                    if (!empty($password)) {
                        $sql .= ", password=? ";
                        $params[] = password_hash($password, PASSWORD_DEFAULT);
                    }
                    
                    $sql .= "WHERE id=? ";
                    $params[] = $sid;
                    
                    $db->prepare($sql)->execute($params);
                    flash('success', "Personnel file updated successfully.");
                }
            } catch (PDOException $e) { flash('error', "Database error. Please check for unique value conflicts."); }
            header('Location: users.php'); exit;
        } else {
            flash('error', implode(' ', $errors));
            header('Location: users.php'); exit;
        }
    }
}

/* =========================================================
   ROUTING ACTIONS (Toggle & Delete)
   ========================================================= */
if ($action === 'delete' && $userId && $userId !== $user['id']) {
    $db->prepare("DELETE FROM users WHERE id=?")->execute([$userId]);
    flash('success', 'Staff account purged from directory.');
    header('Location: users.php'); exit;
}

if ($action === 'toggle' && $userId && $userId !== $user['id']) {
    $db->prepare("UPDATE users SET is_active = NOT is_active WHERE id=?")->execute([$userId]);
    flash('success', 'User accessibility status modified.');
    header('Location: users.php'); exit;
}

$classes = $db->query("SELECT * FROM classes ORDER BY class_name ASC")->fetchAll();
$users   = $db->query("SELECT u.*, c.class_name FROM users u LEFT JOIN classes c ON c.id=u.assigned_class_id ORDER BY u.is_active DESC, u.full_name ASC")->fetchAll();

include 'layout_header.php';
?>

<div class="page-header d-flex flex-column flex-md-row justify-content-between align-items-md-center">
    <div>
        <h1 class="fw-black Syne text-navy mb-1">Administrative Personnel</h1>
        <p class="text-muted small mb-0"><i class="bi bi-shield-lock-fill"></i> Oversight for <?= count($users) ?> staff credentials</p>
    </div>
    <button class="btn btn-navy rounded-pill px-4 shadow-sm fw-bold mt-3 mt-md-0" data-bs-toggle="modal" data-bs-target="#userModal" onclick="openCreate()">
        <i class="bi bi-person-plus-fill me-2 text-accent"></i>Deploy User
    </button>
</div>

<div class="card border-0 shadow-sm rounded-4 overflow-hidden mt-4">
    <div class="table-responsive">
        <table class="table align-middle mb-0" style="font-size:14px">
            <thead class="bg-light">
                <tr class="fs-tiny text-muted fw-bold">
                    <th class="ps-4 py-3">IDENTITY & ACCESS</th>
                    <th>PRIVILEGE LEVEL</th>
                    <th>PLACEMENT</th>
                    <th class="text-center">CONNECTIVITY</th>
                    <th class="pe-4 text-end">MANAGEMENT</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr class="<?= $u['is_active'] ? '' : 'opacity-75' ?>">
                    <td class="ps-4">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-pill bg-navy text-accent Syne fw-bold d-flex align-items-center justify-content-center border border-3 border-light shadow-sm" style="width:40px; height:40px;">
                                <?= strtoupper(substr($u['full_name'],0,1)) ?>
                            </div>
                            <div>
                                <div class="fw-black text-navy mb-0" style="font-size:14px;"><?= htmlspecialchars($u['full_name']) ?></div>
                                <div class="fs-tiny text-muted fw-bold"><i class="bi bi-at"></i><?= htmlspecialchars($u['username']) ?> · <?= htmlspecialchars($u['email']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge rounded-pill px-3 py-2 fw-black fs-tiny shadow-sm" style="background:var(--navy-soft); color:#2af; border: 1px solid var(--accent)">
                            <?= strtoupper(roleLabel($u['role'])) ?>
                        </span>
                    </td>
                    <td class="small fw-bold text-muted"><?= htmlspecialchars($u['class_name'] ?? 'UNIVERSAL') ?></td>
                    <td class="text-center">
                        <span class="badge <?= $u['is_active'] ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' ?> px-3 rounded-pill fw-black" style="font-size:10px;">
                           <?= $u['is_active'] ? 'ACTIVE' : 'DEACTIVATED' ?>
                        </span>
                    </td>
                    <td class="pe-4 text-end">
                        <div class="dropdown">
                            <button class="btn btn-sm btn-light border dropdown-toggle" data-bs-toggle="dropdown">Audit</button>
                            <ul class="dropdown-menu shadow rounded-4 border-0 p-2">
                                <li><a class="dropdown-item py-2" href="#" onclick='openEdit(<?= json_encode($u) ?>)'><i class="bi bi-pencil-square me-2 text-warning"></i>Mod Detail</a></li>
                                <?php if ($u['id'] !== $user['id']): ?>
                                    <li><a class="dropdown-item py-2" href="users.php?action=toggle&id=<?= $u['id'] ?>"><i class="bi bi-power me-2 <?= $u['is_active']?'text-danger':'text-success' ?>"></i><?= $u['is_active']?'Kill Switch':'Enable Access' ?></a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item py-2 text-danger fw-bold" href="javascript:void(0)" onclick="confirmDelete('users.php?action=delete&id=<?= $u['id'] ?>','Permanently delete this user credentials?')"><i class="bi bi-trash3-fill me-2"></i>Purge Record</a></li>
                                <?php else: ?>
                                    <li><span class="dropdown-item py-2 disabled text-muted"><i class="bi bi-person-check-fill me-2"></i>My Session</span></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Advanced User Controller -->
<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-5 overflow-hidden">
            <div class="modal-header bg-navy text-white px-4 border-0">
                <h5 class="modal-title Syne fw-black" id="modalTitle">Account Deployment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="userForm">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="user_id" id="formUserId" value="">
                
                <div class="modal-body p-4">
                    <div class="row g-4">
                        <div class="col-md-7">
                            <label class="form-label fs-tiny fw-black text-navy opacity-50 ms-2">LEGAL ENTITY NAME</label>
                            <input type="text" name="full_name" id="fFullName" class="form-control form-navy shadow-sm py-2 px-3" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fs-tiny fw-black text-navy opacity-50 ms-2">SECURE ALIAS</label>
                            <input type="text" name="username" id="fUsername" class="form-control form-navy shadow-sm py-2 px-3" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fs-tiny fw-black text-navy opacity-50 ms-2">ENCRYPTED MAIL ADDRESS</label>
                            <input type="email" name="email" id="fEmail" class="form-control form-navy shadow-sm py-2 px-3" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fs-tiny fw-black text-navy opacity-50 ms-2">PASSKEY <span id="passNote" class="text-warning-dark" style="font-size:9px">(LEAVE BLANK TO RETAIN)</span></label>
                            <input type="password" name="password" id="fPassword" class="form-control form-navy shadow-sm py-2 px-3" placeholder="Security Protocol Override">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fs-tiny fw-black text-navy opacity-50 ms-2">COMMAND LEVEL / ROLE</label>
                            <select name="role" id="fRole" class="form-select form-navy shadow-sm" required onchange="toggleClassField()">
                                <option value="director">Directorial Staff (Full Access)</option>
                                <option value="admission_officer">Admission Admin (Dossier Manager)</option>
                                <option value="class_master">Class Master (Room Access)</option>
                                <option value="admin_officer">Registry Officer</option>
                            </select>
                        </div>
                        <div class="col-md-6" id="classFieldContainer">
                            <label class="form-label fs-tiny fw-black text-navy opacity-50 ms-2">INSTITUTIONAL PLACEMENT</label>
                            <select name="assigned_class_id" id="fClass" class="form-select form-navy shadow-sm">
                                <option value="">Universal Site (Unassigned)</option>
                                <?php foreach ($classes as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= s($c['class_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mt-4 p-4 rounded-4 bg-light border d-flex align-items-center justify-content-between">
                         <div>
                            <h6 class="fw-bold mb-1 text-navy" style="font-size:14px">Account System Accessibility</h6>
                            <p class="small text-muted mb-0">Allow account login and dashboard visibility</p>
                         </div>
                         <div class="form-check form-switch fs-4">
                             <input class="form-check-input" type="checkbox" name="is_active" id="fActive" value="1" checked>
                         </div>
                    </div>
                </div>
                
                <div class="modal-footer px-4 border-0 pb-4 pt-0">
                    <button type="button" class="btn btn-outline-secondary px-4 rounded-pill fw-bold border-0" data-bs-dismiss="modal">Discard</button>
                    <button type="submit" class="btn btn-navy px-4 rounded-pill fw-black" id="submitBtn">FINALISE ACCOUNT</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
/* CSS Implementation layer */
.fw-black { font-weight: 900 !important; }
.Syne { font-family: 'Syne', sans-serif !important; }
.bg-navy { background-color: #071829 !important; }
.text-navy { color: #071829 !important; }
.fs-tiny { font-size: 10px !important; text-transform: uppercase; letter-spacing: 0.12em; }
.form-navy { border: 2px solid #f1f5f9; border-radius: 12px; }
.form-navy:focus { border-color: var(--accent); box-shadow: 0 0 0 4px #f4be3822; outline: 0; }
.dropdown-item { transition: all 0.2s; border-radius: 10px; font-weight: 600; font-size: 13px; }
.form-switch .form-check-input { cursor: pointer; border-color: var(--navy); }
.form-switch .form-check-input:checked { background-color: #10b981; border-color: #10b981; }
</style>

<?php
$extraScripts = '
<script>
function openCreate() {
    document.getElementById("modalTitle").textContent = "Personnel Identity Deploy";
    document.getElementById("formAction").value = "create";
    document.getElementById("formUserId").value = "";
    document.getElementById("userForm").reset();
    document.getElementById("passNote").style.display = "none";
    document.getElementById("submitBtn").textContent = "COMMITT USER";
    toggleClassField();
}
function openEdit(u) {
    document.getElementById("modalTitle").textContent = "Mod Personnel Credentials";
    document.getElementById("formAction").value = "update";
    document.getElementById("formUserId").value = u.id;
    document.getElementById("fFullName").value = u.full_name;
    document.getElementById("fUsername").value = u.username;
    document.getElementById("fEmail").value = u.email;
    document.getElementById("fRole").value = u.role;
    document.getElementById("fClass").value = u.assigned_class_id || "";
    document.getElementById("fActive").checked = u.is_active == 1;
    document.getElementById("fPassword").value = "";
    document.getElementById("passNote").style.display = "inline-block";
    document.getElementById("submitBtn").textContent = "FINALIZE UPDATE";
    toggleClassField();
    new bootstrap.Modal(document.getElementById("userModal")).show();
}
function toggleClassField() {
    const role = document.getElementById("fRole").value;
    const classContainer = document.getElementById("classFieldContainer");
    if (role === "class_master") {
        classContainer.classList.remove("opacity-50");
        document.getElementById("fClass").disabled = false;
    } else {
        classContainer.classList.add("opacity-50");
        document.getElementById("fClass").value = "";
        // Keep active for logic purposes but show visually muted
    }
}
</script>';
include 'layout_footer.php';
?>
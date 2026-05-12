<?php
require_once 'config.php';
requireLogin();
$pageTitle = 'My Profile';
$db   = getDB();
$user = currentUser();

// Load full user record
$stmt = $db->prepare("SELECT u.*, c.class_name FROM users u LEFT JOIN classes c ON c.id=u.assigned_class_id WHERE u.id=?");
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'update_profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $errors   = [];
        if (empty($fullName)) $errors[] = 'Full name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email required.';
        // Check email unique (exclude self)
        $chk = $db->prepare("SELECT id FROM users WHERE email=? AND id != ?");
        $chk->execute([$email, $user['id']]);
        if ($chk->fetch()) $errors[] = 'Email already in use by another account.';

        if (empty($errors)) {
            $db->prepare("UPDATE users SET full_name=?, email=? WHERE id=?")
               ->execute([$fullName, $email, $user['id']]);
            $_SESSION['user_name'] = $fullName;
            flash('success', 'Profile updated successfully.');
        } else {
            flash('error', implode(' ', $errors));
        }
        header('Location: profile.php'); exit;
    }

    if ($act === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $errors  = [];

        if (!password_verify($current, $profile['password'])) $errors[] = 'Current password is incorrect.';
        if (strlen($new) < 6) $errors[] = 'New password must be at least 6 characters.';
        if ($new !== $confirm) $errors[] = 'New passwords do not match.';

        if (empty($errors)) {
            $hashed = password_hash($new, PASSWORD_BCRYPT);
            $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hashed, $user['id']]);
            flash('success', 'Password changed successfully.');
        } else {
            flash('error', implode(' ', $errors));
        }
        header('Location: profile.php'); exit;
    }
}

// Activity log: last 10 attendance marks by this user
$myActivity = $db->prepare("
    SELECT a.*, s.full_name as student_name, s.admission_number, c.class_name
    FROM attendance a
    JOIN students s ON s.id=a.student_id
    LEFT JOIN classes c ON c.id=s.class_id
    WHERE a.marked_by=?
    ORDER BY a.created_at DESC LIMIT 10
");
$myActivity->execute([$user['id']]);
$myActivity = $myActivity->fetchAll();

$myStudentsCount = 0;
if ($user['role'] === 'class_master' && $user['class_id']) {
    $myStudentsCount = $db->prepare("SELECT COUNT(*) FROM students WHERE class_id=? AND status='active'");
    $myStudentsCount->execute([$user['class_id']]);
    $myStudentsCount = $myStudentsCount->fetchColumn();
}
$myMarkedCount = $db->prepare("SELECT COUNT(*) FROM attendance WHERE marked_by=?");
$myMarkedCount->execute([$user['id']]);
$myMarkedCount = $myMarkedCount->fetchColumn();

$myTodayCount = $db->prepare("SELECT COUNT(*) FROM attendance WHERE marked_by=? AND attendance_date=CURDATE()");
$myTodayCount->execute([$user['id']]);
$myTodayCount = $myTodayCount->fetchColumn();

include 'layout_header.php';
?>

<div class="page-header">
    <div>
        <div class="page-header-title">My Profile</div>
        <div class="page-header-sub">Manage your account settings</div>
    </div>
</div>

<div class="row g-3">
<!-- Profile Card Left -->
<div class="col-md-4">
    <div class="card text-center mb-3">
        <div class="card-body" style="padding:32px 24px;">
            <div style="width:90px;height:90px;background:linear-gradient(135deg,var(--primary),#1a4a8a);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:2.2rem;color:#fff;font-family:'Syne',sans-serif;font-weight:800;box-shadow:0 8px 24px rgba(10,35,66,0.25);">
                <?= strtoupper(substr($profile['full_name'], 0, 1)) ?>
            </div>
            <h5 style="font-family:'Syne',sans-serif;font-weight:700;margin-bottom:4px;"><?= htmlspecialchars($profile['full_name']) ?></h5>
            <div style="font-size:0.82rem;color:var(--muted);margin-bottom:12px;">@<?= htmlspecialchars($profile['username']) ?></div>
            <span class="badge <?= roleBadgeClass($profile['role']) ?> rounded-pill px-3 py-2" style="font-size:0.82rem;">
                <?= roleLabel($profile['role']) ?>
            </span>
            <?php if ($profile['class_name']): ?>
            <div style="margin-top:12px;font-size:0.82rem;color:var(--muted);">
                <i class="bi bi-mortarboard me-1 text-warning"></i><?= htmlspecialchars($profile['class_name']) ?>
            </div>
            <?php endif; ?>
            <div style="margin-top:12px;font-size:0.78rem;color:var(--muted);">
                <i class="bi bi-calendar3 me-1"></i>Member since <?= date('M Y', strtotime($profile['created_at'])) ?>
            </div>
        </div>
    </div>

    <!-- My Stats -->
    <div class="card mb-3">
        <div class="card-header"><div class="card-header-title"><i class="bi bi-bar-chart"></i> My Stats</div></div>
        <div class="card-body p-0">
            <?php $stats = [
                ['bi-check2-circle','Total Marked',$myMarkedCount,'#10b981'],
                ['bi-calendar-check','Marked Today',$myTodayCount,'#f59e0b'],
                ['bi-people','My Students',$myStudentsCount,'#2563eb'],
            ]; foreach ($stats as $st): ?>
            <div style="padding:12px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;">
                <div style="width:36px;height:36px;background:var(--bg);border-radius:8px;display:flex;align-items:center;justify-content:center;">
                    <i class="bi <?= $st[0] ?>" style="color:<?= $st[2] ?>;font-size:1rem;"></i>
                </div>
                <div style="flex:1;">
                    <div style="font-size:0.78rem;color:var(--muted);"><?= $st[1] ?></div>
                </div>
                <div style="font-family:'Syne',sans-serif;font-weight:700;font-size:1.1rem;"><?= number_format($st[2]) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Right Column -->
<div class="col-md-8">
    <!-- Tabs -->
    <div class="card">
        <div style="border-bottom:1px solid var(--border);padding:0 24px;">
            <ul class="nav nav-tabs border-0">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabProfile" style="font-size:0.88rem;font-weight:500;"><i class="bi bi-person me-1"></i>Profile</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabPassword" style="font-size:0.88rem;font-weight:500;"><i class="bi bi-lock me-1"></i>Password</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabActivity" style="font-size:0.88rem;font-weight:500;"><i class="bi bi-activity me-1"></i>Activity</button></li>
            </ul>
        </div>
        <div class="tab-content p-4">
            <!-- Profile Tab -->
            <div class="tab-pane fade show active" id="tabProfile">
                <h6 style="font-family:'Syne',sans-serif;font-size:0.85rem;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:20px;">Edit Profile Information</h6>
                <form method="POST">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($profile['full_name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($profile['username']) ?>" disabled style="background:var(--bg);color:var(--muted);">
                            <div class="form-text">Username cannot be changed</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email *</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($profile['email']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Role</label>
                            <input type="text" class="form-control" value="<?= roleLabel($profile['role']) ?>" disabled style="background:var(--bg);color:var(--muted);">
                        </div>
                        <?php if ($profile['class_name']): ?>
                        <div class="col-md-6">
                            <label class="form-label">Assigned Class</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($profile['class_name']) ?>" disabled style="background:var(--bg);color:var(--muted);">
                        </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save Changes</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Password Tab -->
            <div class="tab-pane fade" id="tabPassword">
                <h6 style="font-family:'Syne',sans-serif;font-size:0.85rem;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:20px;">Change Password</h6>
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    <div class="row g-3" style="max-width:420px;">
                        <div class="col-12">
                            <label class="form-label">Current Password *</label>
                            <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
                        </div>
                        <div class="col-12">
                            <label class="form-label">New Password * <span class="text-muted" style="font-size:0.78rem;">(min 6 characters)</span></label>
                            <input type="password" name="new_password" class="form-control" id="newPass" required minlength="6" autocomplete="new-password">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Confirm New Password *</label>
                            <input type="password" name="confirm_password" class="form-control" id="confirmPass" required autocomplete="new-password">
                            <div id="matchMsg" style="font-size:0.78rem;margin-top:4px;"></div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-lock me-1"></i>Change Password</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Activity Tab -->
            <div class="tab-pane fade" id="tabActivity">
                <h6 style="font-family:'Syne',sans-serif;font-size:0.85rem;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:16px;">Recent Attendance Marked by You</h6>
                <?php if (empty($myActivity)): ?>
                <div class="text-center py-4 text-muted"><i class="bi bi-activity display-6 d-block mb-2"></i>No activity yet</div>
                <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:6px;">
                    <?php foreach ($myActivity as $act): ?>
                    <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:var(--bg);border-radius:8px;">
                        <div style="width:8px;height:8px;background:var(--accent2);border-radius:50%;flex-shrink:0;"></div>
                        <div style="flex:1;">
                            <div style="font-weight:500;font-size:0.85rem;"><?= htmlspecialchars($act['student_name']) ?></div>
                            <div style="font-size:0.75rem;color:var(--muted);"><?= htmlspecialchars($act['class_name']??'—') ?> · <?= formatDate($act['attendance_date']) ?> <?= $act['time_in'] ? '@ ' . date('h:i A', strtotime($act['time_in'])) : '' ?></div>
                        </div>
                        <span class="badge badge-<?= $act['status'] ?> rounded-pill" style="font-size:0.72rem;"><?= ucfirst($act['status']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div>

<?php
$extraScripts = '
<script>
document.getElementById("confirmPass").addEventListener("input", function() {
    const match = this.value === document.getElementById("newPass").value;
    const msg = document.getElementById("matchMsg");
    msg.textContent = this.value ? (match ? "✅ Passwords match" : "❌ Passwords do not match") : "";
    msg.style.color = match ? "#10b981" : "#ef4444";
});
</script>';
include 'layout_footer.php';
?>

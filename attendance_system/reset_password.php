<?php
/**
 * 🛠️ ADMIN RECOVERY & PASSWORD CONSOLE
 * Security Level: EMERGENCY USE ONLY
 * WARNING: DELETE THIS FILE IMMEDIATELY AFTER USE.
 */

// 1. Strict Localhost Check
if (!in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1', 'localhost'])) {
    http_response_code(403);
    die('<h1>Access Denied</h1>This recovery script is strictly locked to Localhost connections for security.');
}

require_once 'config.php';
$db = getDB();

$message = '';
$hash_generated = '';

/**
 * Handle Password Update
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'reset') {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if ($username && strlen($password) >= 6) {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password = ? WHERE username = ?");
            $stmt->execute([$hashed, $username]);

            if ($stmt->rowCount()) {
                $message = ['type' => 'success', 'text' => "SUCCESS: Password for <b>$username</b> updated."];
            } else {
                $message = ['type' => 'error', 'text' => "ERROR: User not found or password unchanged."];
            }
        } else {
            $message = ['type' => 'warning', 'text' => "Validation Failed: Minimum 6 characters required."];
        }
    }

    if ($action === 'generate') {
        $gen_pass = trim($_POST['gen_pass'] ?? '');
        if (!empty($gen_pass)) {
            $hash_generated = password_hash($gen_pass, PASSWORD_DEFAULT);
        }
    }

    /**
     * SELF DESTRUCT Logic
     */
    if ($action === 'self_destruct') {
        unlink(__FILE__);
        die('<h1>Script Purged</h1>For your security, this file has been deleted from the server. <a href="index.php">Go to Login</a>');
    }
}

// Fetch user directory for the dropdown
$users = $db->query("SELECT username, role, full_name FROM users ORDER BY role, full_name")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EMS — Emergency Recovery Console</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root { --navy: #071829; --gold: #f4be38; }
        body { background: #f0f2f5; font-family: 'Inter', system-ui, sans-serif; padding: 40px 0; }
        .recovery-card { max-width: 650px; margin: auto; border: none; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.1); overflow: hidden; }
        .card-header { background: var(--navy); color: white; padding: 30px; text-align: center; border: none; }
        .card-header h2 { font-weight: 800; letter-spacing: -0.02em; margin-bottom: 5px; }
        .btn-navy { background: var(--navy); color: white; border-radius: 10px; font-weight: 700; border: none; padding: 12px; }
        .btn-navy:hover { background: #0a2342; color: var(--gold); }
        .hash-box { background: #f8fafc; border: 1px dashed #cbd5e1; font-family: monospace; font-size: 0.85rem; word-break: break-all; }
        .alert { border-radius: 12px; border: none; font-size: 0.9rem; font-weight: 500; }
        .form-label { font-weight: 700; font-size: 0.75rem; text-transform: uppercase; color: #64748b; margin-left: 5px; }
        .user-list-mini { font-size: 0.8rem; background: #fff; border-radius: 12px; }
    </style>
</head>
<body>

    <div class="recovery-card card">
        <div class="card-header">
            <h2><i class="bi bi-shield-lock-fill text-warning me-2"></i>Admin Recovery</h2>
            <p class="opacity-50 small mb-0">Identity Management & Repair Utility</p>
        </div>

        <div class="card-body p-4 p-md-5">
            <!-- Warning -->
            <div class="alert alert-warning d-flex align-items-center mb-4">
                <i class="bi bi-exclamation-triangle-fill fs-3 me-3"></i>
                <div>
                    <b>Strict Security Protocol:</b><br>
                    Leaving this file on a live server allows unauthorized password resets. Use the self-destruct button below when finished.
                </div>
            </div>

            <!-- Messages -->
            <?php if ($message): ?>
                <div class="alert alert-<?= $message['type'] === 'error' ? 'danger' : $message['type'] ?> mb-4 shadow-sm">
                    <?= $message['text'] ?>
                </div>
            <?php endif; ?>

            <!-- Action: Reset Password -->
            <section class="mb-5">
                <h5 class="fw-bold text-navy mb-3">Manual Account Reset</h5>
                <form method="POST">
                    <input type="hidden" name="action" value="reset">
                    <div class="mb-3">
                        <label class="form-label">System User Directory</label>
                        <select name="username" class="form-select py-2" required>
                            <option value="">Choose User...</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?= htmlspecialchars($u['username']) ?>">
                                <?= htmlspecialchars($u['full_name']) ?> (<?= strtoupper($u['role']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password Payload</label>
                        <input type="text" name="password" class="form-control py-2" placeholder="min 6 chars" required minlength="6">
                    </div>
                    <button type="submit" class="btn btn-navy w-100 shadow-sm">Commit Override</button>
                </form>
            </section>

            <!-- Action: Hash Generator -->
            <section class="p-4 bg-light rounded-4 border">
                <h6 class="fw-bold mb-3 small"><i class="bi bi-braces me-1"></i>Secure Hash Engine</h6>
                <form method="POST">
                    <input type="hidden" name="action" value="generate">
                    <div class="input-group">
                        <input type="text" name="gen_pass" class="form-control" placeholder="Enter string to hash">
                        <button type="submit" class="btn btn-dark px-4 shadow-sm">Generate</button>
                    </div>
                </form>
                <?php if ($hash_generated): ?>
                    <div class="hash-box mt-3 p-3 rounded">
                        <span class="text-muted d-block small mb-1">PASSWORD_DEFAULT BCRYPT:</span>
                        <code><?= $hash_generated ?></code>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Dangerous Area -->
            <div class="mt-5 pt-4 border-top">
                <form method="POST" onsubmit="return confirm('Wipe this utility file from the server immediately?');">
                    <input type="hidden" name="action" value="self_destruct">
                    <button class="btn btn-danger w-100 rounded-pill py-2 fw-bold shadow-sm">
                        <i class="bi bi-trash-fill me-2"></i>CLOSE SESSION & SELF-DESTRUCT FILE
                    </button>
                </form>
                <div class="text-center mt-3">
                    <a href="index.php" class="text-decoration-none small text-muted fw-bold">Back to Main Login Portal</a>
                </div>
            </div>
        </div>
    </div>

</body>
</html>
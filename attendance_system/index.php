<?php
// 1. Secure Session Setup
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

require_once 'config.php';

// CSRF Security Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die('CSRF Security Validation Failed.');
    }

    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        $db = getDB();
        // Exact real PHP logic from your snippet
        $stmt = $db->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Prevent Session Fixation
            session_regenerate_id(true);

            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['role']      = $user['role'];
            $_SESSION['class_id']  = $user['assigned_class_id'];
            
            header('Location: dashboard.php');
            exit;
        } else {
            // Artificial delay to prevent timing attacks/brute force
            usleep(400000); 
            $error = 'Invalid credentials or inactive account.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Secure Login — Attendance Pro</title>
    <!-- Branding & Layout Dependencies -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --p-navy: #071829;
            --p-blue: #0a2342;
            --accent: #e8b84b;
            --accent-glow: rgba(232, 184, 75, 0.2);
            --bg-grid: rgba(255, 255, 255, 0.03);
            --radius-main: 16px;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--p-navy);
            min-height: 100vh;
            display: flex;
            align-items: center;
            overflow-x: hidden;
        }

        /* ── Modern Layout Design ── */
        .login-wrap {
            width: 100%;
            max-width: 1050px;
            background: rgba(10, 35, 66, 0.6);
            backdrop-filter: blur(25px);
            border-radius: 24px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            margin: auto;
            display: flex;
            min-height: 600px;
            overflow: hidden;
            box-shadow: 0 24px 50px -12px rgba(0, 0, 0, 0.5);
        }

        /* ── Branding Panel (Left) ── */
        .panel-branding {
            flex: 1.1;
            background: linear-gradient(135deg, #071829 0%, #0d3060 100%);
            padding: 4rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
        }

        /* Decorative Background */
        .panel-branding::before {
            content: ''; position: absolute; inset: 0;
            background-image: linear-gradient(var(--bg-grid) 1px, transparent 1px), linear-gradient(90deg, var(--bg-grid) 1px, transparent 1px);
            background-size: 40px 40px; pointer-events: none;
        }

        .brand-pill {
            display: inline-flex;
            background: rgba(255, 255, 255, 0.05);
            padding: 8px 16px;
            border-radius: 50px;
            color: var(--accent);
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 2rem;
            border: 1px solid rgba(232, 184, 75, 0.2);
        }

        .brand-title {
            font-family: 'Syne', sans-serif;
            font-size: 2.8rem;
            font-weight: 800;
            color: #fff;
            line-height: 1.1;
            margin-bottom: 1.5rem;
        }

        .brand-icon {
            width: 56px; height: 56px;
            background: var(--accent);
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            color: var(--p-navy); font-size: 1.5rem; margin-bottom: 20px;
            box-shadow: 0 0 20px var(--accent-glow);
        }

        /* ── Form Panel (Right) ── */
        .panel-form {
            flex: 1;
            background: #fff;
            padding: 4rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .form-header h3 { font-family: 'Syne', sans-serif; font-weight: 700; color: var(--p-navy); }
        .form-header p { color: #888; font-size: 0.95rem; }

        .input-box {
            position: relative;
            margin-bottom: 1.25rem;
        }

        .input-box i {
            position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
            color: #94a3b8; font-size: 1.1rem;
        }

        .form-control {
            border: 1.5px solid #e5e7eb;
            border-radius: 12px;
            padding: 14px 14px 14px 44px;
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            border-color: var(--p-blue);
            box-shadow: 0 0 0 4px rgba(10, 35, 66, 0.05);
            background: #f8fafc;
        }

        .btn-login {
            background: var(--p-navy);
            color: #fff;
            border: none;
            padding: 14px;
            border-radius: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            width: 100%;
            transition: 0.3s;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            background: var(--p-blue);
            box-shadow: 0 8px 20px rgba(7, 24, 41, 0.2);
        }

        .alert-custom {
            border: 1px solid #ffd0d0;
            background: #fff5f5;
            color: #c0392b;
            padding: 1rem;
            border-radius: 12px;
            font-size: 0.88rem;
            margin-bottom: 1.5rem;
        }

        /* ── Responsive Handling ── */
        @media (max-width: 991px) {
            .panel-branding { display: none; }
            .login-wrap { max-width: 450px; background: #fff; border-radius: 0; min-height: 100vh; }
            .panel-form { padding: 2.5rem; }
        }

        @media (max-width: 480px) {
            .panel-form { padding: 1.5rem; }
            .brand-mobile { display: flex !important; }
        }

        /* Mobile Branding Toggle */
        .brand-mobile {
            display: none; align-items: center; gap: 12px; margin-bottom: 2rem;
        }
    </style>
</head>
<body>

<div class="container-fluid g-0 py-lg-5">
    <div class="login-wrap mx-auto">
        
        <!-- Sidebar Detail (Biometric Theme) -->
        <div class="panel-branding d-none d-lg-flex">
            <div class="brand-pill">MANTRA L1 AUTHENTICATION SECURE</div>
            <div class="brand-icon"><i class="bi bi-fingerprint"></i></div>
            <h1 class="brand-title">Biometric<br><span>Security V2</span></h1>
            <p class="text-white opacity-75">Unified attendance & identification system powered by Mantra MLO31. Seamless school-wide visibility.</p>
            
            <div class="mt-4">
                <div class="d-flex align-items-center mb-2 gap-2 text-white small opacity-75">
                    <i class="bi bi-patch-check-fill text-accent"></i> Real-time Dashboard Sync
                </div>
                <div class="d-flex align-items-center gap-2 text-white small opacity-75">
                    <i class="bi bi-patch-check-fill text-accent"></i> Encrypted Audit Logs
                </div>
            </div>
        </div>

        <!-- Authentication Panel -->
        <div class="panel-form">
            <div class="brand-mobile">
                <div class="brand-icon mb-0" style="width: 40px; height: 40px; font-size: 1rem;"><i class="bi bi-fingerprint"></i></div>
                <h5 class="fw-bold mb-0" style="color:var(--p-navy)">ATTENDANCE PRO</h5>
            </div>

            <div class="form-header mb-4">
                <h3>System Sign-In</h3>
                <p>Welcome to the <strong>Egyptian Modern Model</strong> portal.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-custom">
                    <i class="bi bi-shield-lock-fill me-2"></i><?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <!-- Security: Real CSRF PHP Token -->
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <div class="input-box">
                    <i class="bi bi-person"></i>
                    <input type="text" name="username" class="form-control" placeholder="Email or Username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                </div>

                <div class="input-box mb-4">
                    <i class="bi bi-key-fill"></i>
                    <input type="password" name="password" id="passwordField" class="form-control" placeholder="Security Password" required>
                    <i class="bi bi-eye position-absolute" style="left: auto; right: 14px; cursor: pointer;" onclick="togglePW()"></i>
                </div>

                <button type="submit" class="btn-login" id="submitBtn">
                    Verify Identity <i class="bi bi-arrow-right-short"></i>
                </button>
            </form>

            <footer class="mt-5 text-center small opacity-50">
                &copy; <?= date('Y') ?> <?= SCHOOL_NAME ?>
            </footer>
        </div>
    </div>
</div>

<script>
    function togglePW() {
        const field = document.getElementById('passwordField');
        field.type = field.type === 'password' ? 'text' : 'password';
    }

    // UX: Prevent Double Submit
    document.querySelector('form').onsubmit = function() {
        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>Authenticating...`;
    };
</script>

</body>
</html>
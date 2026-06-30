<?php
require_once '../includes/db.php';
startSecureSession();

// 1. INITIALIZE CAPTCHA IMMEDIATELY
// This ensures variables exist before the HTML tries to display them
if (!isset($_SESSION['captcha_n1'])) {
    $_SESSION['captcha_n1'] = rand(1, 12);
    $_SESSION['captcha_n2'] = rand(1, 12);
    $_SESSION['captcha_ans'] = $_SESSION['captcha_n1'] + $_SESSION['captcha_n2'];
}

$error = '';
$step = 'login'; 

// Persistence: If user passed password but not math, keep them on math step
if (isset($_SESSION['user_pending_id'])) {
    $step = 'math_verify';
}

// -------------------------------------------------------
// HANDLE POST ACTIONS
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // CSRF Check
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } 
    
    // ACTION: INITIAL LOGIN (Username & Password)
    elseif ($_POST['action'] === 'login') {
        $username = sanitizeInput($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Please fill in all fields.';
        } else {
            $db = getDB();
            $stmt = $db->prepare('SELECT id, username, password_hash FROM users WHERE username = ? LIMIT 1');
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();

            // Check if user exists and password is valid
            if ($user && password_verify($password, $user['password_hash'])) {
                // Success Part 1: Set pending session and move to Math
                $_SESSION['user_pending_id']   = $user['id'];
                $_SESSION['user_pending_name'] = $user['username'];
                $step = 'math_verify';
            } else {
                $error = 'Invalid username or password.';
                sleep(1); // Anti-brute force delay
            }
        }
    } 

    // ACTION: VERIFY MATH CAPTCHA
    elseif ($_POST['action'] === 'verify_math') {
        $user_answer = isset($_POST['math_answer']) ? (int)$_POST['math_answer'] : null;
        $correct_answer = (int)$_SESSION['captcha_ans'];

        if (isset($_SESSION['user_pending_id']) && $user_answer === $correct_answer) {
            // Success Part 2: Finalize login
            $_SESSION['user_id']   = $_SESSION['user_pending_id'];
            $_SESSION['user_name'] = $_SESSION['user_pending_name'];
            
            // Cleanup security sessions
            unset($_SESSION['user_pending_id'], $_SESSION['user_pending_name'], $_SESSION['captcha_ans'], $_SESSION['captcha_n1'], $_SESSION['captcha_n2']);

            session_regenerate_id(true);
            header('Location: dashboard.php'); 
            exit;
        } else {
            $error = 'Incorrect math answer. Please try again.';
            $step = 'math_verify';
            // Force new numbers on failure
            $_SESSION['captcha_n1'] = rand(1, 12);
            $_SESSION['captcha_n2'] = rand(1, 12);
            $_SESSION['captcha_ans'] = $_SESSION['captcha_n1'] + $_SESSION['captcha_n2'];
        }
    }
}

// Reset logic if user wants to go back to login screen
if (isset($_GET['reset'])) {
    unset($_SESSION['user_pending_id'], $_SESSION['user_pending_name']);
    header("Location: login.php");
    exit();
}

$csrf  = generateCSRFToken();
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Login — AldiFoods</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="auth-wrap">
<div class="auth-card">
    <div class="auth-logo">
        <span>AldiFoods</span>
        <span class="badge" style="background:rgba(74,158,255,.15);color:var(--info)">User</span>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?>"><?= htmlspecialchars($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($step === 'login'): ?>
        <h2>Sign In</h2>
        <p class="subtitle">Welcome back to AldiFoods</p>
        <form method="POST" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="login">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required placeholder="Your username"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required placeholder="Your password">
            </div>
            <button type="submit" class="btn btn-primary">Continue →</button>
            <div style="text-align: center; margin-top: 15px;">
              <a href="forgot-password.php" class="link" style="font-size: 0.85rem;">Forgot password?</a>
            </div>
        </form>
        <hr class="divider">
        <p class="text-sm text-muted" style="text-align:center">
            Don't have an account? <a href="register.php" class="link">Register</a>
        </p>
        <p class="text-sm text-muted mt-1" style="text-align:center">
            <a href="../admin/login.php" class="link">Admin Login</a>
        </p>

    <?php elseif ($step === 'math_verify'): ?>
        <h2>Human Check</h2>
        <p class="subtitle">Solve this simple addition to proceed.</p>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="verify_math">
            
            <div class="math-box" style="background: #f4f7f6; border: 2px solid #e0e6ed; border-radius: 12px; padding: 25px; text-align: center; font-size: 2rem; font-weight: 800; color: #2d3748; margin-bottom: 1.5rem;">
                <?= $_SESSION['captcha_n1'] ?> + <?= $_SESSION['captcha_n2'] ?> = ?
            </div>

            <div class="form-group">
                <input type="number" name="math_answer" class="form-control" placeholder="Answer" required autofocus style="text-align: center; font-size: 1.2rem;">
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%;">Verify & Sign In →</button>
        </form>

        <div class="mt-2 text-sm text-muted" style="text-align:center">
            <a href="login.php?reset=1" class="link">← Back to login</a>
        </div>
    <?php endif; ?>
</div>
</div>
</body>
</html>

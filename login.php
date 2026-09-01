<?php
require_once '../includes/db.php';
startSecureSession();

$error = '';

// -------------------------------------------------------
// HANDLE POST ACTIONS
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // CSRF Check
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } 
    
    // ACTION: LOGIN (Username & Password)
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
                // Success: Finalize login instantly
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['user_name'] = $user['username'];

                session_regenerate_id(true);
                header('Location: dashboard.php'); 
                exit;
            } else {
                $error = 'Invalid username or password.';
                sleep(1); // Anti-brute force delay
            }
        }
    }
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
        
        <button type="submit" class="btn btn-primary" style="width: 100%;">Continue →</button>
    </form>
    
    <hr class="divider">
    <p class="text-sm text-muted" style="text-align:center">
        Don't have an account? <a href="register.php" class="link">Register</a>
    </p>
    <p class="text-sm text-muted mt-1" style="text-align:center">
        <a href="../admin/login.php" class="link">Admin Login</a>
    </p>
</div>
</div>
</body>
</html>
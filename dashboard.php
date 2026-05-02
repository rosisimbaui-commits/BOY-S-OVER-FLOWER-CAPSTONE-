<?php
require_once '../includes/db.php';
requireUserLogin();

$db = getDB();
$userId = $_SESSION['user_id'] ?? 0;

// 1. Fetch user info
$stmt = $db->prepare('SELECT username, email, full_name, phone, address, created_at FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Decrypt sensitive data
$user['email']     = decryptData($user['email'] ?? '');
$user['full_name'] = decryptData($user['full_name'] ?? '');
$user['phone']     = decryptData($user['phone'] ?? '');
$user['address']   = decryptData($user['address'] ?? '');

// 2. Fetch "Time In"
$timeIn = "Not Logged Today";

try {
    $logCheck = $db->prepare("SELECT created_at FROM user_logs WHERE user_id = ? AND action = 'login' ORDER BY id DESC LIMIT 1");
    if ($logCheck) {
        $logCheck->bind_param("i", $userId);
        $logCheck->execute();
        $res = $logCheck->get_result()->fetch_assoc();
        if ($res) {
            $timeIn = date('h:i A', strtotime($res['created_at']));
        }
        $logCheck->close();
    }
} catch (mysqli_sql_exception $e) {
    $timeIn = "Setup Required"; 
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — ALDiFOODS</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        :root { 
            --bg-main: #121212;         
            --card-bg: #1e1e1e;         
            --primary-green: #2ecc71;   
            --muted-text: #888888;
            --border-color: #2a2a2a;
        }

        body { background: var(--bg-main); color: #ffffff; font-family: 'Inter', sans-serif; margin: 0; display: flex; }
        .layout { display: flex; width: 100%; min-height: 100vh; }
        .main-content { flex: 1; padding: 40px; }
        .page-header { margin-bottom: 30px; }
        .breadcrumb { color: var(--muted-text); font-size: 0.85rem; margin-top: 5px; text-transform: uppercase; letter-spacing: 1px; }
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: var(--card-bg); padding: 25px; border-radius: 12px; border: 1px solid var(--border-color); box-shadow: 0 4px 15px rgba(0,0,0,0.3); }
        .stat-label { font-size: 0.7rem; color: var(--muted-text); text-transform: uppercase; font-weight: 600; }
        .stat-value { font-size: 1.3rem; font-weight: 700; margin-top: 10px; color: #fff; }
        .green-text { color: var(--primary-green); }
        .blue-text { color: #3498db; }
        .table-card { background: var(--card-bg); border-radius: 12px; border: 1px solid var(--border-color); overflow: hidden; }
        .table-card-header { padding: 20px 25px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; padding: 25px; }
        .info-group label { font-size: 0.7rem; color: var(--muted-text); text-transform: uppercase; display: block; margin-bottom: 5px; }
        .info-group div { font-weight: 600; font-size: 1rem; color: #eee; }
        .btn { padding: 12px 24px; border-radius: 8px; font-weight: 600; text-decoration: none; transition: 0.2s; display: inline-block; text-align: center; }
        .btn-outline { border: 1px solid var(--border-color); color: #fff; font-size: 0.8rem; }
        .btn-outline:hover { background: rgba(255,255,255,0.05); border-color: var(--primary-green); }
        .btn-settings { background: #252525; color: #fff; border: 1px solid var(--border-color); margin-top: 20px; width: 100%; box-sizing: border-box; }
    </style>
</head>
<body>
<div class="layout">
    <?php include '_sidebar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h1 style="margin:0; font-size: 1.8rem;">Hello, <span class="green-text"><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></span> 👋</h1>
            <div class="breadcrumb">Employee Dashboard — ALDiFOODS Portal</div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Account Status</div>
                <div class="stat-value green-text">● Active</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Member Since</div>
                <div class="stat-value"><?= date('M Y', strtotime($user['created_at'] ?: 'now')) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Current Time In</div>
                <div class="stat-value blue-text">🕒 <?= $timeIn ?></div>
            </div>
        </div>

        <div class="table-card">
            <div class="table-card-header">
                <h3 style="margin:0; font-size:1.1rem; color: var(--primary-green);">Account Information</h3>
                <a href="profile.php" class="btn btn-outline">Edit Profile</a>
            </div>
            <div class="grid-2">
                <div class="info-group">
                    <label>Username</label>
                    <div><?= htmlspecialchars($user['username']) ?></div>
                </div>
                <div class="info-group">
                    <label>Email Address</label>
                    <div><?= htmlspecialchars($user['email']) ?></div>
                </div>
                <div class="info-group">
                    <label>Full Name</label>
                    <div><?= htmlspecialchars($user['full_name'] ?: 'Not Provided') ?></div>
                </div>
                <div class="info-group">
                    <label>Phone</label>
                    <div><?= htmlspecialchars($user['phone'] ?: '—') ?></div>
                </div>
            </div>
        </div>

        <a href="profile.php" class="btn btn-settings">⚙️ Update Personal Settings</a>
    </div>
</div>
</body>
</html>
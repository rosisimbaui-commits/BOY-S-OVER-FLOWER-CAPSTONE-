<?php
require_once '../includes/db.php';
requireUserLogin(); 

$db = getDB();
$message = "";

// --- SAFETY CHECK ---
$db->query("CREATE TABLE IF NOT EXISTS pack_yields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_id INT NOT NULL,
    user_id INT NOT NULL,
    actual_grams DECIMAL(10,2) NOT NULL,
    total_packs_produced INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// 1. HANDLE FORM SUBMISSION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_yield'])) {
    $batchId = (int)$_POST['batch_id'];
    $gramsArray = $_POST['actual_grams'] ?? [];
    $qtyArray = $_POST['pack_qty'] ?? [];
    $userId = $_SESSION['user_id'] ?? 0; 
    
    if ($batchId > 0 && !empty($gramsArray)) {
        $successCount = 0;
        $stmt = $db->prepare("INSERT INTO pack_yields (batch_id, user_id, actual_grams, total_packs_produced) VALUES (?, ?, ?, ?)");
        
        foreach ($gramsArray as $index => $grams) {
            $gramsValue = (float)$grams;
            $qtyValue = (int)$qtyArray[$index];

            if ($gramsValue > 0 && $qtyValue > 0) {
                $stmt->bind_param("iiii", $batchId, $userId, $gramsValue, $qtyValue);
                if ($stmt->execute()) { 
                    $successCount++; 
                }
            }
        }
        
        if ($successCount > 0) {
            $message = "<div class='alert success'>✅ $successCount entries logged successfully!</div>";
        }
    } else {
        $message = "<div class='alert' style='background:rgba(231,76,60,0.1); color:#e74c3c;'>❌ Please fill in all fields correctly.</div>";
    }
}

// 2. FETCH BATCHES
$batches = $db->query("SELECT id, batch_number, product_name FROM production_batches ORDER BY production_datetime DESC");

// 3. FETCH HISTORY
$historyQuery = "SELECT 
                    pb.batch_number, 
                    pb.product_name, 
                    py.actual_grams, 
                    py.total_packs_produced, 
                    py.created_at,
                    u.username as logged_by
                 FROM pack_yields py
                 JOIN production_batches pb ON py.batch_id = pb.id
                 LEFT JOIN users u ON py.user_id = u.id
                 ORDER BY py.created_at DESC LIMIT 50";

$yieldLogs = $db->query($historyQuery);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Aldi Foods | Packs Yield Tracker</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        :root { 
            --bg-main: #f5f6fa; 
            --card-bg: #ffffff; 
            --text-main: #111111; 
            --primary-orange: #e67e22; 
            --muted-text: #666666; 
            --border-color: #dddddd; 
            --input-bg: #ffffff; 
        }
        body { background: var(--bg-main); color: var(--text-main); font-family: 'Inter', sans-serif; margin: 0; display: flex; }
        .layout { display: flex; width: 100%; min-height: 100vh; }
        .main-content { flex: 1; padding: 40px; overflow-y: auto; }
        h1 { color: var(--text-main); margin-bottom: 5px; }
        .title-subtext { color: var(--muted-text); font-size: 0.95rem; margin-top: 0; margin-bottom: 30px; font-weight: 500; }
        
        /* Glass Card Titles */
        .glass-card { background: var(--card-bg); border-radius: 12px; padding: 25px; margin-bottom: 30px; border: 1px solid var(--border-color); box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .section-title { font-weight: 700; color: var(--text-main); border-left: 4px solid var(--primary-orange); padding-left: 15px; margin-bottom: 25px; text-transform: uppercase; }
        .orange-text { color: var(--primary-orange); }
        
        /* Pack Metrics Configuration Title - Set to Black */
        .config-header { font-size: 0.85rem; font-weight: 800; color: var(--text-main); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 15px; display: block; }
        
        /* Grid Fields Setup */
        .yield-row-header { display: grid; grid-template-columns: 1fr 1fr 50px; gap: 15px; margin-bottom: 10px; }
        .yield-row { display: grid; grid-template-columns: 1fr 1fr 50px; gap: 15px; margin-bottom: 10px; align-items: center; }
        
        .input-group label { font-size: 0.7rem; color: var(--muted-text); text-transform: uppercase; margin-bottom: 8px; display: block; }
        input, select { padding: 12px; border: 1px solid var(--border-color); background: var(--input-bg); color: var(--text-main); border-radius: 8px; width: 100%; box-sizing: border-box; }
        input::placeholder { color: #aaa; }
        
        /* Button Variants Styles */
        .btn-add { background: transparent; color: var(--text-main); border: 1px dashed var(--border-color); padding: 10px; border-radius: 8px; cursor: pointer; width: 100%; margin-bottom: 20px; font-weight: 600; }
        .btn-add:hover { background: #f9f9f9; border-color: var(--muted-text); }
        .btn-remove { background: #e74c3c; color: white; border: none; padding: 12px; border-radius: 8px; cursor: pointer; height: 42px; }
        .btn-save { background: var(--primary-orange); color: #fff; padding: 14px; border-radius: 8px; border: none; font-weight: 700; cursor: pointer; width: 100%; }

        /* Tables Data Format styling */
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th { text-align: left; color: var(--muted-text); padding: 15px; border-bottom: 2px solid var(--border-color); font-size: 0.7rem; text-transform: uppercase; }
        .data-table td { padding: 12px 15px; border-bottom: 1px solid #eeeeee; font-size: 0.9rem; color: var(--text-main); }
        
        .batch-header-row { background: rgba(230, 126, 34, 0.05); }
        .batch-tag { color: var(--primary-orange); font-weight: 800; font-size: 0.9rem; }
        .pack-item { color: #333333; border-left: 2px solid var(--primary-orange); padding-left: 10px; }
        
        .user-badge { font-size: 0.7rem; color: var(--muted-text); background: #f0f0f0; padding: 3px 8px; border-radius: 4px; border: 1px solid var(--border-color); }
        .alert { padding: 15px; border-radius: 6px; margin-bottom: 20px; font-size: 0.9rem; font-weight: 600; }
        .alert.success { background: rgba(230, 126, 34, 0.1); color: var(--primary-orange); border: 1px solid rgba(230, 126, 34, 0.2); }
    </style>
</head>
<body>

<div class="layout">
    <?php include '_sidebar.php'; ?>

    <div class="main-content">
        <h1>Packed <span class="orange-text">Yields</span></h1>
        <p class="title-subtext">Log finishing metrics and output configurations per item batch</p>
        
        <?= $message ?>

        <div class="glass-card">
            <h3 class="section-title">Log Packing Results</h3>
            <form method="POST">
                <div class="input-group" style="margin-bottom: 25px; max-width: 400px;">
                    <label>Active Production Batch</label>
                    <select name="batch_id" required>
                        <option value="">-- Choose batch --</option>
                        <?php while($b = $batches->fetch_assoc()): ?>
                            <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['batch_number']) ?> - <?= htmlspecialchars($b['product_name']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div id="rowContainer">
                    <span class="config-header">PACK METRICS CONFIGURATION</span>
                    <div class="yield-row-header">
                        <label style="font-size: 0.7rem; color: var(--muted-text); text-transform: uppercase;">Actual (Grams/Pack)</label>
                        <label style="font-size: 0.7rem; color: var(--muted-text); text-transform: uppercase;">Qty Packed (Units)</label>
                        <div></div>
                    </div>

                    <div class="yield-row">
                        <div class="input-group">
                            <input type="number" name="actual_grams[]" step="0.01" placeholder="e.g. 500" required>
                        </div>
                        <div class="input-group">
                            <input type="number" name="pack_qty[]" placeholder="e.g. 100" required>
                        </div>
                        <div></div>
                    </div>
                </div>

                <button type="button" class="btn-add" onclick="addRow()">+ Add Different Pack Size</button>
                <button type="submit" name="log_yield" class="btn-save">Save All Packs</button>
            </form>
        </div>

        <div class="glass-card">
            <h3 class="section-title">🕒 Combined <span class="orange-text">Yield</span> History</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 35%;">Batch & Product</th>
                        <th style="width: 45%;">Pack Variations</th>
                        <th style="width: 20%;">Logged By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $currentBatch = null;
                    if($yieldLogs && $yieldLogs->num_rows > 0): 
                        while($log = $yieldLogs->fetch_assoc()): 
                            $batchKey = $log['batch_number'] . $log['created_at']; 
                            if ($currentBatch !== $batchKey): 
                                $currentBatch = $batchKey;
                    ?>
                            <tr class="batch-header-row">
                                <td>
                                    <span class="batch-tag">#<?= htmlspecialchars($log['batch_number']) ?></span><br>
                                    <small style="font-weight:600; color: #111111;"><?= htmlspecialchars($log['product_name']) ?></small>
                                </td>
                                <td></td>
                                <td>
                                    <small style="color:#666; font-weight: 500;"><?= date('M d, H:i', strtotime($log['created_at'])) ?></small>
                                </td>
                            </tr>
                    <?php endif; ?>
                            <tr>
                                <td></td>
                                <td>
                                    <div class="pack-item">
                                        <strong><?= number_format($log['actual_grams'], 1) ?>g</strong> 
                                        &nbsp;&rarr;&nbsp; 
                                        <?= number_format($log['total_packs_produced']) ?> Units
                                    </div>
                                </td>
                                <td>
                                    <span class="user-badge">👤 <?= htmlspecialchars($log['logged_by'] ?? 'Unknown User') ?></span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="3" style="text-align:center; color:var(--muted-text); padding: 50px;">No yield data recorded.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function addRow() {
    const container = document.getElementById('rowContainer');
    const newRow = document.createElement('div');
    newRow.className = 'yield-row';
    newRow.innerHTML = `
        <div class="input-group"><input type="number" name="actual_grams[]" step="0.01" placeholder="Grams" required></div>
        <div class="input-group"><input type="number" name="pack_qty[]" placeholder="Quantity" required></div>
        <button type="button" class="btn-remove" onclick="this.parentElement.remove()">×</button>
    `;
    container.appendChild(newRow);
}
</script>

</body>
</html>

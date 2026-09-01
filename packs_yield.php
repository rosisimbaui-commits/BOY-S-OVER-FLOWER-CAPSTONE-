 <?php
// 1. Database Connection & Requirements
require_once '../includes/db.php';
requireUserLogin(); 

$db = getDB();
$error = '';
$success = '';

// 2. Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_yield'])) {
    $batchId = (int)$_POST['batch_id'];
    $gramsArray = $_POST['actual_grams'] ?? [];
    $qtyArray = $_POST['pack_qty'] ?? [];
    
    // Capture the current logged-in User ID from the session
    $userId = $_SESSION['user_id'] ?? 0; 
    
    if ($batchId > 0 && !empty($gramsArray)) {
        $successCount = 0;
        
        $stmt = $db->prepare("INSERT INTO production_yields (batch_id, user_id, actual_grams, total_packs_produced) VALUES (?, ?, ?, ?)");
        
        foreach ($gramsArray as $index => $grams) {
            $gramsValue = (float)$grams;
            $qtyValue = (int)$qtyArray[$index];

            if ($gramsValue > 0 && $qtyValue > 0) {
                $stmt->bind_param("iidi", $batchId, $userId, $gramsValue, $qtyValue);
                if ($stmt->execute()) { 
                    $successCount++; 
                }
            }
        }
        
        if ($successCount > 0) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?success=" . $successCount);
            exit();
        } else {
            $error = "No valid pack variants were filled out.";
        }
    } else {
        $error = "Invalid form submission configuration.";
    }
}

if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']) . " pack yield entries logged successfully!";
}

// 3. FETCH BATCHES FOR THE DROPDOWN
$batches = $db->query("SELECT id, batch_number, product_name FROM production_batches ORDER BY production_datetime DESC");

// 4. FETCH COMBINED HISTORY
$historyQuery = "SELECT 
                    pb.batch_number, 
                    pb.product_name, 
                    py.actual_grams, 
                    py.total_packs_produced, 
                    py.created_at,
                    u.username as logged_by
                 FROM production_yields py
                 JOIN production_batches pb ON py.batch_id = pb.id
                 LEFT JOIN users u ON py.user_id = u.id
                 ORDER BY py.created_at DESC LIMIT 30";
$yieldLogs = $db->query($historyQuery);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Packed Yields Tracker — ALDiFOODS</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style> 
        .input-row { display: grid; grid-template-columns: 1fr; gap: 20px; margin-bottom: 25px; }
        .yield-item { display: grid; grid-template-columns: 2fr 1fr auto; gap: 10px; margin-bottom: 12px; align-items: center; }
        .yield-item input { margin-bottom: 0; }
        .remove-btn { color: #e74c3c; cursor: pointer; border: none; background: none; font-size: 1.4rem; padding: 0 10px; line-height: 1; }
        
        /* Contextual visual treatments for layout alignment */
        .batch-header-row { background: rgba(255, 255, 255, 0.02); }
        .pack-item-border { border-left: 2px solid var(--accent); padding-left: 10px; margin-left: 5px; }
        .user-badge { font-size: 0.75rem; color: var(--text-muted); background: rgba(255,255,255,0.03); padding: 3px 8px; border-radius: 4px; border: 1px solid rgba(255,255,255,0.05); }
    </style>
</head>
<body>
<div class="layout">
    <?php include '_sidebar.php'; ?>
    
    <div class="main-content">
        <!-- PAGE HEADER -->
        <div class="page-header">
            <div>
                <h1>Packed <span class="text-accent">Yields</span></h1>
                <div class="breadcrumb">Log finishing metrics and output configurations per item batch</div>
            </div>
        </div>

        <!-- NOTIFICATION ALERTS -->
        <?php if ($error):   ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

        <!-- STACKED LAYOUT (VERTICAL DIRECTION) -->
        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
            
            <!-- PACKING RESULTS CARD FORM (TOP) -->
            <div class="table-card">
                <div class="table-card-header">
                    <h3>Log Packing Results</h3>
                </div>
                <div style="padding: 1.5rem;">
                    <form method="POST" id="yieldForm">
                        <div class="input-row">
                            <div class="form-group">
                                <label style="font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em;">Active Production Batch</label>
                                <select name="batch_id" required>
                                    <option value="">-- Choose batch --</option>
                                    <?php while($b = $batches->fetch_assoc()): ?>
                                        <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['batch_number']) ?> - <?= htmlspecialchars($b['product_name']) ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label style="font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; margin-bottom: 12px; display: block;">Pack Metrics Configuration</label>
                            <div id="rowContainer">
                                <div class="yield-item">
                                    <input type="number" name="actual_grams[]" step="0.01" placeholder="Actual Weight (e.g. 500g)" required>
                                    <input type="number" name="pack_qty[]" placeholder="Quantity Packed Units" required>
                                    <div style="width: 44px;"></div>
                                </div>
                            </div>
                        </div>
                        
                        <button type="button" class="btn btn-outline btn-sm" style="width: 100%; justify-content: center; margin-top: 5px;" onclick="addRow()">+ Add Different Pack Size</button>
                        
                        <div style="margin-top: 30px; text-align: right;">
                            <button type="submit" name="log_yield" class="btn btn-primary">Save All Packs</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- COMBINED YIELD HISTORY DATA CARD (BOTTOM) -->
            <div class="table-card">
                <div class="table-card-header">
                    <h3>Combined Yield History</h3>
                    <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: 500; float: right; margin-top: -20px;">
                        Showing last 24 hours
                    </span>
                </div>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; width: 30%;">Batch & Product</th>
                                <th style="font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; width: 30%;">Pack Variations</th>
                                <th style="font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; width: 30%;">Logged By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $currentBatchKey = null;
                            if($yieldLogs && $yieldLogs->num_rows > 0): 
                                while($log = $yieldLogs->fetch_assoc()): 
                                    $batchGroupKey = $log['batch_number'] . '_' . $log['created_at']; 
                                    
                                    if ($currentBatchKey !== $batchGroupKey): 
                                        $currentBatchKey = $batchGroupKey;
                            ?>
                                    <!-- Contextual Meta Header Row -->
                                    <tr class="batch-header-row">
                                        <td colspan="3" style="padding-top: 15px; padding-bottom: 15px; border-bottom: 1px solid rgba(255,255,255,0.05);">
                                            <span style="font-weight: 700; color: var(--accent); margin-right: 10px;">#<?= htmlspecialchars($log['batch_number']) ?></span> 
                                            <span style="font-weight: 600; color: var(--text);"><?= htmlspecialchars($log['product_name']) ?></span>
                                            <span style="font-size: 0.8rem; color: var(--text-muted); float: right; margin-right: 1px; font-weight: 500;">
                                                <?= date('M d, Y — h:i A', strtotime($log['created_at'])) ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    
                                    <!-- Pack Variant Data Item Row -->
                                    <tr>
                                        <td></td> 
                                        <td>
                                            <div class="pack-item-border" style="font-size: 0.85rem; color: var(--text);">
                                                <strong><?= number_format($log['actual_grams'], 1) ?>g</strong> 
                                                <span style="color: var(--text-muted); margin: 0 8px;">&rarr;</span> 
                                                <span style="font-weight: 600;"><?= number_format($log['total_packs_produced']) ?></span> Units
                                            </div>
                                        </td>
                                        <td>
                                            <span class="user-badge"><?= htmlspecialchars($log['logged_by'] ?? 'Unknown User') ?></span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" style="text-align: center; padding: 3rem;" class="text-muted">
                                        <div style="font-size: 1.5rem; margin-bottom: 0.5rem;">⏱️</div>
                                        No logs recorded in the last 24 hours.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
function addRow() {
    const container = document.getElementById('rowContainer');
    const newRow = document.createElement('div');
    newRow.className = 'yield-item';
    newRow.innerHTML = `
        <input type="number" name="actual_grams[]" step="0.01" placeholder="Actual Weight (Grams)" required>
        <input type="number" name="pack_qty[]" placeholder="Quantity Units" required>
        <button type="button" class="remove-btn" onclick="this.parentElement.remove()">×</button>
    `;
    container.appendChild(newRow);
}
</script>
</body>
</html>
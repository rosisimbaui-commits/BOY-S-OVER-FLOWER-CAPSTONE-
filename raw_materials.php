<?php
// 1. Database Connection & Requirements
require_once '../includes/db.php';
requireUserLogin(); 

$db = getDB();
$error = '';
$success = '';

// 2. Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_batch'])) {
    $batchNo  = trim($_POST['batch_number']); 
    $product  = $_POST['product_name'];
    $m_names  = $_POST['material_name'] ?? []; 
    $m_kgs    = $_POST['material_kg'] ?? []; 
    
    $currentUser = $_SESSION['user_name'] ?? 'System'; 

    $checkStmt = $db->prepare("SELECT id FROM production_batches WHERE batch_number = ? LIMIT 1");
    $checkStmt->bind_param("s", $batchNo);
    $checkStmt->execute();
    $result = $checkStmt->get_result();

    if ($result->num_rows > 0) {
        $error = "Batch #$batchNo already exists!";
    } else {
        $db->begin_transaction();
        try {
            $stmt = $db->prepare("INSERT INTO production_batches (batch_number, product_name, created_by) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $batchNo, $product, $currentUser);
            $stmt->execute();
            $batchId = $db->insert_id;

            $stmtMat = $db->prepare("INSERT INTO batch_materials (batch_id, material_name, original_kg) VALUES (?, ?, ?)");
            
            foreach ($m_names as $index => $name) {
                if (!empty(trim($name))) {
                    $qty = !empty($m_kgs[$index]) ? (float)$m_kgs[$index] : 0;
                    $name_clean = sanitizeInput($name);
                    $stmtMat->bind_param("isd", $batchId, $name_clean, $qty);
                    $stmtMat->execute();
                }
            }

            $db->commit();
            header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
            exit();
        } catch (Exception $e) {
            $db->rollback();
            $error = "Error: " . $e->getMessage();
        }
    }
}

if (isset($_GET['success'])) {
    $success = "Record saved successfully!";
}

$historyQuery = "SELECT pb.batch_number, pb.product_name, pb.created_by, pb.production_datetime,
                 GROUP_CONCAT(CONCAT(bm.material_name, ' (', bm.original_kg, 'kg)') SEPARATOR ' • ') as materials 
                 FROM production_batches pb
                 LEFT JOIN batch_materials bm ON pb.id = bm.batch_id
                 GROUP BY pb.id
                 ORDER BY pb.production_datetime DESC LIMIT 15";
$history = $db->query($historyQuery);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Raw Materials Management — ALDiFOODS</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style> 
        .input-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px; }
        .material-item { display: grid; grid-template-columns: 2fr 1fr auto; gap: 10px; margin-bottom: 12px; }
        .remove-btn { color: #e74c3c; cursor: pointer; border: none; background: none; font-size: 1.4rem; padding: 0 10px; line-height: 1; }
    </style>
</head>
<body>
<div class="layout">
    <?php include '_sidebar.php'; ?>
    
    <div class="main-content">
        <!-- PAGE HEADER -->
        <div class="page-header">
            <div>
                <h1>Raw Materials <span class="text-accent">Management</span></h1>
                <div class="breadcrumb">Configure production batch items and tracking records</div>
            </div>
        </div>

        <!-- NOTIFICATION ALERTS -->
        <?php if ($error):   ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

        <!-- STACKED LAYOUT (VERTICAL DIRECTION) -->
        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
            
            <!-- BATCH ENTRY CARD FORM (TOP) -->
            <div class="table-card">
                <div class="table-card-header">
                    <h3>Batch Essentials</h3>
                </div>
                <div style="padding: 1.5rem;">
                    <form method="POST" id="productionForm">
                        <div class="input-row">
                            <div class="form-group">
                                <label style="font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em;">Batch Number</label>
                                <input type="text" name="batch_number" placeholder="e.g. ALDI-BT-101" required>
                            </div>
                            <div class="form-group">
                                <label style="font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em;">Product Name</label>
                                <input type="text" name="product_name" placeholder="Product Name" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label style="font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; margin-bottom: 12px; display: block;">Raw Materials List</label>
                            <div id="materials-container">
                                <div class="material-item">
                                    <input type="text" name="material_name[]" placeholder="Material Name" required>
                                    <input type="number" step="0.01" name="material_kg[]" placeholder="Kg" required>
                                    <div style="width: 44px;"></div>
                                </div>
                            </div>
                        </div>
                        
                        <button type="button" class="btn btn-outline btn-sm" style="width: 100%; justify-content: center; margin-top: 5px;" onclick="addMaterialField()">+ Add Additional Material Row</button>
                        
                        <div style="margin-top: 30px; text-align: right;">
                            <button type="submit" name="save_batch" class="btn btn-primary">Save Batch</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ACTIVITY LOGS DATA CARD (BOTTOM) -->
            <div class="table-card">
                <div class="table-card-header">
                    <h3>Production Activity Logs</h3>
                </div>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em;">Batch & Author</th>
                                <th style="font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em;">Composition</th>
                                <th style="font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em;">Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($history && $history->num_rows > 0): ?>
                                <?php while ($row = $history->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 700; color: var(--accent);"><?= htmlspecialchars($row['batch_number']) ?></div>
                                            <div style="font-size: 0.85rem; color: var(--text);"><?= htmlspecialchars($row['product_name']) ?></div>
                                            <span class="text-sm text-muted">User: <?= htmlspecialchars($row['created_by']) ?></span>
                                        </td>
                                        <td style="font-size: 0.85rem; color: var(--text-muted); max-width: 250px; line-height: 1.4; word-break: break-word;">
                                            <?= htmlspecialchars($row['materials'] ?? 'No materials logged') ?>
                                        </td>
                                        <td style="font-size: 0.85rem;">
                                            <div style="color: var(--text);"><?= date('M d, Y', strtotime($row['production_datetime'])) ?></div>
                                            <div class="text-muted"><?= date('h:i A', strtotime($row['production_datetime'])) ?></div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" style="text-align: center; padding: 2rem;" class="text-muted">No production activity recorded.</td>
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
function addMaterialField() {
    const container = document.getElementById('materials-container');
    const div = document.createElement('div');
    div.className = 'material-item';
    div.innerHTML = `
        <input type="text" name="material_name[]" placeholder="Additional Material" required>
        <input type="number" step="0.01" name="material_kg[]" placeholder="Kg" required>
        <button type="button" class="remove-btn" onclick="this.parentElement.remove()">×</button>
    `;
    container.appendChild(div);
}
</script>
</body>
</html>

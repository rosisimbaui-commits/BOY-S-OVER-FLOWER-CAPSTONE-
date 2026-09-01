<?php
require_once '../includes/db.php';
requireAdminLogin(); 

$db = getDB();

// 1. MASTER QUERY - Keep matching your exact configurations
$query = "SELECT 
    pb.id, 
    pb.batch_number, 
    pb.product_name, 
    pb.production_datetime,
    pb.created_by, 
    (SELECT SUM(original_kg) FROM batch_materials WHERE batch_id = pb.id) as total_input_kg,
    (SELECT SUM(kg_extracted) FROM material_extractions WHERE batch_id = pb.id) as total_extracted_kg,
    SUM(py.total_packs_produced) as total_packs,
    ( (SUM(py.actual_grams * py.total_packs_produced) / 1000) / 
      NULLIF((SELECT SUM(kg_extracted) FROM material_extractions WHERE batch_id = pb.id), 0) * 100 
    ) as efficiency_rate
    FROM production_batches pb
    LEFT JOIN production_yields py ON pb.id = py.batch_id
    GROUP BY pb.id
    ORDER BY pb.production_datetime DESC";

$results = $db->query($query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Batch History Admin | ALDiFOODS</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 700; }
        .badge-green { background: rgba(46, 204, 113, 0.2); color: #2ecc71; }
        .badge-amber { background: rgba(241, 196, 15, 0.2); color: #f1c40f; }
        .badge-red { background: rgba(231, 76, 60, 0.2); color: #e74c3c; }
        .text-sm { font-size: 0.75rem; }
        .stat-label { color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px; }
        
        /* Modern Action Button Layout styling */
        .btn-print {
            background: #2ecc71;
            color: #fff;
            border: none;
            padding: 10px 16px;
            font-size: 0.85rem;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background 0.2s ease;
        }
        .btn-print:hover { background: #27ae60; }

        /* CSS PRINT ENGINE RULES: Strips interface layouts, margins, sidebars when saving soft copy */
        @media print {
            body { background: #ffffff !important; color: #000000 !important; font-size: 12px; }
            .layout { display: block !important; }
            .sidebar, #_sidebar, .page-header button, .btn-print { display: none !important; }
            .main-content { padding: 0 !important; margin: 0 !important; width: 100% !important; }
            .table-card { border: none !important; box-shadow: none !important; background: transparent !important; }
            .data-table th { background: #f5f5f5 !important; color: #000 !important; border-bottom: 2px solid #000 !important; }
            .data-table td { border-bottom: 1px solid #ddd !important; padding: 10px 5px !important; }
            .badge { border: 1px solid #777 !important; background: transparent !important; color: #000 !important; }
            @page { margin: 1.5cm; }
        }
    </style>
</head>
<body>
<div class="layout">
<?php include '_sidebar.php'; ?>

<div class="main-content">
    <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <div>
            <h1>Production Audit</h1>
            <div class="breadcrumb">Real-time batch efficiency and extraction tracking</div>
        </div>
        <!-- Trigger Button -->
        <button onclick="printDailyReport()" class="btn-print">
            💾 Save Daily Soft-Copy / Print
        </button>
    </div>

    <div class="table-card" id="printable-table-area">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Batch Details</th>
                    <th>Material Input</th>
                    <th>Machine Extraction</th>
                    <th>Packing Yield</th>
                    <th>Packing Efficiency</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($results->num_rows === 0): ?>
                <tr><td colspan="5" style="text-align:center; padding:50px;">No records found.</td></tr>
            <?php else: ?>
            <?php while($row = $results->fetch_assoc()): 
                $input = $row['total_input_kg'] ?? 0;
                $extracted = $row['total_extracted_kg'] ?? 0;
                $loss = ($input > 0) ? (($input - $extracted) / $input) * 100 : 0;
                $efficiency = $row['efficiency_rate'] ?? 0;
            ?>
                <!-- Individual logs tagged dynamically by operational date -->
                <tr class="log-row" data-date="<?= date('Y-m-d', strtotime($row['production_datetime'])) ?>">
                    <td>
                        <div style="color:var(--primary-green); font-weight:800;">#<?= $row['batch_number'] ?></div>
                        <strong><?= htmlspecialchars($row['product_name']) ?></strong>
                        <div class="text-sm text-muted"><?= date('M d, Y', strtotime($row['production_datetime'])) ?></div>
                    </td>
                    
                    <td>
                        <div class="stat-label">Input</div>
                        <strong><?= number_format($input, 2) ?> kg</strong>
                    </td>

                    <td>
                        <div class="stat-label">Extracted</div>
                        <strong><?= number_format($extracted, 2) ?> kg</strong>
                        <div class="text-sm" style="color:#e74c3c;">Loss: <?= number_format($loss, 1) ?>%</div>
                    </td>

                    <td>
                        <?php if($row['total_packs']): ?>
                            <div class="stat-label">Total Units</div>
                            <strong><?= number_format($row['total_packs']) ?> Packs</strong>
                        <?php else: ?>
                            <span class="text-muted italic">No packs logged</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if($efficiency > 0): ?>
                            <div class="badge <?= $efficiency > 98 ? 'badge-green' : ($efficiency > 90 ? 'badge-amber' : 'badge-red') ?>">
                                <?= number_format($efficiency, 1) ?>%
                            </div>
                            <div class="text-sm text-muted" style="margin-top:4px;">Yield vs Extraction</div>
                        <?php else: ?>
                            <span class="text-muted">--</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<script>
function printDailyReport() {
    // Get today's local date format matching data-date attribute (YYYY-MM-DD)
    const today = new Date().toISOString().split('T')[0];
    const logRows = document.querySelectorAll('.log-row');
    let matchingRecords = 0;

    // Filter to show only today's data rows on the printable output engine
    logRows.forEach(row => {
        if (row.getAttribute('data-date') === today) {
            row.style.display = '';
            matchingRecords++;
        } else {
            row.style.display = 'none'; // Temporarily hides older records from appearing on PDF output
        }
    });

    if (matchingRecords === 0) {
        alert("No batch operations have been recorded yet today (" + today + "). Showing clean canvas preview.");
    }

    // Triggers local web rendering system save dialog ("Save as PDF" format option)
    window.print();

    // Revert styling rules visibility on current live viewport window smoothly right after execution closure
    logRows.forEach(row => row.style.display = '');
}
</script>
</body>
</html>
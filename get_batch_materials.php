<?php

require_once '../includes/db.php';

$db = getDB();

// ---------------------------------------------------------
// GET BATCH MATERIALS
// ---------------------------------------------------------

header('Content-Type: application/json');

$batchId = 0;

// If a batch_id was supplied, use it.
if (isset($_GET['batch_id']) && is_numeric($_GET['batch_id'])) {
    $batchId = intval($_GET['batch_id']);
}

// ---------------------------------------------------------
// IF NO BATCH ID WAS PROVIDED,
// AUTOMATICALLY GET TODAY'S BATCH
// ---------------------------------------------------------

if ($batchId <= 0) {

    $stmt = $db->prepare("
        SELECT id
        FROM production_batches
        WHERE production_datetime >= CURDATE()
          AND production_datetime < CURDATE() + INTERVAL 1 DAY
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $batchId = (int) $row['id'];
    }

    $stmt->close();
}

// ---------------------------------------------------------
// NO BATCH FOUND FOR TODAY
// ---------------------------------------------------------

if ($batchId <= 0) {

    echo json_encode([
        'success' => false,
        'message' => 'No production batch exists for today.',
        'materials' => []
    ]);

    exit;
}

// ---------------------------------------------------------
// GET MATERIALS FOR THE SELECTED BATCH
// ---------------------------------------------------------

$stmt = $db->prepare("
    SELECT
        id,
        batch_id,
        material_name,
        original_kg
    FROM batch_materials
    WHERE batch_id = ?
    ORDER BY id ASC
");

$stmt->bind_param(
    "i",
    $batchId
);

$stmt->execute();

$result = $stmt->get_result();

$materials = [];

while ($row = $result->fetch_assoc()) {

    $materials[] = [
        'id' => (int) $row['id'],
        'batch_id' => (int) $row['batch_id'],
        'material_name' => $row['material_name'],
        'original_kg' => (float) $row['original_kg']
    ];
}

$stmt->close();

// ---------------------------------------------------------
// RETURN JSON
// ---------------------------------------------------------

echo json_encode([
    'success' => true,
    'batch_id' => $batchId,
    'materials' => $materials
]);

exit;
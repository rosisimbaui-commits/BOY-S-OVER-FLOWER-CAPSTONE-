<?php
require_once '../includes/db.php';
requireAdminLogin();

$db = getDB();
$currentPage = 'analytics.php';

// ---------------------------------------------------------
// 1. CREATE OR UPDATE TODAY'S DAILY SNAPSHOT
// ---------------------------------------------------------

$todayQuery = $db->query("
    SELECT
        COALESCE(SUM((
            SELECT COALESCE(SUM(bm.original_kg), 0)
            FROM batch_materials bm
            WHERE bm.batch_id = pb.id
        )), 0) AS day_in,

        COALESCE(SUM((
            SELECT COALESCE(SUM(me.kg_extracted), 0)
            FROM material_extractions me
            WHERE me.batch_id = pb.id
        )), 0) AS day_out,

        COALESCE((
            SELECT SUM(py.total_packs_produced)
            FROM production_yields py
            WHERE py.created_at >= CURDATE()
              AND py.created_at < CURDATE() + INTERVAL 1 DAY
        ), 0) AS day_packs

    FROM production_batches pb
    WHERE pb.production_datetime >= CURDATE()
      AND pb.production_datetime < CURDATE() + INTERVAL 1 DAY
");

$todayData = $todayQuery->fetch_assoc();

$snapshotIn    = (float) ($todayData['day_in'] ?? 0);
$snapshotOut   = (float) ($todayData['day_out'] ?? 0);
$snapshotPacks = (int) ($todayData['day_packs'] ?? 0);
$snapshotLoss  = $snapshotIn - $snapshotOut;
$snapshotEff   = $snapshotIn > 0
    ? ($snapshotOut / $snapshotIn) * 100
    : 0;

// Prevent tiny floating-point negatives.
if (abs($snapshotLoss) < 0.00001) {
    $snapshotLoss = 0;
}

$snapshotStatement = $db->prepare("
    INSERT INTO daily_analytics_snapshots (
        snapshot_date,
        total_input_kg,
        total_output_kg,
        total_packs_produced,
        total_loss_kg,
        efficiency_percentage
    )
    VALUES (CURDATE(), ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        total_input_kg = VALUES(total_input_kg),
        total_output_kg = VALUES(total_output_kg),
        total_packs_produced = VALUES(total_packs_produced),
        total_loss_kg = VALUES(total_loss_kg),
        efficiency_percentage = VALUES(efficiency_percentage)
");

$snapshotStatement->bind_param(
    "ddidd",
    $snapshotIn,
    $snapshotOut,
    $snapshotPacks,
    $snapshotLoss,
    $snapshotEff
);

$snapshotStatement->execute();
$snapshotStatement->close();

// ---------------------------------------------------------
// 2. PERIOD SELECTION
// ---------------------------------------------------------

$allowedPeriods = ['daily', 'weekly', 'monthly', 'yearly'];

$period = strtolower($_GET['period'] ?? 'daily');

if (!in_array($period, $allowedPeriods, true)) {
    $period = 'daily';
}

$periodSettings = [
    'daily' => [
        'title'        => 'Daily',
        'date_from'    => date('Y-m-d', strtotime('-29 days')),
        'date_to'      => date('Y-m-d'),
        'group'        => "DATE(snapshot_date)",
        'label'        => "DATE_FORMAT(snapshot_date, '%b %d')",
        'period_start' => "DATE(snapshot_date)",
        'period_end'   => "DATE(snapshot_date)",
        'order'        => "DATE(snapshot_date)"
    ],

    'weekly' => [
        'title'        => 'Weekly',
        'date_from'    => date('Y-m-d', strtotime('monday this week -11 weeks')),
        'date_to'      => date('Y-m-d'),
        'group'        => "YEARWEEK(snapshot_date, 3)",
        'label'        => "CONCAT(
                            'W',
                            LPAD(WEEK(snapshot_date, 3), 2, '0'),
                            ' ',
                            YEAR(
                                DATE_ADD(
                                    snapshot_date,
                                    INTERVAL 4 - DAYOFWEEK(snapshot_date) DAY
                                )
                            )
                        )",
        'period_start' => "DATE_SUB(
                            DATE(snapshot_date),
                            INTERVAL WEEKDAY(snapshot_date) DAY
                        )",
        'period_end'   => "DATE_ADD(
                            DATE_SUB(
                                DATE(snapshot_date),
                                INTERVAL WEEKDAY(snapshot_date) DAY
                            ),
                            INTERVAL 6 DAY
                        )",
        'order'        => "YEARWEEK(snapshot_date, 3)"
    ],

    'monthly' => [
        'title'        => 'Monthly',
        'date_from'    => date('Y-m-01', strtotime('-11 months')),
        'date_to'      => date('Y-m-d'),
        'group'        => "DATE_FORMAT(snapshot_date, '%Y-%m')",
        'label'        => "DATE_FORMAT(snapshot_date, '%b %Y')",
        'period_start' => "DATE_FORMAT(snapshot_date, '%Y-%m-01')",
        'period_end'   => "LAST_DAY(snapshot_date)",
        'order'        => "DATE_FORMAT(snapshot_date, '%Y-%m')"
    ],

    'yearly' => [
        'title'        => 'Yearly',
        'date_from'    => date('Y-01-01', strtotime('-4 years')),
        'date_to'      => date('Y-m-d'),
        'group'        => "YEAR(snapshot_date)",
        'label'        => "DATE_FORMAT(snapshot_date, '%Y')",
        'period_start' => "DATE_FORMAT(snapshot_date, '%Y-01-01')",
        'period_end'   => "DATE_FORMAT(snapshot_date, '%Y-12-31')",
        'order'        => "YEAR(snapshot_date)"
    ]
];

$config = $periodSettings[$period];

$dateFrom = $config['date_from'];
$dateTo   = $config['date_to'];

// ---------------------------------------------------------
// 3. AGGREGATED PERIOD RECORDS
// ---------------------------------------------------------

$recordsSql = "
    SELECT
        {$config['label']} AS period_label,

        MIN({$config['period_start']}) AS period_start,

        MAX({$config['period_end']}) AS period_end,

        COALESCE(SUM(total_input_kg), 0) AS total_input_kg,

        COALESCE(SUM(total_output_kg), 0) AS total_output_kg,

        COALESCE(SUM(total_packs_produced), 0) AS total_packs_produced,

        COALESCE(SUM(total_loss_kg), 0) AS total_loss_kg,

        CASE
            WHEN SUM(total_input_kg) > 0
            THEN
                (SUM(total_output_kg) / SUM(total_input_kg)) * 100
            ELSE 0
        END AS efficiency_percentage

    FROM daily_analytics_snapshots

    WHERE snapshot_date BETWEEN ? AND ?

    GROUP BY {$config['group']}

    ORDER BY {$config['order']} ASC
";

$recordsStatement = $db->prepare($recordsSql);

$recordsStatement->bind_param(
    "ss",
    $dateFrom,
    $dateTo
);

$recordsStatement->execute();

$recordsResult = $recordsStatement->get_result();

$records = [];

while ($row = $recordsResult->fetch_assoc()) {
    $records[] = $row;
}

$recordsStatement->close();

// ---------------------------------------------------------
// 4. SUMMARY FOR SELECTED RANGE
// ---------------------------------------------------------

$summaryStatement = $db->prepare("
    SELECT
        COALESCE(SUM(total_input_kg), 0) AS total_input_kg,

        COALESCE(SUM(total_output_kg), 0) AS total_output_kg,

        COALESCE(SUM(total_packs_produced), 0) AS total_packs_produced,

        COALESCE(SUM(total_loss_kg), 0) AS total_loss_kg,

        CASE
            WHEN SUM(total_input_kg) > 0
            THEN
                (SUM(total_output_kg) / SUM(total_input_kg)) * 100
            ELSE 0
        END AS efficiency_percentage

    FROM daily_analytics_snapshots

    WHERE snapshot_date BETWEEN ? AND ?
");

$summaryStatement->bind_param(
    "ss",
    $dateFrom,
    $dateTo
);

$summaryStatement->execute();

$summary = $summaryStatement
    ->get_result()
    ->fetch_assoc();

$summaryStatement->close();

$totalInput = (float) (
    $summary['total_input_kg'] ?? 0
);

$totalOutput = (float) (
    $summary['total_output_kg'] ?? 0
);

$totalPacks = (int) (
    $summary['total_packs_produced'] ?? 0
);

$totalLoss = (float) (
    $summary['total_loss_kg'] ?? 0
);

$totalEfficiency = (float) (
    $summary['efficiency_percentage'] ?? 0
);

// ---------------------------------------------------------
// 5. FORMAT PERIOD RANGE
// ---------------------------------------------------------

function formatPeriodRange(
    string $period,
    array $record
): string {

    $start = strtotime(
        $record['period_start']
    );

    $end = strtotime(
        $record['period_end']
    );

    switch ($period) {

        case 'daily':

            return date(
                'D, M d, Y',
                $start
            );

        case 'weekly':

            return date(
                'M d',
                $start
            ) . ' - ' . date(
                'M d, Y',
                $end
            );

        case 'monthly':

            return date(
                'F Y',
                $start
            );

        case 'yearly':

            return date(
                'Y',
                $start
            );

        default:

            return date(
                'M d, Y',
                $start
            );
    }
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?= htmlspecialchars($config['title']) ?>
        Production Metrics - ALDiFOODS
    </title>

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

    <style>

        .analytics-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .period-tabs {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .period-tab {
            display: inline-block;
            padding: 0.7rem 1rem;
            border: 1px solid #dfe5ec;
            border-radius: 8px;
            background: #ffffff;
            color: #4d5968;
            font-weight: 600;
            text-decoration: none;
            transition: 0.2s ease;
        }

        .period-tab:hover {
            border-color: #3498db;
            color: #3498db;
        }

        .period-tab.active {
            border-color: #3498db;
            background: #3498db;
            color: #ffffff;
        }

        .range-text {
            color: #7a7a8a;
            font-size: 0.9rem;
        }

        .empty-state {
            padding: 2rem;
            text-align: center;
            color: #7a7a8a;
        }

        @media (max-width: 768px) {

            .analytics-toolbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .period-tab {
                padding: 0.6rem 0.8rem;
            }

        }

    </style>

</head>

<body>

<div class="layout">

    <?php include '_sidebar.php'; ?>

    <main class="main-content">

        <!-- PAGE HEADER -->

        <div class="page-header">

            <h1>
                <?= htmlspecialchars($config['title']) ?>
                Production Records
            </h1>

            <div class="breadcrumb">
                Tracking raw material loss, production volume and efficiency
            </div>

        </div>


        <!-- PERIOD TOOLBAR -->

        <div class="analytics-toolbar">

            <nav
                class="period-tabs"
                aria-label="Analytics period"
            >

                <?php foreach (
                    $allowedPeriods
                    as $periodOption
                ): ?>

                    <a
                        href="?period=<?= urlencode($periodOption) ?>"
                        class="period-tab <?= $period === $periodOption ? 'active' : '' ?>"
                    >
                        <?= htmlspecialchars(
                            ucfirst($periodOption)
                        ) ?>
                    </a>

                <?php endforeach; ?>

            </nav>


            <div class="range-text">

                <?= date(
                    'M d, Y',
                    strtotime($dateFrom)
                ) ?>

                -

                <?= date(
                    'M d, Y',
                    strtotime($dateTo)
                ) ?>

            </div>

        </div>


        <!-- SUMMARY CARDS -->

        <div class="stats-grid">

            <!-- PACKS -->

            <div class="stat-card blue">

                <div class="stat-label">

                    <?= htmlspecialchars(
                        $config['title']
                    ) ?>

                    Packs Produced

                </div>


                <div class="stat-value">

                    <?= number_format(
                        $totalPacks
                    ) ?>

                </div>


                <div
                    style="
                        font-size: 0.8rem;
                        opacity: 0.8;
                    "
                >

                    Total units in selected range

                </div>

            </div>


            <!-- MATERIAL LOSS -->

            <div class="stat-card red">

                <div class="stat-label">

                    <?= htmlspecialchars(
                        $config['title']
                    ) ?>

                    Material Loss

                </div>


                <div class="stat-value">

                    <?= number_format(
                        $totalLoss,
                        2
                    ) ?>

                    kg

                </div>


                <div
                    style="
                        font-size: 0.8rem;
                        opacity: 0.8;
                    "
                >

                    Unrecovered raw material

                </div>

            </div>


            <!-- EFFICIENCY -->

            <div class="stat-card amber">

                <div class="stat-label">

                    <?= htmlspecialchars(
                        $config['title']
                    ) ?>

                    Efficiency

                </div>


                <div class="stat-value">

                    <?= number_format(
                        $totalEfficiency,
                        1
                    ) ?>%

                </div>


                <div
                    style="
                        font-size: 0.8rem;
                        opacity: 0.8;
                    "
                >

                    Total extracted versus total input

                </div>

            </div>

        </div>


        <!-- PRODUCTION HISTORY -->

        <div class="table-card mt-3">

            <h3>

                <?= htmlspecialchars(
                    $config['title']
                ) ?>

                Production History

            </h3>


            <?php if (empty($records)): ?>

                <div class="empty-state">

                    No analytics records were found
                    for this period.

                </div>

            <?php else: ?>

                <div
                    style="
                        overflow-x: auto;
                    "
                >

                    <table class="data-table">

                        <thead>

                            <tr>

                                <th>
                                    Period
                                </th>

                                <th>
                                    Total Packs Made
                                </th>

                                <th>
                                    Material Input
                                </th>

                                <th>
                                    Material Extracted
                                </th>

                                <th>
                                    Material Lost
                                </th>

                                <th>
                                    Status
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php foreach (
                                array_reverse($records)
                                as $record
                            ): ?>

                                <?php

                                $efficiency =
                                    (float)
                                    $record[
                                        'efficiency_percentage'
                                    ];

                                if (
                                    $efficiency >= 90
                                ) {

                                    $badgeClass =
                                        'badge-green';

                                } else {

                                    $badgeClass =
                                        'badge-amber';

                                }

                                ?>


                                <tr>

                                    <!-- PERIOD -->

                                    <td>

                                        <strong>

                                            <?= htmlspecialchars(
                                                formatPeriodRange(
                                                    $period,
                                                    $record
                                                )
                                            ) ?>

                                        </strong>

                                    </td>


                                    <!-- PACKS -->

                                    <td
                                        style="
                                            color:
                                                var(--primary-blue);
                                            font-weight: bold;
                                        "
                                    >

                                        <?= number_format(
                                            (int)
                                            $record[
                                                'total_packs_produced'
                                            ]
                                        ) ?>

                                        packs

                                    </td>


                                    <!-- INPUT -->

                                    <td>

                                        <?= number_format(
                                            (float)
                                            $record[
                                                'total_input_kg'
                                            ],
                                            2
                                        ) ?>

                                        kg

                                    </td>


                                    <!-- EXTRACTED -->

                                    <td>

                                        <?= number_format(
                                    
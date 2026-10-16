<?php

require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/config/database.php';

require_role('institution');

$email = $_SESSION['email'] ?? '';

/*
|--------------------------------------------------------------------------
| Find the institution connected to the logged-in account
|--------------------------------------------------------------------------
| The current MVP links the institution login to institutions.contact_email.
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "SELECT id, name
     FROM institutions
     WHERE contact_email = ?
       AND status = 'active'
     LIMIT 1"
);

$stmt->bind_param("s", $email);
$stmt->execute();

$institution = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$institution) {
    http_response_code(404);
    exit('Institution profile not found.');
}

$institutionId = (int) $institution['id'];
$institutionName = $institution['name'];


/*
|--------------------------------------------------------------------------
| Institution-wide metrics
|--------------------------------------------------------------------------
*/

/* Total active learners */
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total_learners
     FROM students
     WHERE institution_id = ?
       AND status = 'active'"
);

$stmt->bind_param("i", $institutionId);
$stmt->execute();

$totalLearners = (int) ($stmt->get_result()->fetch_assoc()['total_learners'] ?? 0);
$stmt->close();


/* Active learners = students who have submitted at least one activity */
$stmt = $conn->prepare(
    "SELECT COUNT(DISTINCT s.id) AS active_learners
     FROM students s
     INNER JOIN submissions sub
         ON sub.student_id = s.id
     INNER JOIN activities a
         ON a.id = sub.activity_id
        AND a.status = 'active'
     INNER JOIN cohorts c
         ON c.id = s.cohort_id
        AND c.institution_id = ?
     WHERE s.institution_id = ?
       AND s.status = 'active'"
);

$stmt->bind_param("ii", $institutionId, $institutionId);
$stmt->execute();

$activeLearners = (int) ($stmt->get_result()->fetch_assoc()['active_learners'] ?? 0);
$stmt->close();


/*
|--------------------------------------------------------------------------
| Average progress
|--------------------------------------------------------------------------
| Progress = submissions / active activities assigned to the student's cohort.
| Each student's progress is capped at 100%.
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "SELECT
        s.id,
        COUNT(DISTINCT CASE WHEN a.status = 'active' THEN a.id END) AS total_activities,
        COUNT(DISTINCT CASE WHEN a.status = 'active' AND sub.id IS NOT NULL THEN a.id END) AS completed_activities
     FROM students s
     INNER JOIN cohorts c
         ON c.id = s.cohort_id
        AND c.institution_id = ?
     LEFT JOIN activities a
         ON a.cohort_id = s.cohort_id
        AND a.status = 'active'
     LEFT JOIN submissions sub
         ON sub.student_id = s.id
        AND sub.activity_id = a.id
     WHERE s.institution_id = ?
       AND s.status = 'active'
     GROUP BY s.id"
);

$stmt->bind_param("ii", $institutionId, $institutionId);
$stmt->execute();

$result = $stmt->get_result();

$progressValues = [];

while ($row = $result->fetch_assoc()) {

    $totalActivities = (int) $row['total_activities'];
    $completedActivities = (int) $row['completed_activities'];

    if ($totalActivities > 0) {
        $progress = min(
            100,
            ($completedActivities / $totalActivities) * 100
        );

        $progressValues[] = $progress;
    }
}

$stmt->close();

if (count($progressValues) > 0) {
    $averageProgress = round(
        array_sum($progressValues) / count($progressValues)
    );
} else {
    $averageProgress = 0;
}


/* Participation = active learners / total active learners */
$participation = $totalLearners > 0
    ? round(($activeLearners / $totalLearners) * 100)
    : 0;


/*
|--------------------------------------------------------------------------
| Learner progress rows
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "SELECT
        s.id,
        u.name,
        c.name AS cohort_name,

        COUNT(DISTINCT CASE
            WHEN a.status = 'active'
            THEN a.id
        END) AS total_activities,

        COUNT(DISTINCT CASE
            WHEN a.status = 'active'
             AND sub.id IS NOT NULL
            THEN a.id
        END) AS completed_activities,

        COUNT(DISTINCT CASE
            WHEN sub.status = 'reviewed'
            THEN sub.id
        END) AS reviewed_submissions

     FROM students s

     INNER JOIN users u
         ON u.id = s.user_id

     INNER JOIN cohorts c
         ON c.id = s.cohort_id
        AND c.institution_id = ?

     LEFT JOIN activities a
         ON a.cohort_id = s.cohort_id
        AND a.status = 'active'

     LEFT JOIN submissions sub
         ON sub.student_id = s.id
        AND sub.activity_id = a.id

     WHERE s.institution_id = ?
       AND s.status = 'active'

     GROUP BY
        s.id,
        u.name,
        c.name

     ORDER BY
        u.name ASC"
);

$stmt->bind_param("ii", $institutionId, $institutionId);
$stmt->execute();

$learnerResult = $stmt->get_result();

$learners = [];

while ($row = $learnerResult->fetch_assoc()) {

    $totalActivities = (int) $row['total_activities'];
    $completedActivities = (int) $row['completed_activities'];
    $reviewedSubmissions = (int) $row['reviewed_submissions'];

    $progress = $totalActivities > 0
        ? round(min(100, ($completedActivities / $totalActivities) * 100))
        : 0;

    /*
     * Status is based on actual platform activity.
     */
    if ($progress >= 75) {
        $status = 'On track';
        $statusClass = 'on-track';
    } elseif ($progress > 0) {
        $status = 'Needs attention';
        $statusClass = 'review';
    } else {
        $status = 'Not started';
        $statusClass = 'review';
    }

    $learners[] = [
        'name' => $row['name'],
        'cohort_name' => $row['cohort_name'],
        'progress' => $progress,
        'reviewed' => $reviewedSubmissions,
        'status' => $status,
        'status_class' => $statusClass
    ];
}

$stmt->close();

$needsAttention = 0;

foreach ($learners as $learner) {
    if ($learner['status'] !== 'On track') {
        $needsAttention++;
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

    <title>Participation & Progress — AIraut</title>

    <style>

        :root {
            --navy: #123454;
            --teal: #079bb0;
            --gold: #d99a18;
            --text: #173b5d;
            --muted: #55789a;
            --line: #d9dedf;
            --paper: #fbfaf6;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: var(--paper);
            color: var(--navy);
            line-height: 1.45;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .page {
            width: min(1400px, calc(100% - 96px));
            margin: 0 auto;
        }

        /* HEADER */

        header {
            height: 150px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .logo {
            width: 135px;
            height: auto;
            display: block;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 34px;
            font-weight: 700;
            font-size: 18px;
        }

        .role {
            color: var(--teal);
            letter-spacing: 6px;
            font-size: 16px;
        }

        .back {
            color: var(--navy);
        }

        /* HERO */

        .hero {
            padding: 72px 0 105px;
            border-bottom: 1px solid var(--line);
        }

        .eyebrow {
            color: var(--teal);
            letter-spacing: 7px;
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 30px;
        }

        h1 {
            max-width: 1000px;
            font-size: clamp(64px, 7vw, 112px);
            line-height: 0.96;
            letter-spacing: -5px;
            font-weight: 800;
            margin-bottom: 34px;
        }

        h1 span {
            color: var(--teal);
        }

        .hero-text {
            max-width: 780px;
            font-size: 22px;
            line-height: 1.55;
            color: var(--muted);
        }

        /* OVERVIEW */

        .overview {
            padding: 100px 0;
            border-bottom: 1px solid var(--line);
        }

        .section-label {
            color: var(--teal);
            letter-spacing: 7px;
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 24px;
        }

        .overview-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 80px;
            align-items: end;
        }

        .overview-title {
            font-size: 54px;
            line-height: 1.05;
            letter-spacing: -2px;
            max-width: 600px;
        }

        .overview-copy {
            font-size: 20px;
            color: var(--muted);
            max-width: 540px;
            line-height: 1.55;
        }

        /* METRICS */

        .metrics {
            margin-top: 72px;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            border-top: 1px solid var(--line);
            border-bottom: 1px solid var(--line);
        }

        .metric {
            padding: 38px 30px 42px;
            border-right: 1px solid var(--line);
        }

        .metric:last-child {
            border-right: none;
        }

        .metric-number {
            font-size: 52px;
            line-height: 1;
            font-weight: 800;
            margin-bottom: 12px;
        }

        .metric-label {
            color: var(--muted);
            font-size: 17px;
        }

        .metric-highlight {
            color: var(--teal);
        }

        .metric-attention {
            color: var(--gold);
        }

        /* PROGRESS */

        .progress-section {
            padding: 110px 0;
            border-bottom: 1px solid var(--line);
        }

        .progress-heading {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 80px;
            margin-bottom: 65px;
        }

        .progress-heading h2 {
            font-size: 58px;
            line-height: 1;
            letter-spacing: -3px;
        }

        .progress-heading p {
            max-width: 500px;
            font-size: 20px;
            color: var(--muted);
            line-height: 1.55;
            align-self: end;
        }

        /* LEARNER ROWS */

        .learner-list {
            border-top: 1px solid var(--line);
        }

        .learner {
            display: grid;
            grid-template-columns: 70px 1.5fr 0.8fr 1fr;
            align-items: center;
            min-height: 135px;
            border-bottom: 1px solid var(--line);
            gap: 25px;
        }

        .number {
            color: var(--teal);
            font-weight: 700;
            letter-spacing: 3px;
        }

        .learner-name {
            font-size: 25px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .learner-sub {
            color: var(--muted);
            font-size: 16px;
        }

        .progress-value {
            font-size: 27px;
            font-weight: 800;
        }

        .progress-word {
            color: var(--muted);
            font-size: 15px;
            margin-top: 3px;
        }

        .status {
            font-weight: 700;
            font-size: 16px;
        }

        .status.on-track {
            color: var(--teal);
        }

        .status.review {
            color: var(--gold);
        }

        /* INSIGHT */

        .insight {
            padding: 115px 0;
            border-bottom: 1px solid var(--line);
        }

        .insight-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 100px;
        }

        .insight h2 {
            font-size: 60px;
            line-height: 1;
            letter-spacing: -3px;
            max-width: 600px;
        }

        .insight h2 span {
            color: var(--teal);
        }

        .insight-list {
            border-top: 1px solid var(--line);
        }

        .insight-item {
            display: grid;
            grid-template-columns: 60px 1fr;
            gap: 25px;
            padding: 28px 0;
            border-bottom: 1px solid var(--line);
        }

        .insight-number {
            color: var(--teal);
            font-weight: 700;
            letter-spacing: 3px;
        }

        .insight-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .insight-text {
            color: var(--muted);
            font-size: 17px;
        }

        /* NEXT */

        .next {
            padding: 120px 0 135px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 60px;
        }

        .next-label {
            color: var(--teal);
            letter-spacing: 7px;
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 28px;
        }

        .next h2 {
            font-size: clamp(48px, 5vw, 78px);
            line-height: 1;
            letter-spacing: -3px;
            max-width: 800px;
        }

        .next p {
            color: var(--muted);
            font-size: 20px;
            margin-top: 22px;
        }

        .next-button {
            width: 76px;
            height: 76px;
            border-radius: 50%;
            background: var(--navy);
            color: white;
            border: 2px solid var(--gold);
            display: grid;
            place-items: center;
            font-size: 30px;
            flex-shrink: 0;
            transition:
                transform 0.2s ease,
                background 0.2s ease;
        }

        .next-button:hover {
            transform: translateX(5px);
            background: var(--teal);
        }

        /* FOOTER */

        footer {
            border-top: 1px solid var(--line);
            padding: 35px 0;
            display: flex;
            justify-content: space-between;
            color: var(--muted);
            font-size: 15px;
        }

        footer strong {
            color: var(--navy);
        }

        /* RESPONSIVE */

        @media (max-width: 900px) {

            .page {
                width: min(100% - 40px, 700px);
            }

            header {
                height: 110px;
            }

            .header-right {
                gap: 15px;
                font-size: 14px;
            }

            .role {
                display: none;
            }

            h1 {
                font-size: 62px;
                letter-spacing: -3px;
            }

            .overview-grid,
            .progress-heading,
            .insight-grid {
                grid-template-columns: 1fr;
                gap: 40px;
            }

            .metrics {
                grid-template-columns: 1fr 1fr;
            }

            .metric:nth-child(2) {
                border-right: none;
            }

            .learner {
                grid-template-columns: 45px 1fr 100px;
                padding: 28px 0;
            }

            .next {
                align-items: flex-start;
            }

        }

        @media (max-width: 600px) {

            .metrics {
                grid-template-columns: 1fr;
            }

            .metric {
                border-right: none;
                border-bottom: 1px solid var(--line);
            }

            .metric:last-child {
                border-bottom: none;
            }

            .learner {
                grid-template-columns: 35px 1fr;
                gap: 12px;
            }

            .progress-value,
            .status {
                grid-column: 2;
            }

            .next {
                flex-direction: column;
            }

            footer {
                flex-direction: column;
                gap: 15px;
            }

        }

    </style>

</head>

<body>

<div class="page">


    <!-- HEADER -->

    <header>

        <a href="institution-dashboard.php">

            <img
                src="assets/airaut-logo.png"
                alt="AIraut"
                class="logo"
            >

        </a>

        <div class="header-right">

            <span class="role">
                INSTITUTION
            </span>

            <a
                href="institution-dashboard.php"
                class="back"
            >
                ← Institution Dashboard
            </a>

        </div>

    </header>


    <!-- HERO -->

    <section class="hero">

        <div class="eyebrow">
            PARTICIPATION &amp; PROGRESS
        </div>

        <h1>
            See how learners are
            <span>moving forward.</span>
        </h1>

        <p class="hero-text">
            Understand participation, progress and completion across your
            learners' future readiness journey.
        </p>

    </section>


    <!-- OVERVIEW -->

    <section class="overview">

        <div class="section-label">
            COHORT OVERVIEW
        </div>

        <div class="overview-grid">

            <h2 class="overview-title">
                A clearer view of the journey.
            </h2>

            <p class="overview-copy">
                Follow how learners are engaging with AIraut, how cohorts
                are progressing and where learner support may be needed.
            </p>

        </div>


        <div class="metrics">

            <div class="metric">

                <div class="metric-number">
                    <?= $totalLearners ?>
                </div>

                <div class="metric-label">
                    Learners
                </div>

            </div>


            <div class="metric">

                <div class="metric-number metric-highlight">
                    <?= $activeLearners ?>
                </div>

                <div class="metric-label">
                    Active Learners
                </div>

            </div>


            <div class="metric">

                <div class="metric-number metric-highlight">
                    <?= $averageProgress ?>%
                </div>

                <div class="metric-label">
                    Average Progress
                </div>

            </div>


            <div class="metric">

                <div class="metric-number metric-attention">
                    <?= $participation ?>%
                </div>

                <div class="metric-label">
                    Participation
                </div>

            </div>

        </div>

    </section>


    <!-- LEARNER PROGRESS -->

    <section class="progress-section">

        <div class="section-label">
            LEARNER PROGRESS
        </div>


        <div class="progress-heading">

            <h2>
                Where your learners are now.
            </h2>

            <p>
                A simple view of learner progress through the programme,
                helping your institution understand participation,
                movement and where timely support may be useful.
            </p>

        </div>


        <div class="learner-list">

            <?php if (count($learners) === 0): ?>

                <div class="learner">

                    <div class="number">
                        —
                    </div>

                    <div>

                        <div class="learner-name">
                            No active learners yet.
                        </div>

                        <div class="learner-sub">
                            Active student records will appear here as they
                            are enrolled into your institution's cohorts.
                        </div>

                    </div>

                </div>

            <?php else: ?>

                <?php foreach ($learners as $index => $learner): ?>

                    <div class="learner">

                        <div class="number">
                            <?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?>
                        </div>


                        <div>

                            <div class="learner-name">
                                <?= htmlspecialchars($learner['name']) ?>
                            </div>

                            <div class="learner-sub">
                                <?= htmlspecialchars($learner['cohort_name']) ?>
                            </div>

                        </div>


                        <div>

                            <div class="progress-value">
                                <?= $learner['progress'] ?>%
                            </div>

                            <div class="progress-word">
                                Progress
                            </div>

                        </div>


                        <div class="status <?= htmlspecialchars($learner['status_class']) ?>">
                            <?= htmlspecialchars($learner['status']) ?>
                        </div>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </section>


    <!-- INSIGHT -->

    <section class="insight">

        <div class="insight-grid">

            <div>

                <div class="section-label">
                    INSTITUTION VIEW
                </div>

                <h2>
                    Progress becomes useful when it helps you
                    <span>act.</span>
                </h2>

            </div>


            <div class="insight-list">

                <div class="insight-item">

                    <div class="insight-number">
                        01
                    </div>

                    <div>

                        <div class="insight-title">
                            Participation
                        </div>

                        <div class="insight-text">
                            See how many learners are actively engaging
                            with AIraut activities.
                        </div>

                    </div>

                </div>


                <div class="insight-item">

                    <div class="insight-number">
                        02
                    </div>

                    <div>

                        <div class="insight-title">
                            Progress
                        </div>

                        <div class="insight-text">
                            Understand how learners are moving through
                            their assigned journey.
                        </div>

                    </div>

                </div>


                <div class="insight-item">

                    <div class="insight-number">
                        03
                    </div>

                    <div>

                        <div class="insight-title">
                            Completion
                        </div>

                        <div class="insight-text">
                            See where learners have completed activities
                            and submitted their work.
                        </div>

                    </div>

                </div>


                <div class="insight-item">

                    <div class="insight-number">
                        04
                    </div>

                    <div>

                        <div class="insight-title">
                            Support
                        </div>

                        <div class="insight-text">
                            Identify learners whose progress may benefit
                            from timely guidance.
                        </div>

                    </div>

                </div>

            </div>

        </div>

    </section>


    <!-- NEXT -->

    <section class="next">

        <div>

            <div class="next-label">
                NEXT
            </div>

            <h2>
                Explore your active cohorts.
            </h2>

            <p>
                See programme groups, faculty support and cohort-level
                progress in one place.
            </p>

        </div>


        <a
            href="institution-cohort.php"
            class="next-button"
            aria-label="Explore active cohorts"
        >
            →
        </a>

    </section>


    <!-- FOOTER -->

    <footer>

        <div>

            <strong>
                AIraut
            </strong>

            — Future Readiness Development System

        </div>


        <a href="institution-dashboard.php">
            ← Institution Dashboard
        </a>

    </footer>


</div>

</body>

</html>
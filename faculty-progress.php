<?php

require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/config/database.php';

require_role('faculty');

$userId = current_user_id();


/* =========================================================
   FIND LOGGED-IN FACULTY
========================================================= */

$stmt = $conn->prepare(
    "SELECT id
     FROM faculty
     WHERE user_id = ?
     LIMIT 1"
);

$stmt->bind_param("i", $userId);
$stmt->execute();

$faculty = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$faculty) {
    http_response_code(404);
    exit('Faculty profile not found.');
}

$facultyId = (int) $faculty['id'];


/* =========================================================
   FACULTY COHORTS
========================================================= */

$stmt = $conn->prepare(
    "SELECT DISTINCT cohort_id
     FROM faculty_cohorts
     WHERE faculty_id = ?"
);

$stmt->bind_param("i", $facultyId);
$stmt->execute();

$cohortRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$cohortIds = array_map(
    fn($row) => (int) $row['cohort_id'],
    $cohortRows
);


/* =========================================================
   DEFAULT VALUES
========================================================= */

$totalLearners = 0;
$totalActiveActivities = 0;
$totalParticipating = 0;
$totalNeedAttention = 0;
$averageProgress = 0;

$learners = [];


/* =========================================================
   LOAD FACULTY'S LEARNERS
========================================================= */

if (!empty($cohortIds)) {

    $placeholders = implode(
        ',',
        array_fill(0, count($cohortIds), '?')
    );

    $types = str_repeat('i', count($cohortIds));

    /*
     * Students belonging to cohorts assigned to this faculty.
     */
    $sql = "
        SELECT
            st.id AS student_id,
            st.cohort_id,
            u.name AS student_name,
            c.name AS cohort_name,

            (
                SELECT COUNT(*)
                FROM activities a
                WHERE a.cohort_id = st.cohort_id
                  AND a.status = 'active'
            ) AS active_activities,

            (
                SELECT COUNT(*)
                FROM submissions s
                WHERE s.student_id = st.id
            ) AS submission_count,

            (
                SELECT COUNT(*)
                FROM reviews r
                INNER JOIN submissions s2
                    ON s2.id = r.submission_id
                WHERE s2.student_id = st.id
            ) AS reviewed_count

        FROM students st

        INNER JOIN users u
            ON u.id = st.user_id

        INNER JOIN cohorts c
            ON c.id = st.cohort_id

        WHERE st.cohort_id IN ($placeholders)
          AND st.status = 'active'

        ORDER BY u.name ASC
    ";

    $stmt = $conn->prepare($sql);

    $stmt->bind_param(
        $types,
        ...$cohortIds
    );

    $stmt->execute();

    $learners = $stmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $stmt->close();
}


/* =========================================================
   CALCULATE FACULTY PROGRESS
========================================================= */

$totalLearners = count($learners);

$progressTotal = 0;

foreach ($learners as &$learner) {

    $activeActivities = (int) $learner['active_activities'];

    $submissionCount = (int) $learner['submission_count'];

    $reviewedCount = (int) $learner['reviewed_count'];


    /* Individual participation progress */

    if ($activeActivities > 0) {

        $progress = min(
            100,
            round(
                ($submissionCount / $activeActivities) * 100
            )
        );

    } else {

        $progress = 0;

    }


    $learner['progress'] = $progress;


    /* Status */

    if ($submissionCount === 0) {

        $learner['status'] = 'Needs attention';

        $learner['status_class'] = 'review';

        $totalNeedAttention++;

    } elseif ($reviewedCount < $submissionCount) {

        $learner['status'] = 'Review pending';

        $learner['status_class'] = 'review';

    } else {

        $learner['status'] = 'On track';

        $learner['status_class'] = 'on-track';

    }


    if ($submissionCount > 0) {
        $totalParticipating++;
    }


    $progressTotal += $progress;


    $totalActiveActivities = max(
        $totalActiveActivities,
        $activeActivities
    );
}

unset($learner);


if ($totalLearners > 0) {

    $averageProgress = round(
        $progressTotal / $totalLearners
    );

}

$participationRate = $totalLearners > 0
    ? round(
        ($totalParticipating / $totalLearners) * 100
    )
    : 0;


/* =========================================================
   COHORT LABEL
========================================================= */

$cohortLabel = count($cohortIds) === 1
    ? ($learners[0]['cohort_name'] ?? 'Assigned Cohort')
    : 'Assigned Cohorts';

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
            width: min(
                1400px,
                calc(100% - 96px)
            );

            margin: 0 auto;
        }


        /* =========================================================
           HEADER
        ========================================================= */

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


        /* =========================================================
           HERO
        ========================================================= */

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

            font-size: clamp(
                64px,
                7vw,
                112px
            );

            line-height: .96;

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


        /* =========================================================
           OVERVIEW
        ========================================================= */

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


        /* =========================================================
           METRICS
        ========================================================= */

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


        /* =========================================================
           PROGRESS
        ========================================================= */

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


        /* =========================================================
           LEARNER ROWS
        ========================================================= */

        .learner-list {
            border-top: 1px solid var(--line);
        }


        .learner {
            display: grid;

            grid-template-columns:
                70px
                1.5fr
                0.8fr
                1fr
                150px;

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


        .view {
            font-weight: 700;

            text-align: right;

            transition: color .2s ease;
        }


        .view:hover {
            color: var(--teal);
        }


        /* =========================================================
           EMPTY STATE
        ========================================================= */

        .empty-state {
            padding: 90px 20px;

            border-bottom: 1px solid var(--line);

            text-align: center;
        }


        .empty-state h3 {
            font-size: 32px;

            margin-bottom: 12px;
        }


        .empty-state p {
            max-width: 620px;

            margin: 0 auto;

            color: var(--muted);

            font-size: 18px;

            line-height: 1.6;
        }


        /* =========================================================
           INSIGHT
        ========================================================= */

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


        /* =========================================================
           NEXT
        ========================================================= */

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
            font-size: clamp(
                48px,
                5vw,
                78px
            );

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
                transform .2s ease,
                background .2s ease;
        }


        .next-button:hover {
            transform: translateX(5px);

            background: var(--teal);
        }


        /* =========================================================
           FOOTER
        ========================================================= */

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


        /* =========================================================
           RESPONSIVE
        ========================================================= */

        @media (max-width: 900px) {

            .page {
                width: min(
                    100% - 40px,
                    700px
                );
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
                grid-template-columns:
                    45px
                    1fr
                    100px;

                padding: 28px 0;
            }


            .status {
                display: none;
            }


            .view {
                text-align: left;
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
                grid-template-columns:
                    35px
                    1fr;

                gap: 12px;
            }


            .progress-value,
            .view {
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


    <!-- =========================================================
         HEADER
    ========================================================= -->

    <header>

        <a href="faculty-dashboard.php">

            <img
                src="assets/airaut-logo.png"
                alt="AIraut"
                class="logo"
            >

        </a>


        <div class="header-right">

            <span class="role">
                FACULTY
            </span>

            <a
                href="faculty-dashboard.php"
                class="back"
            >
                ← Faculty Dashboard
            </a>

        </div>

    </header>


    <!-- =========================================================
         HERO
    ========================================================= -->

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


    <!-- =========================================================
         COHORT OVERVIEW
    ========================================================= -->

    <section class="overview">

        <div class="section-label">
            COHORT OVERVIEW
        </div>


        <div class="overview-grid">

            <h2 class="overview-title">
                A clearer view of the journey.
            </h2>


            <p class="overview-copy">

                Follow how learners are engaging with the programme
                and where they are progressing across
                <?= htmlspecialchars($cohortLabel) ?>.

            </p>

        </div>


        <!-- METRICS -->

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

                    <?= $averageProgress ?>%

                </div>

                <div class="metric-label">
                    Average Progress
                </div>

            </div>


            <div class="metric">

                <div class="metric-number">

                    <?= $participationRate ?>%

                </div>

                <div class="metric-label">
                    Participating
                </div>

            </div>


            <div class="metric">

                <div class="metric-number metric-attention">

                    <?= $totalNeedAttention ?>

                </div>

                <div class="metric-label">
                    Need Attention
                </div>

            </div>


        </div>

    </section>


    <!-- =========================================================
         LEARNER PROGRESS
    ========================================================= -->

    <section class="progress-section">


        <div class="section-label">
            LEARNER PROGRESS
        </div>


        <div class="progress-heading">

            <h2>
                Where your learners are now.
            </h2>


            <p>

                This view is calculated from active cohort activities,
                student submissions and completed faculty reviews.

            </p>

        </div>


        <div class="learner-list">


            <?php if (empty($learners)): ?>


                <div class="empty-state">

                    <h3>
                        No learners yet.
                    </h3>

                    <p>

                        Students belonging to your assigned cohorts
                        will appear here as they are added.

                    </p>

                </div>


            <?php else: ?>


                <?php foreach ($learners as $index => $learner): ?>


                    <div class="learner">


                        <div class="number">

                            <?= str_pad(
                                $index + 1,
                                2,
                                '0',
                                STR_PAD_LEFT
                            ) ?>

                        </div>


                        <div>

                            <div class="learner-name">

                                <?= htmlspecialchars(
                                    $learner['student_name']
                                ) ?>

                            </div>


                            <div class="learner-sub">

                                <?= htmlspecialchars(
                                    $learner['cohort_name']
                                ) ?>

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


                        <div class="status <?= $learner['status_class'] ?>">

                            <?= htmlspecialchars(
                                $learner['status']
                            ) ?>

                        </div>


                        <a
                            href="faculty-student.php?student_id=<?= (int) $learner['student_id'] ?>"
                            class="view"
                        >

                            View →

                        </a>


                    </div>


                <?php endforeach; ?>


            <?php endif; ?>


        </div>

    </section>


    <!-- =========================================================
         FACULTY INSIGHT
    ========================================================= -->

    <section class="insight">


        <div class="insight-grid">


            <div>

                <div class="section-label">
                    FACULTY VIEW
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

                            See which learners have started submitting
                            their assigned activities.

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

                            Follow activity completion across
                            your assigned learners.

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

                            See which submitted activities have
                            also received faculty review.

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

                            Identify learners who have not yet
                            started submitting their work.

                        </div>

                    </div>

                </div>


            </div>

        </div>

    </section>


    <!-- =========================================================
         NEXT
    ========================================================= -->

    <section class="next">


        <div>

            <div class="next-label">
                NEXT
            </div>


            <h2>
                See student work and give meaningful feedback.
            </h2>


            <p>
                Review submitted activities and reflections.
            </p>

        </div>


        <a
            href="faculty-submissions.php"
            class="next-button"
            aria-label="Review student work"
        >
            →
        </a>


    </section>


    <!-- =========================================================
         FOOTER
    ========================================================= -->

    <footer>

        <div>

            <strong>
                AIraut
            </strong>

            — Future Readiness Development System

        </div>


        <a href="faculty-dashboard.php">

            ← Faculty Dashboard

        </a>

    </footer>


</div>

</body>

</html>
<?php

require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/config/database.php';

require_role('institution');

$userEmail = $_SESSION['email'] ?? '';

if ($userEmail === '') {
    http_response_code(403);
    exit('Institution account email not found.');
}


/* =========================================================
   FIND LOGGED-IN INSTITUTION
========================================================= */

$stmt = $conn->prepare(
    "SELECT
        id,
        name,
        code,
        contact_name,
        contact_email,
        status
     FROM institutions
     WHERE contact_email = ?
       AND status <> 'inactive'
     LIMIT 1"
);

$stmt->bind_param("s", $userEmail);
$stmt->execute();

$institution = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$institution) {
    http_response_code(404);
    exit('Institution profile not linked to this account.');
}

$institutionId = (int) $institution['id'];


/* =========================================================
   INSTITUTION SUMMARY
========================================================= */

/* Active cohorts */

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM cohorts
     WHERE institution_id = ?
       AND status = 'active'"
);

$stmt->bind_param("i", $institutionId);
$stmt->execute();

$activeCohorts = (int) (
    $stmt->get_result()->fetch_assoc()['total'] ?? 0
);

$stmt->close();


/* Active learners */

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM students
     WHERE institution_id = ?
       AND status = 'active'"
);

$stmt->bind_param("i", $institutionId);
$stmt->execute();

$totalLearners = (int) (
    $stmt->get_result()->fetch_assoc()['total'] ?? 0
);

$stmt->close();


/* Faculty facilitators assigned to institution cohorts */

$stmt = $conn->prepare(
    "SELECT COUNT(DISTINCT fc.faculty_id) AS total
     FROM faculty_cohorts fc
     INNER JOIN cohorts c
        ON c.id = fc.cohort_id
     INNER JOIN faculty f
        ON f.id = fc.faculty_id
     WHERE c.institution_id = ?
       AND c.status = 'active'
       AND f.status = 'active'"
);

$stmt->bind_param("i", $institutionId);
$stmt->execute();

$totalFaculty = (int) (
    $stmt->get_result()->fetch_assoc()['total'] ?? 0
);

$stmt->close();


/* =========================================================
   INSTITUTION-LEVEL AVERAGE PROGRESS
========================================================= */

$stmt = $conn->prepare(
    "SELECT AVG(cohort_progress) AS average_progress
     FROM (

        SELECT
            c.id AS cohort_id,

            CASE
                WHEN COUNT(DISTINCT st.id) = 0
                     OR COUNT(DISTINCT a.id) = 0
                THEN 0

                ELSE
                    (
                        COUNT(
                            DISTINCT
                            CASE
                                WHEN s.id IS NOT NULL
                                THEN CONCAT(
                                    st.id,
                                    ':',
                                    a.id
                                )
                            END
                        )
                        /
                        (
                            COUNT(DISTINCT st.id)
                            *
                            COUNT(DISTINCT a.id)
                        )
                    ) * 100
            END AS cohort_progress

        FROM cohorts c

        LEFT JOIN students st
            ON st.cohort_id = c.id
           AND st.institution_id = c.institution_id
           AND st.status = 'active'

        LEFT JOIN activities a
            ON a.cohort_id = c.id
           AND a.status = 'active'

        LEFT JOIN submissions s
            ON s.student_id = st.id
           AND s.activity_id = a.id

        WHERE c.institution_id = ?
          AND c.status = 'active'

        GROUP BY c.id

     ) AS cohort_progress_data"
);

$stmt->bind_param("i", $institutionId);
$stmt->execute();

$averageProgressResult =
    $stmt->get_result()->fetch_assoc();

$stmt->close();

$averageProgress =
    $averageProgressResult['average_progress'] !== null
        ? round(
            (float) $averageProgressResult['average_progress']
        )
        : 0;


/* =========================================================
   LOAD ACTIVE COHORTS
========================================================= */

$stmt = $conn->prepare(
    "SELECT

        c.id AS cohort_id,
        c.name AS cohort_name,
        c.status,

        p.name AS programme_name,

        COUNT(DISTINCT st.id) AS learner_count,

        COUNT(DISTINCT fc.faculty_id) AS faculty_count,

        COUNT(DISTINCT a.id) AS active_activity_count,

        COUNT(
            DISTINCT
            CASE
                WHEN s.id IS NOT NULL
                THEN CONCAT(
                    st.id,
                    ':',
                    a.id
                )
            END
        ) AS completed_activity_count

     FROM cohorts c

     LEFT JOIN programmes p
        ON p.id = c.programme_id

     LEFT JOIN students st
        ON st.cohort_id = c.id
       AND st.institution_id = c.institution_id
       AND st.status = 'active'

     LEFT JOIN faculty_cohorts fc
        ON fc.cohort_id = c.id

     LEFT JOIN faculty f
        ON f.id = fc.faculty_id
       AND f.status = 'active'

     LEFT JOIN activities a
        ON a.cohort_id = c.id
       AND a.status = 'active'

     LEFT JOIN submissions s
        ON s.student_id = st.id
       AND s.activity_id = a.id

     WHERE c.institution_id = ?
       AND c.status = 'active'

     GROUP BY
        c.id,
        c.name,
        c.status,
        p.name

     ORDER BY c.id ASC"
);

$stmt->bind_param("i", $institutionId);
$stmt->execute();

$cohorts = $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$stmt->close();


/* =========================================================
   CALCULATE COHORT PROGRESS
========================================================= */

foreach ($cohorts as &$cohort) {

    $learners =
        (int) $cohort['learner_count'];

    $activities =
        (int) $cohort['active_activity_count'];

    $completed =
        (int) $cohort['completed_activity_count'];


    if (
        $learners > 0 &&
        $activities > 0
    ) {

        $cohort['progress'] = min(
            100,
            round(
                (
                    $completed
                    /
                    ($learners * $activities)
                ) * 100
            )
        );

    } else {

        $cohort['progress'] = 0;

    }
}

unset($cohort);


/* =========================================================
   DISPLAY NAME
========================================================= */

$institutionName =
    $institution['name'] ?? 'Your Institution';

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
        Cohort Overview — AIraut
    </title>


    <style>

        :root {
            --navy: #12395b;
            --cyan: #0aa4bd;
            --gold: #e3a000;
            --text-soft: #4779ad;
            --line: #d9d9d4;
            --cream: #fbfaf7;
        }


        * {
            box-sizing: border-box;
        }


        html {
            scroll-behavior: smooth;
        }


        body {
            margin: 0;

            background: var(--cream);

            color: var(--navy);

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            -webkit-font-smoothing: antialiased;
        }


        a {
            color: inherit;

            text-decoration: none;
        }


        .page {
            width: min(
                1570px,
                calc(100% - 150px)
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
            width: 145px;

            height: auto;
        }


        .header-right {
            display: flex;

            align-items: center;

            gap: 48px;

            font-size: 17px;

            font-weight: 600;
        }


        .role {
            color: var(--cyan);

            font-size: 18px;

            letter-spacing: 9px;

            font-weight: 700;
        }


        .back-link {
            transition:
                transform .2s ease,
                color .2s ease;
        }


        .back-link:hover {
            transform: translateX(-4px);

            color: var(--cyan);
        }


        /* =========================================================
           HERO
        ========================================================= */

        .hero {
            min-height: 610px;

            display: flex;

            flex-direction: column;

            justify-content: center;

            padding: 70px 0 100px;
        }


        .eyebrow {
            color: var(--cyan);

            font-size: 18px;

            font-weight: 700;

            letter-spacing: 9px;

            margin-bottom: 62px;
        }


        h1 {
            font-size: clamp(
                70px,
                7.2vw,
                122px
            );

            line-height: .94;

            letter-spacing: -5px;

            max-width: 1200px;

            margin: 0;

            font-weight: 750;
        }


        h1 span {
            color: var(--cyan);
        }


        .hero-copy {
            max-width: 820px;

            margin-top: 46px;

            color: var(--text-soft);

            font-size: 24px;

            line-height: 1.55;
        }


        /* =========================================================
           SECTION
        ========================================================= */

        .section {
            border-top: 1px solid var(--line);

            padding: 120px 0;
        }


        .section-label {
            color: var(--cyan);

            font-size: 17px;

            font-weight: 700;

            letter-spacing: 9px;

            margin-bottom: 62px;
        }


        .section-intro {
            display: grid;

            grid-template-columns: 1fr 1fr;

            gap: 100px;

            align-items: start;

            margin-bottom: 90px;
        }


        h2 {
            font-size: clamp(
                48px,
                4.2vw,
                72px
            );

            line-height: 1.02;

            letter-spacing: -3px;

            margin: 0;

            max-width: 700px;
        }


        .intro-copy {
            color: var(--text-soft);

            font-size: 22px;

            line-height: 1.75;

            max-width: 650px;
        }


        /* =========================================================
           SUMMARY
        ========================================================= */

        .summary {
            display: grid;

            grid-template-columns:
                repeat(4, 1fr);

            border-top: 1px solid var(--line);

            border-bottom: 1px solid var(--line);
        }


        .summary-item {
            min-height: 190px;

            padding: 38px 42px;

            border-right: 1px solid var(--line);
        }


        .summary-item:last-child {
            border-right: none;
        }


        .number {
            font-size: 62px;

            line-height: 1;

            font-weight: 750;

            margin-bottom: 20px;
        }


        .summary-item:nth-child(2)
        .number {
            color: var(--cyan);
        }


        .summary-item:nth-child(4)
        .number {
            color: var(--gold);
        }


        .summary-label {
            color: var(--text-soft);

            font-size: 18px;
        }


        /* =========================================================
           COHORT LIST
        ========================================================= */

        .cohort-list {
            border-top: 1px solid var(--line);
        }


        .cohort {
            display: grid;

            grid-template-columns:
                80px
                minmax(350px, 1.5fr)
                180px
                160px
                180px
                100px;

            gap: 30px;

            align-items: center;

            min-height: 190px;

            border-bottom: 1px solid var(--line);

            transition:
                background .25s ease;
        }


        .cohort:hover {
            background:
                rgba(
                    10,
                    164,
                    189,
                    .035
                );
        }


        .cohort-number {
            color: var(--cyan);

            font-size: 18px;

            font-weight: 700;
        }


        .cohort-type {
            color: var(--cyan);

            font-size: 14px;

            font-weight: 700;

            letter-spacing: 5px;

            margin-bottom: 18px;
        }


        .cohort-name {
            font-size: 28px;

            font-weight: 700;

            margin-bottom: 10px;
        }


        .cohort-detail {
            color: var(--text-soft);

            font-size: 16px;

            line-height: 1.5;
        }


        .data-label {
            color: var(--text-soft);

            font-size: 14px;

            margin-bottom: 9px;
        }


        .data-value {
            font-size: 20px;

            font-weight: 700;
        }


        .status {
            color: var(--cyan);

            font-weight: 700;

            font-size: 16px;
        }


        .status.inactive {
            color: var(--gold);
        }


        .view {
            font-size: 17px;

            font-weight: 700;

            text-align: right;

            white-space: nowrap;
        }


        .view span {
            color: var(--cyan);

            margin-left: 7px;
        }


        .view:hover {
            color: var(--cyan);
        }


        /* =========================================================
           EMPTY STATE
        ========================================================= */

        .empty-state {
            padding: 100px 30px;

            border-bottom: 1px solid var(--line);

            text-align: center;
        }


        .empty-state h3 {
            margin: 0 0 12px;

            font-size: 34px;

            letter-spacing: -1px;
        }


        .empty-state p {
            max-width: 650px;

            margin: 0 auto;

            color: var(--text-soft);

            font-size: 18px;

            line-height: 1.6;
        }


        /* =========================================================
           CLOSING
        ========================================================= */

        .closing {
            min-height: 520px;

            display: flex;

            flex-direction: column;

            justify-content: center;
        }


        .closing h2 {
            max-width: 1050px;
        }


        .closing h2 span {
            color: var(--cyan);
        }


        .closing-copy {
            color: var(--text-soft);

            font-size: 21px;

            line-height: 1.7;

            max-width: 700px;

            margin-top: 38px;
        }


        /* =========================================================
           FOOTER
        ========================================================= */

        footer {
            border-top: 1px solid var(--line);

            min-height: 120px;

            display: flex;

            align-items: center;

            justify-content: space-between;

            color: var(--text-soft);

            font-size: 17px;
        }


        footer strong {
            color: var(--navy);
        }


        footer a:hover {
            color: var(--cyan);
        }


        /* =========================================================
           RESPONSIVE
        ========================================================= */

        @media (max-width: 1100px) {

            .page {
                width: min(
                    100% - 60px,
                    900px
                );
            }


            .section-intro {
                grid-template-columns: 1fr;

                gap: 40px;
            }


            .summary {
                grid-template-columns:
                    repeat(2, 1fr);
            }


            .summary-item:nth-child(2) {
                border-right: none;
            }


            .cohort {
                grid-template-columns:
                    55px
                    1fr
                    130px;

                padding: 35px 0;
            }


            .cohort
            .data-block:nth-of-type(2),
            .cohort
            .data-block:nth-of-type(3) {
                display: none;
            }


            .view {
                text-align: right;
            }

        }


        @media (max-width: 700px) {

            .page {
                width:
                    calc(100% - 36px);
            }


            header {
                height: 105px;
            }


            .logo {
                width: 105px;
            }


            .header-right {
                gap: 15px;

                font-size: 14px;
            }


            .role {
                font-size: 13px;

                letter-spacing: 4px;
            }


            .hero {
                min-height: 520px;

                padding: 50px 0 70px;
            }


            .eyebrow,
            .section-label {
                font-size: 13px;

                letter-spacing: 5px;

                margin-bottom: 35px;
            }


            h1 {
                font-size: 58px;

                letter-spacing: -2px;
            }


            .hero-copy {
                font-size: 18px;
            }


            .section {
                padding: 75px 0;
            }


            h2 {
                font-size: 43px;

                letter-spacing: -2px;
            }


            .summary {
                grid-template-columns:
                    1fr 1fr;
            }


            .summary-item {
                padding: 28px 20px;

                min-height: 145px;
            }


            .number {
                font-size: 42px;
            }


            .summary-label {
                font-size: 15px;
            }


            .cohort {
                grid-template-columns:
                    40px
                    1fr;

                gap: 15px;

                min-height: 180px;
            }


            .view {
                grid-column: 2;

                text-align: left;

                margin-top: -20px;
            }


            footer {
                padding: 30px 0;

                flex-direction: column;

                align-items: flex-start;

                gap: 20px;
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

        <a href="platform.html">

            <img
                src="assets/airaut-logo.png"
                alt="AIraut"
                class="logo"
            >

        </a>


        <div class="header-right">

            <div class="role">
                INSTITUTION
            </div>


            <a
                href="institution-dashboard.php"
                class="back-link"
            >
                ← Institution Dashboard
            </a>

        </div>

    </header>


    <!-- =========================================================
         HERO
    ========================================================= -->

    <section class="hero">

        <div class="eyebrow">
            COHORT OVERVIEW
        </div>


        <h1>

            See the programmes<br>
            and learner groups<br>

            <span>
                in progress.
            </span>

        </h1>


        <p class="hero-copy">

            Understand the programmes currently running across your
            institution, the learners taking part and the teams supporting
            their development.

        </p>

    </section>


    <!-- =========================================================
         INSTITUTION VIEW
    ========================================================= -->

    <section class="section">


        <div class="section-label">
            INSTITUTION VIEW
        </div>


        <div class="section-intro">


            <h2>

                A clearer view of
                <br>
                every active
                <br>

                <span style="color: var(--cyan);">
                    cohort.
                </span>

            </h2>


            <div class="intro-copy">

                Follow programmes and learner groups as they move through
                AIraut. See participation, progress and the people helping
                make the experience meaningful.

            </div>

        </div>


        <!-- =====================================================
             SUMMARY
        ====================================================== -->

        <div class="summary">


            <div class="summary-item">

                <div class="number">

                    <?= str_pad(
                        $activeCohorts,
                        2,
                        '0',
                        STR_PAD_LEFT
                    ) ?>

                </div>


                <div class="summary-label">
                    Active Cohorts
                </div>

            </div>


            <div class="summary-item">

                <div class="number">
                    <?= $totalLearners ?>
                </div>


                <div class="summary-label">
                    Learners
                </div>

            </div>


            <div class="summary-item">

                <div class="number">
                    <?= str_pad(
                        $totalFaculty,
                        2,
                        '0',
                        STR_PAD_LEFT
                    ) ?>
                </div>


                <div class="summary-label">
                    Faculty Facilitators
                </div>

            </div>


            <div class="summary-item">

                <div class="number">
                    <?= $averageProgress ?>%
                </div>


                <div class="summary-label">
                    Average Progress
                </div>

            </div>


        </div>

    </section>


    <!-- =========================================================
         COHORTS
    ========================================================= -->

    <section class="section">


        <div class="section-label">
            ACTIVE COHORTS
        </div>


        <div class="cohort-list">


            <?php if (empty($cohorts)): ?>


                <div class="empty-state">

                    <h3>
                        No active cohorts yet.
                    </h3>


                    <p>

                        Active AIraut cohorts belonging to your institution
                        will appear here once they are created.

                    </p>

                </div>


            <?php else: ?>


                <?php foreach ($cohorts as $index => $cohort): ?>


                    <div class="cohort">


                        <div class="cohort-number">

                            <?= str_pad(
                                $index + 1,
                                2,
                                '0',
                                STR_PAD_LEFT
                            ) ?>

                        </div>


                        <div>


                            <div class="cohort-type">

                                <?= htmlspecialchars(
                                    $cohort['programme_name']
                                    ?: 'AIraut Programme'
                                ) ?>

                            </div>


                            <div class="cohort-name">

                                <?= htmlspecialchars(
                                    $cohort['cohort_name']
                                ) ?>

                            </div>


                            <div class="cohort-detail">

                                <?= (int) $cohort['learner_count'] ?>

                                learners across this cohort,
                                supported by

                                <?= (int) $cohort['faculty_count'] ?>

                                faculty facilitator
                                <?= (int) $cohort['faculty_count'] === 1
                                    ? ''
                                    : 's'
                                ?>.

                            </div>


                        </div>


                        <!-- LEARNERS -->

                        <div class="data-block">

                            <div class="data-label">
                                Learners
                            </div>


                            <div class="data-value">

                                <?= (int)
                                    $cohort['learner_count']
                                ?>

                            </div>

                        </div>


                        <!-- FACULTY -->

                        <div class="data-block">

                            <div class="data-label">
                                Faculty
                            </div>


                            <div class="data-value">

                                <?= str_pad(
                                    (int)
                                    $cohort['faculty_count'],
                                    2,
                                    '0',
                                    STR_PAD_LEFT
                                ) ?>

                            </div>

                        </div>


                        <!-- PROGRESS -->

                        <div class="data-block">

                            <div class="data-label">
                                Progress
                            </div>


                            <div class="data-value">

                                <?= (int)
                                    $cohort['progress']
                                ?>%

                            </div>

                        </div>


                        <!-- STATUS -->

                        <div class="status">

                            ACTIVE

                        </div>


                    </div>


                <?php endforeach; ?>


            <?php endif; ?>


        </div>

    </section>


    <!-- =========================================================
         CLOSING
    ========================================================= -->

    <section class="section closing">


        <div class="section-label">
            NEXT
        </div>


        <h2>

            Move from seeing the cohort
            <br>

            to understanding
            <span>
                its journey.
            </span>

        </h2>


        <p class="closing-copy">

            Explore participation and progress to understand how learners
            are engaging with AIraut and where support may be needed.

        </p>

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


        <a href="institution-dashboard.php">

            ← Institution Dashboard

        </a>


    </footer>


</div>

</body>

</html>
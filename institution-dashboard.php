<?php

require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/config/database.php';

require_role('institution');

$userId = current_user_id();
$userEmail = $_SESSION['email'] ?? '';


/* =========================================================
   FIND INSTITUTION
   MVP LINK:
   logged-in institution user's email
   = institutions.contact_email
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
    exit(
        'Institution profile not linked to this account.'
    );
}

$institutionId = (int) $institution['id'];


/* =========================================================
   ACTIVE COHORTS
========================================================= */

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


/* =========================================================
   ACTIVE LEARNERS
========================================================= */

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM students
     WHERE institution_id = ?
       AND status = 'active'"
);

$stmt->bind_param("i", $institutionId);
$stmt->execute();

$activeLearners = (int) (
    $stmt->get_result()->fetch_assoc()['total'] ?? 0
);

$stmt->close();


/* =========================================================
   ACTIVE FACULTY
========================================================= */

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM faculty
     WHERE institution_id = ?
       AND status = 'active'"
);

$stmt->bind_param("i", $institutionId);
$stmt->execute();

$activeFaculty = (int) (
    $stmt->get_result()->fetch_assoc()['total'] ?? 0
);

$stmt->close();


/* =========================================================
   AVERAGE LEARNER PROGRESS
   Based on:
   submitted active activities /
   active activities assigned to the learner's cohort
========================================================= */

$stmt = $conn->prepare(
    "SELECT AVG(learner_progress) AS average_progress
     FROM (

        SELECT
            st.id AS student_id,

            CASE
                WHEN COUNT(DISTINCT a.id) = 0 THEN 0
                ELSE
                    (
                        COUNT(DISTINCT s.activity_id)
                        /
                        COUNT(DISTINCT a.id)
                    ) * 100
            END AS learner_progress

        FROM students st

        INNER JOIN cohorts c
            ON c.id = st.cohort_id
           AND c.institution_id = st.institution_id
           AND c.status = 'active'

        LEFT JOIN activities a
            ON a.cohort_id = st.cohort_id
           AND a.status = 'active'

        LEFT JOIN submissions s
            ON s.student_id = st.id
           AND s.activity_id = a.id

        WHERE st.institution_id = ?
          AND st.status = 'active'

        GROUP BY st.id

     ) AS progress_data"
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
   PROGRAMMES CURRENTLY ACTIVE
========================================================= */

$stmt = $conn->prepare(
    "SELECT COUNT(DISTINCT programme_id) AS total
     FROM cohorts
     WHERE institution_id = ?
       AND status = 'active'
       AND programme_id IS NOT NULL"
);

$stmt->bind_param("i", $institutionId);
$stmt->execute();

$activeProgrammes = (int) (
    $stmt->get_result()->fetch_assoc()['total'] ?? 0
);

$stmt->close();


/* =========================================================
   INSTITUTION DISPLAY NAME
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
        Institution Dashboard — AIraut
    </title>


    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }


        body {
            font-family: Arial, Helvetica, sans-serif;

            background: #fbfaf6;

            color: #123454;

            line-height: 1.5;
        }


        a {
            color: inherit;

            text-decoration: none;
        }


        .page {
            width: 100%;
        }


        /* =========================================================
           HEADER
        ========================================================= */

        header {
            height: 150px;

            padding: 38px 7.3vw 0;

            display: flex;

            align-items: flex-start;

            justify-content: space-between;
        }


        .logo {
            width: 150px;

            height: auto;

            display: block;
        }


        .header-right {
            display: flex;

            align-items: center;

            gap: 42px;

            padding-top: 8px;
        }


        .role {
            color: #079db2;

            font-size: 17px;

            font-weight: 700;

            letter-spacing: 6px;
        }


        .back-link {
            font-size: 18px;

            font-weight: 700;

            color: #123454;
        }


        .back-link:hover {
            color: #079db2;
        }


        /* =========================================================
           HERO
        ========================================================= */

        .hero {
            min-height: 560px;

            padding: 95px 7.3vw 90px;

            border-bottom: 1px solid #d9ddd9;

            display: flex;

            align-items: center;
        }


        .hero-content {
            max-width: 1100px;
        }


        .eyebrow {
            color: #079db2;

            font-size: 17px;

            font-weight: 700;

            letter-spacing: 7px;

            margin-bottom: 38px;
        }


        .hero h1 {
            font-size: clamp(
                64px,
                7vw,
                112px
            );

            line-height: .98;

            letter-spacing: -5px;

            max-width: 1100px;

            margin-bottom: 34px;

            font-weight: 750;
        }


        .hero h1 span {
            color: #079db2;
        }


        .hero p {
            color: #4c78a3;

            font-size: 22px;

            max-width: 900px;

            line-height: 1.55;
        }


        /* =========================================================
           OVERVIEW
        ========================================================= */

        .overview {
            padding: 125px 7.3vw 0;
        }


        .section-label {
            color: #079db2;

            font-size: 16px;

            font-weight: 700;

            letter-spacing: 7px;

            margin-bottom: 42px;
        }


        .intro-grid {
            display: grid;

            grid-template-columns: 1fr 1fr;

            gap: 100px;

            padding-bottom: 80px;
        }


        .intro-grid h2 {
            font-size: 57px;

            line-height: 1.08;

            letter-spacing: -2.5px;

            max-width: 650px;
        }


        .intro-grid p {
            color: #4c78a3;

            font-size: 20px;

            line-height: 1.7;

            max-width: 620px;

            padding-top: 8px;
        }


        /* =========================================================
           METRICS
        ========================================================= */

        .metrics {
            border-top: 1px solid #d9ddd9;

            border-bottom: 1px solid #d9ddd9;

            display: grid;

            grid-template-columns:
                repeat(4, 1fr);
        }


        .metric {
            min-height: 205px;

            padding: 42px 34px;

            border-right: 1px solid #d9ddd9;
        }


        .metric:last-child {
            border-right: none;
        }


        .metric-number {
            font-size: 62px;

            font-weight: 750;

            line-height: 1;

            margin-bottom: 18px;
        }


        .metric:nth-child(2) .metric-number {
            color: #079db2;
        }


        .metric:nth-child(3) .metric-number {
            color: #079db2;
        }


        .metric:nth-child(4) .metric-number {
            color: #d89405;
        }


        .metric-label {
            color: #4c78a3;

            font-size: 18px;
        }


        /* =========================================================
           ROUTES
        ========================================================= */

        .routes {
            padding: 130px 7.3vw;
        }


        .routes-heading {
            display: grid;

            grid-template-columns: 1fr 1fr;

            gap: 100px;

            margin-bottom: 75px;
        }


        .routes-heading h2 {
            font-size: 58px;

            line-height: 1.05;

            letter-spacing: -2.5px;

            max-width: 650px;
        }


        .routes-heading p {
            color: #4c78a3;

            font-size: 20px;

            line-height: 1.65;

            max-width: 610px;
        }


        .route-list {
            border-top: 1px solid #d9ddd9;
        }


        .route {
            display: grid;

            grid-template-columns:
                80px
                1fr
                180px;

            align-items: center;

            min-height: 155px;

            border-bottom: 1px solid #d9ddd9;

            transition: padding .25s ease;
        }


        .route:hover {
            padding-left: 12px;
        }


        .route-number {
            color: #079db2;

            font-size: 17px;

            font-weight: 700;

            letter-spacing: 2px;
        }


        .route-title {
            font-size: 29px;

            font-weight: 700;

            margin-bottom: 7px;
        }


        .route-description {
            color: #4c78a3;

            font-size: 17px;
        }


        .route-action {
            justify-self: end;

            font-size: 17px;

            font-weight: 700;
        }


        .route-action span {
            color: #079db2;

            margin-left: 7px;
        }


        /* =========================================================
           INSIGHT
        ========================================================= */

        .insight {
            margin: 0 7.3vw;

            border-top: 1px solid #d9ddd9;

            padding: 125px 0 135px;

            display: grid;

            grid-template-columns: 1fr 1fr;

            gap: 100px;
        }


        .insight h2 {
            font-size: 59px;

            line-height: 1.04;

            letter-spacing: -3px;

            max-width: 700px;
        }


        .insight h2 span {
            color: #079db2;
        }


        .insight-copy {
            color: #4c78a3;

            font-size: 20px;

            line-height: 1.7;

            max-width: 620px;
        }


        /* =========================================================
           FOOTER
        ========================================================= */

        footer {
            border-top: 1px solid #d9ddd9;

            padding: 42px 7.3vw 55px;

            display: flex;

            justify-content: space-between;

            color: #4c78a3;

            font-size: 17px;
        }


        footer strong {
            color: #123454;
        }


        footer a:hover {
            color: #079db2;
        }


        /* =========================================================
           MOBILE
        ========================================================= */

        @media (max-width: 850px) {

            header {
                height: 120px;

                padding: 25px 6vw 0;
            }


            .logo {
                width: 110px;
            }


            .header-right {
                gap: 15px;
            }


            .role {
                font-size: 12px;

                letter-spacing: 3px;
            }


            .back-link {
                font-size: 14px;
            }


            .hero {
                padding: 75px 6vw;

                min-height: 500px;
            }


            .hero h1 {
                font-size: 58px;

                letter-spacing: -3px;
            }


            .hero p {
                font-size: 18px;
            }


            .overview,
            .routes {
                padding-left: 6vw;

                padding-right: 6vw;
            }


            .intro-grid,
            .routes-heading,
            .insight {
                grid-template-columns: 1fr;

                gap: 45px;
            }


            .intro-grid h2,
            .routes-heading h2,
            .insight h2 {
                font-size: 45px;
            }


            .metrics {
                grid-template-columns: 1fr 1fr;
            }


            .metric:nth-child(2) {
                border-right: none;
            }


            .metric:nth-child(-n+2) {
                border-bottom: 1px solid #d9ddd9;
            }


            .route {
                grid-template-columns: 50px 1fr;

                padding: 30px 0;
            }


            .route-action {
                grid-column: 2;

                justify-self: start;

                margin-top: 18px;
            }


            .insight {
                margin: 0 6vw;
            }


            footer {
                padding-left: 6vw;

                padding-right: 6vw;

                flex-direction: column;

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
                href="logout.php"
                class="back-link"
            >
                Logout
            </a>


            <a
                href="platform.html"
                class="back-link"
            >
                ← Platform
            </a>

        </div>

    </header>


    <!-- =========================================================
         HERO
    ========================================================= -->

    <section class="hero">

        <div class="hero-content">

            <div class="eyebrow">
                INSTITUTION OVERVIEW
            </div>


            <h1>

                <?= htmlspecialchars(
                    $institutionName
                ) ?>

                is
                <span>
                    building future readiness.
                </span>

            </h1>


            <p>

                Understand participation and progress
                across your AIraut programmes and cohorts.

            </p>

        </div>

    </section>


    <!-- =========================================================
         OVERVIEW
    ========================================================= -->

    <section class="overview">

        <div class="section-label">
            INSTITUTION VIEW
        </div>


        <div class="intro-grid">


            <h2>
                A clearer view of the
                journey across your institution.
            </h2>


            <p>

                Follow how learners are engaging with AIraut,
                how cohorts are progressing and where learner
                support may be needed.

            </p>

        </div>


        <!-- =====================================================
             METRICS
        ====================================================== -->

        <div class="metrics">


            <div class="metric">

                <div class="metric-number">

                    <?= str_pad(
                        $activeCohorts,
                        2,
                        '0',
                        STR_PAD_LEFT
                    ) ?>

                </div>


                <div class="metric-label">
                    Active Cohorts
                </div>

            </div>


            <div class="metric">

                <div class="metric-number">

                    <?= $activeLearners ?>

                </div>


                <div class="metric-label">
                    Active Learners
                </div>

            </div>


            <div class="metric">

                <div class="metric-number">

                    <?= $averageProgress ?>%

                </div>


                <div class="metric-label">
                    Average Progress
                </div>

            </div>


            <div class="metric">

                <div class="metric-number">

                    <?= str_pad(
                        $activeFaculty,
                        2,
                        '0',
                        STR_PAD_LEFT
                    ) ?>

                </div>


                <div class="metric-label">
                    Faculty Facilitators
                </div>

            </div>


        </div>

    </section>


    <!-- =========================================================
         ROUTES
    ========================================================= -->

    <section class="routes">

        <div class="section-label">
            EXPLORE
        </div>


        <div class="routes-heading">


            <h2>

                See what is happening
                across your programme.

            </h2>


            <p>

                Move from the institution-wide view into
                cohorts and participation data to understand
                learner development and progress.

            </p>

        </div>


        <div class="route-list">


            <!-- =================================================
                 01 — COHORT OVERVIEW
            ================================================== -->

            <a
                href="institution-cohort.php"
                class="route"
            >


                <div class="route-number">
                    01
                </div>


                <div>

                    <div class="route-title">
                        Cohort Overview
                    </div>


                    <div class="route-description">

                        See the programmes and learner groups
                        currently in progress.

                    </div>

                </div>


                <div class="route-action">

                    View
                    <span>
                        →
                    </span>

                </div>


            </a>


            <!-- =================================================
                 02 — PARTICIPATION & PROGRESS
            ================================================== -->

            <a
                href="institution-progress.php"
                class="route"
            >


                <div class="route-number">
                    02
                </div>


                <div>

                    <div class="route-title">

                        Participation &amp; Progress

                    </div>


                    <div class="route-description">

                        Understand how learners are engaging,
                        submitting and progressing.

                    </div>

                </div>


                <div class="route-action">

                    View
                    <span>
                        →
                    </span>

                </div>


            </a>


        </div>

    </section>


    <!-- =========================================================
         INSIGHT
    ========================================================= -->

    <section class="insight">


        <div>

            <div class="section-label">

                INSTITUTIONAL VISIBILITY

            </div>


            <h2>

                Progress becomes useful
                when it helps you
                <span>
                    act.
                </span>

            </h2>

        </div>


        <div class="insight-copy">

            AIraut brings learner participation,
            development and programme progress into
            one structured institutional view — helping
            institutions understand where learners are,
            what is moving forward and where support
            may be needed.

        </div>


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


        <a href="platform.html">

            ← Platform

        </a>


    </footer>


</div>

</body>

</html>
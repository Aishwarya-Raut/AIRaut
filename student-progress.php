<?php

require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/config/database.php';

require_role('student');

$userId = current_user_id();


/* =========================================================
   FIND LOGGED-IN STUDENT
========================================================= */

$stmt = $conn->prepare(
    "SELECT
        id,
        cohort_id
     FROM students
     WHERE user_id = ?
     LIMIT 1"
);

$stmt->bind_param(
    "i",
    $userId
);

$stmt->execute();

$student = $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$student) {
    http_response_code(404);
    exit('Student profile not found.');
}

$studentId = (int) $student['id'];
$cohortId = (int) $student['cohort_id'];


/* =========================================================
   ACTIVE ACTIVITIES FOR THIS COHORT
========================================================= */

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM activities
     WHERE cohort_id = ?
       AND status = 'active'"
);

$stmt->bind_param(
    "i",
    $cohortId
);

$stmt->execute();

$totalActivities = (int) (
    $stmt->get_result()->fetch_assoc()['total'] ?? 0
);

$stmt->close();


/* =========================================================
   STUDENT SUBMISSIONS
   Only count submissions belonging to active activities
   in the student's cohort.
========================================================= */

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM submissions s

     INNER JOIN activities a
        ON a.id = s.activity_id
       AND a.cohort_id = ?
       AND a.status = 'active'

     WHERE s.student_id = ?"
);

$stmt->bind_param(
    "ii",
    $cohortId,
    $studentId
);

$stmt->execute();

$totalSubmissions = (int) (
    $stmt->get_result()->fetch_assoc()['total'] ?? 0
);

$stmt->close();


/* =========================================================
   REVIEWED ACTIVITIES
========================================================= */

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM reviews r

     INNER JOIN submissions s
        ON s.id = r.submission_id

     INNER JOIN activities a
        ON a.id = s.activity_id
       AND a.cohort_id = ?
       AND a.status = 'active'

     WHERE s.student_id = ?"
);

$stmt->bind_param(
    "ii",
    $cohortId,
    $studentId
);

$stmt->execute();

$totalReviewed = (int) (
    $stmt->get_result()->fetch_assoc()['total'] ?? 0
);

$stmt->close();


/* =========================================================
   AVERAGE SCORE
========================================================= */

$stmt = $conn->prepare(
    "SELECT AVG(r.score) AS average_score
     FROM reviews r

     INNER JOIN submissions s
        ON s.id = r.submission_id

     INNER JOIN activities a
        ON a.id = s.activity_id
       AND a.cohort_id = ?
       AND a.status = 'active'

     WHERE s.student_id = ?"
);

$stmt->bind_param(
    "ii",
    $cohortId,
    $studentId
);

$stmt->execute();

$averageScore =
    $stmt->get_result()->fetch_assoc()['average_score'];

$stmt->close();

$averageScore = $averageScore !== null
    ? round((float) $averageScore, 2)
    : null;


/* =========================================================
   JOURNEY PROGRESS
   Reviewed activities / active activities
========================================================= */

$journeyProgress = 0;

if ($totalActivities > 0) {

    $journeyProgress = min(
        100,
        round(
            (
                $totalReviewed
                / $totalActivities
            ) * 100
        )
    );
}


/* =========================================================
   COMPLETION PROGRESS
   Submitted / active activities
========================================================= */

$completionProgress = 0;

if ($totalActivities > 0) {

    $completionProgress = min(
        100,
        round(
            (
                $totalSubmissions
                / $totalActivities
            ) * 100
        )
    );
}


/* =========================================================
   DEVELOPMENT AREAS
=========================================================

   Each area is calculated independently:

   - activity_count:
       Number of active cohort activities mapped to the area.

   - reviewed_count:
       Number of those mapped activities that this student
       has submitted and received a faculty review for.

   - average_score:
       Average faculty score for reviewed submissions mapped
       to that development area.

   - evidence_progress:
       reviewed_count / activity_count

   This is evidence progress, not a claim of mastery.
========================================================= */

$developmentAreas = [];

$stmt = $conn->prepare(
    "SELECT
        da.id,
        da.name,
        da.code,
        da.description,

        COUNT(DISTINCT a.id) AS activity_count,

        COUNT(
            DISTINCT CASE
                WHEN s.id IS NOT NULL
                 AND r.id IS NOT NULL
                THEN a.id
            END
        ) AS reviewed_count,

        ROUND(
            AVG(
                CASE
                    WHEN s.id IS NOT NULL
                     AND r.score IS NOT NULL
                    THEN r.score
                END
            ),
            2
        ) AS average_score

     FROM development_areas da

     LEFT JOIN activity_development_areas ada
        ON ada.development_area_id = da.id

     LEFT JOIN activities a
        ON a.id = ada.activity_id
       AND a.cohort_id = ?
       AND a.status = 'active'

     LEFT JOIN submissions s
        ON s.activity_id = a.id
       AND s.student_id = ?

     LEFT JOIN reviews r
        ON r.submission_id = s.id

     WHERE da.status = 'active'

     GROUP BY
        da.id,
        da.name,
        da.code,
        da.description

     ORDER BY
        da.id ASC"
);

$stmt->bind_param(
    "ii",
    $cohortId,
    $studentId
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    $activityCount =
        (int) $row['activity_count'];

    $reviewedCount =
        (int) $row['reviewed_count'];


    if ($activityCount > 0) {

        $evidenceProgress = min(
            100,
            round(
                (
                    $reviewedCount
                    / $activityCount
                ) * 100
            )
        );

    } else {

        $evidenceProgress = null;
    }


    $row['evidence_progress'] =
        $evidenceProgress;


    $row['average_score'] =
        $row['average_score'] !== null
            ? round(
                (float) $row['average_score'],
                2
            )
            : null;


    $developmentAreas[] = $row;
}

$stmt->close();


/* =========================================================
   DISPLAY HELPERS
========================================================= */

function development_status(
    ?int $activityCount,
    ?int $reviewedCount
): string {

    if ($activityCount === null || $activityCount === 0) {
        return 'NO EVIDENCE YET';
    }

    return $reviewedCount
        . ' / '
        . $activityCount
        . ' REVIEWED';
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
        My Progress — AIraut
    </title>

    <link
        rel="stylesheet"
        href="style.css"
    >


    <style>

        .progress-page {
            min-height: 100vh;
            background: #faf9f5;
            color: #102a43;
        }


        /* =========================================================
           HEADER
        ========================================================= */

        .platform-header {
            width: 100%;
            padding: 38px 5vw;

            display: flex;
            align-items: center;
            justify-content: space-between;

            box-sizing: border-box;
        }

        .platform-logo img {
            width: 105px;
            display: block;
        }

        .platform-nav {
            display: flex;
            align-items: center;
            gap: 28px;
        }

        .platform-label {
            color: #008892;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 4px;
        }

        .back-link {
            color: #102a43;
            text-decoration: none;
            font-size: 15px;
            font-weight: 700;
        }

        .back-link:hover {
            color: #008892;
        }


        /* =========================================================
           INTRO
        ========================================================= */

        .progress-intro {
            max-width: 1280px;
            margin: 0 auto;

            padding: 115px 5vw 120px;
        }

        .progress-eyebrow {
            color: #008892;

            font-size: 12px;
            font-weight: 700;

            letter-spacing: 4px;

            margin-bottom: 30px;
        }

        .progress-intro h1 {
            max-width: 950px;

            margin: 0;

            font-size: clamp(
                56px,
                7vw,
                94px
            );

            line-height: .94;

            letter-spacing: -4px;

            font-weight: 750;
        }

        .progress-intro h1 span {
            color: #008892;
        }

        .progress-intro p {
            max-width: 700px;

            margin-top: 35px;

            font-size: 19px;

            line-height: 1.65;

            color: #526777;
        }


        /* =========================================================
           MAIN
        ========================================================= */

        .progress-section {
            max-width: 1280px;

            margin: 0 auto;

            padding: 0 5vw 140px;
        }

        .section-heading {
            padding: 22px 0;

            border-top: 1px solid #dedbd2;

            border-bottom: 1px solid #dedbd2;

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 30px;
        }

        .section-heading span:first-child {
            color: #008892;

            font-size: 12px;

            font-weight: 700;

            letter-spacing: 4px;
        }

        .section-heading span:last-child {
            color: #718096;

            font-size: 14px;
        }


        /* =========================================================
           OVERVIEW
        ========================================================= */

        .progress-overview {
            display: grid;

            grid-template-columns: 1.1fr .9fr;

            border: 1px solid #d9d5cb;

            background: #fffefa;

            margin-top: 55px;
        }

        .overview-main {
            padding: 48px;

            border-right: 1px solid #dedbd2;
        }

        .overview-label {
            color: #008892;

            font-size: 11px;

            font-weight: 700;

            letter-spacing: 3px;

            margin-bottom: 22px;
        }

        .overview-main h2 {
            margin: 0;

            font-size: 42px;

            letter-spacing: -1.5px;
        }

        .overview-main p {
            max-width: 620px;

            margin: 15px 0 35px;

            color: #526777;

            font-size: 16px;

            line-height: 1.65;
        }


        /* =========================================================
           PROGRESS BAR
        ========================================================= */

        .progress-track {
            width: 100%;

            height: 8px;

            background: #e8e6df;

            overflow: hidden;
        }

        .progress-fill {
            width: <?= $journeyProgress ?>%;

            height: 100%;

            background: #008892;
        }

        .progress-number {
            display: flex;

            justify-content: space-between;

            margin-top: 13px;

            font-size: 13px;

            color: #718096;
        }

        .progress-number strong {
            color: #102a43;
        }


        /* =========================================================
           STATS
        ========================================================= */

        .progress-stats {
            display: grid;

            grid-template-columns: 1fr 1fr;
        }

        .stat {
            padding: 42px 35px;

            display: flex;

            flex-direction: column;

            justify-content: center;
        }

        .stat:nth-child(1) {
            border-bottom: 1px solid #dedbd2;
            border-right: 1px solid #dedbd2;
        }

        .stat:nth-child(2) {
            border-bottom: 1px solid #dedbd2;
        }

        .stat:nth-child(3) {
            border-right: 1px solid #dedbd2;
        }

        .stat-label {
            color: #718096;

            font-size: 12px;

            margin-bottom: 10px;
        }

        .stat-value {
            font-size: 38px;

            font-weight: 750;

            letter-spacing: -1px;
        }


        /* =========================================================
           DEVELOPMENT
        ========================================================= */

        .development-heading {
            margin-top: 90px;
        }

        .development-list {
            border-bottom: 1px solid #dedbd2;
        }

        .development-row {
            display: grid;

            grid-template-columns: 80px 1fr 280px;

            align-items: center;

            gap: 30px;

            padding: 35px 22px;

            border-bottom: 1px solid #dedbd2;
        }

        .development-row:last-child {
            border-bottom: none;
        }

        .development-number {
            color: #c79d4d;

            font-size: 12px;

            font-weight: 700;

            letter-spacing: 3px;
        }

        .development-row h3 {
            margin: 0;

            font-size: 24px;

            letter-spacing: -.6px;
        }

        .development-row p {
            margin: 7px 0 0;

            color: #718096;

            font-size: 14px;

            line-height: 1.5;
        }


        /* =========================================================
           DEVELOPMENT METRICS
        ========================================================= */

        .development-metrics {
            display: flex;

            flex-direction: column;

            gap: 9px;

            min-width: 0;
        }

        .development-metric-top {
            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 15px;
        }

        .development-status {
            color: #718096;

            font-size: 11px;

            font-weight: 700;

            letter-spacing: 1.5px;

            white-space: nowrap;
        }

        .development-score {
            color: #102a43;

            font-size: 13px;

            font-weight: 700;

            white-space: nowrap;
        }

        .development-progress-track {
            width: 100%;

            height: 6px;

            background: #e8e6df;

            overflow: hidden;
        }

        .development-progress-fill {
            height: 100%;

            background: #008892;
        }

        .development-no-evidence
        .development-progress-fill {
            width: 0;
        }


        /* =========================================================
           CALLOUT
        ========================================================= */

        .progress-callout {
            margin-top: 65px;

            padding: 45px 50px;

            background: #e5f5f4;

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 40px;
        }

        .progress-callout h2 {
            margin: 0;

            font-size: 28px;

            letter-spacing: -.7px;
        }

        .progress-callout p {
            max-width: 750px;

            margin: 12px 0 0;

            color: #526777;

            font-size: 15px;

            line-height: 1.6;
        }

        .callout-mark {
            width: 65px;

            height: 65px;

            flex-shrink: 0;

            border: 1px solid #d4ad5b;

            border-radius: 50%;

            display: grid;

            place-items: center;

            color: #c79d4d;

            font-size: 25px;
        }


        /* =========================================================
           FOOTER
        ========================================================= */

        .platform-footer {
            max-width: 1280px;

            margin: 0 auto;

            padding: 35px 5vw 45px;

            border-top: 1px solid #dedbd2;

            display: flex;

            justify-content: space-between;

            color: #718096;

            font-size: 14px;

            box-sizing: border-box;
        }

        .platform-footer strong {
            color: #102a43;
        }

        .dashboard-link {
            color: #526777;

            text-decoration: none;
        }

        .dashboard-link:hover {
            color: #008892;
        }


        /* =========================================================
           MOBILE
        ========================================================= */

        @media (max-width: 800px) {

            .platform-header {
                padding: 25px 24px;
            }

            .platform-label {
                display: none;
            }

            .progress-intro {
                padding: 80px 24px 90px;
            }

            .progress-intro h1 {
                font-size: clamp(
                    48px,
                    14vw,
                    72px
                );

                letter-spacing: -3px;
            }

            .progress-section {
                padding: 0 24px 90px;
            }

            .progress-overview {
                grid-template-columns: 1fr;
            }

            .overview-main {
                border-right: none;

                border-bottom: 1px solid #dedbd2;
            }

            .development-row {
                grid-template-columns:
                    45px
                    1fr;

                gap: 18px;
            }

            .development-metrics {
                grid-column: 2;
            }

            .development-metric-top {
                align-items: flex-start;

                flex-direction: column;

                gap: 7px;
            }

            .progress-callout {
                padding: 32px 25px;
            }

            .callout-mark {
                display: none;
            }

            .platform-footer {
                padding: 30px 24px;

                flex-direction: column;

                gap: 15px;
            }

        }

    </style>

</head>


<body>

<div class="progress-page">


    <!-- =========================================================
         HEADER
    ========================================================= -->

    <header class="platform-header">

        <a
            href="index.html"
            class="platform-logo"
        >

            <img
                src="assets/airaut-logo.png"
                alt="AIraut"
            >

        </a>


        <div class="platform-nav">

            <span class="platform-label">
                STUDENT
            </span>

            <a
                href="student-dashboard.php"
                class="back-link"
            >
                ← Student Dashboard
            </a>

        </div>

    </header>


    <!-- =========================================================
         INTRO
    ========================================================= -->

    <section class="progress-intro">

        <div class="progress-eyebrow">
            MY PROGRESS
        </div>

        <h1>
            See your
            <span>journey.</span>
        </h1>

        <p>
            Your progress brings together the experiences,
            activities and development you are building along
            your readiness journey.
        </p>

    </section>


    <!-- =========================================================
         PROGRESS
    ========================================================= -->

    <section class="progress-section">


        <div class="section-heading">

            <span>
                YOUR DEVELOPMENT
            </span>

            <span>
                A view of your journey so far
            </span>

        </div>


        <!-- =====================================================
             OVERVIEW
        ====================================================== -->

        <div class="progress-overview">


            <div class="overview-main">

                <div class="overview-label">
                    OVERALL JOURNEY
                </div>


                <h2>

                    <?php if ($totalActivities === 0): ?>

                        Your journey is ready to begin.

                    <?php elseif ($totalReviewed === 0): ?>

                        Your journey is underway.

                    <?php else: ?>

                        Your journey is building through experience.

                    <?php endif; ?>

                </h2>


                <p>

                    Progress here is based on activities assigned
                    to your cohort, your submissions and the reviews
                    completed by your faculty.

                </p>


                <div class="progress-track">

                    <div class="progress-fill"></div>

                </div>


                <div class="progress-number">

                    <span>
                        Reviewed journey progress
                    </span>

                    <strong>
                        <?= $journeyProgress ?>%
                    </strong>

                </div>

            </div>


            <div class="progress-stats">


                <div class="stat">

                    <div class="stat-label">
                        EXPERIENCES
                    </div>

                    <div class="stat-value">
                        <?= $totalActivities ?>
                    </div>

                </div>


                <div class="stat">

                    <div class="stat-label">
                        SUBMISSIONS
                    </div>

                    <div class="stat-value">
                        <?= $totalSubmissions ?>
                    </div>

                </div>


                <div class="stat">

                    <div class="stat-label">
                        REVIEWED
                    </div>

                    <div class="stat-value">
                        <?= $totalReviewed ?>
                    </div>

                </div>


                <div class="stat">

                    <div class="stat-label">
                        AVERAGE SCORE
                    </div>

                    <div class="stat-value">

                        <?= $averageScore !== null
                            ? htmlspecialchars(
                                number_format(
                                    $averageScore,
                                    2
                                )
                            )
                            : '—'
                        ?>

                    </div>

                </div>


            </div>

        </div>


        <!-- =====================================================
             FIVE C FRAMEWORK
        ====================================================== -->

        <div class="development-heading">

            <div class="section-heading">

                <span>
                    FIVE C FRAMEWORK
                </span>

                <span>
                    Evidence from your experiences
                </span>

            </div>

        </div>


        <div class="development-list">


            <?php if (count($developmentAreas) > 0): ?>


                <?php foreach (
                    $developmentAreas as
                    $index => $area
                ): ?>


                    <?php

                    $number =
                        str_pad(
                            (string) ($index + 1),
                            2,
                            '0',
                            STR_PAD_LEFT
                        );

                    $activityCount =
                        (int) $area['activity_count'];

                    $reviewedCount =
                        (int) $area['reviewed_count'];

                    $evidenceProgress =
                        $area['evidence_progress'];

                    ?>


                    <div class="development-row">


                        <div class="development-number">

                            <?= $number ?>

                        </div>


                        <div>

                            <h3>
                                <?= htmlspecialchars(
                                    $area['name']
                                ) ?>
                            </h3>

                            <p>
                                <?= htmlspecialchars(
                                    $area['description'] ?? ''
                                ) ?>
                            </p>

                        </div>


                        <div class="development-metrics">


                            <div class="development-metric-top">


                                <span class="development-status">

                                    <?= htmlspecialchars(
                                        development_status(
                                            $activityCount,
                                            $reviewedCount
                                        )
                                    ) ?>

                                </span>


                                <?php if (
                                    $area['average_score'] !== null
                                ): ?>

                                    <span class="development-score">

                                        Avg.
                                        <?= number_format(
                                            (float) $area['average_score'],
                                            2
                                        ) ?>/10

                                    </span>

                                <?php endif; ?>


                            </div>


                            <?php if (
                                $evidenceProgress !== null
                            ): ?>

                                <div
                                    class="development-progress-track"
                                    aria-label="<?= $evidenceProgress ?>% evidence progress"
                                >

                                    <div
                                        class="development-progress-fill"
                                        style="width: <?= (int) $evidenceProgress ?>%;"
                                    ></div>

                                </div>


                                <div
                                    style="
                                        display:flex;
                                        justify-content:flex-end;
                                        margin-top:4px;
                                        color:#718096;
                                        font-size:12px;
                                    "
                                >

                                    <?= (int) $evidenceProgress ?>%
                                    evidence progress

                                </div>


                            <?php endif; ?>


                        </div>


                    </div>


                <?php endforeach; ?>


            <?php else: ?>


                <div
                    style="
                        padding:45px 22px;
                        color:#718096;
                    "
                >

                    No development areas have been configured yet.

                </div>


            <?php endif; ?>


        </div>


        <!-- =====================================================
             CALLOUT
        ====================================================== -->

        <div class="progress-callout">

            <div>

                <h2>
                    Progress is more than a number.
                </h2>

                <p>
                    Your journey brings together what you submit,
                    what your faculty observes and how your work is
                    evaluated over time. Development-area progress
                    reflects the evidence currently connected to
                    your activities.
                </p>

            </div>


            <div class="callout-mark">
                +
            </div>

        </div>


    </section>

</main>


<!-- =========================================================
     FOOTER
========================================================= -->

<footer class="platform-footer">

    <div>

        <strong>
            AIraut
        </strong>

        — Future Readiness Development System

    </div>


    <a
        href="student-dashboard.php"
        class="dashboard-link"
    >

        ← Student Dashboard

    </a>

</footer>


</div>

</body>

</html>
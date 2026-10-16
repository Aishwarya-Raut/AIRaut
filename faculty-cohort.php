<?php

require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/config/database.php';

require_role('faculty');

$userId = current_user_id();


/*
|--------------------------------------------------------------------------
| FIND LOGGED-IN FACULTY
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "SELECT
        f.id,
        f.institution_id,
        f.faculty_code,
        f.designation,
        u.name,
        u.email
     FROM faculty f
     INNER JOIN users u
        ON u.id = f.user_id
     WHERE f.user_id = ?
     LIMIT 1"
);

if (!$stmt) {
    http_response_code(500);
    exit('Unable to load faculty profile.');
}

$stmt->bind_param(
    "i",
    $userId
);

$stmt->execute();

$faculty = $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$faculty) {
    http_response_code(404);
    exit('Faculty profile not found.');
}

$facultyId = (int) $faculty['id'];

$facultyName = $faculty['name'];


/*
|--------------------------------------------------------------------------
| ACTIVE ASSIGNED COHORTS
|--------------------------------------------------------------------------
*/

$cohorts = [];

$stmt = $conn->prepare(
    "SELECT
        c.id,
        c.name,
        c.start_date,
        c.end_date,
        c.status,
        p.name AS programme_name

     FROM faculty_cohorts fc

     INNER JOIN cohorts c
        ON c.id = fc.cohort_id

     LEFT JOIN programmes p
        ON p.id = c.programme_id

     WHERE fc.faculty_id = ?
       AND c.status = 'active'

     ORDER BY
        c.start_date ASC,
        c.id ASC"
);

if (!$stmt) {
    http_response_code(500);
    exit('Unable to load assigned cohorts.');
}

$stmt->bind_param(
    "i",
    $facultyId
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $cohorts[] = $row;
}

$stmt->close();

$activeCohortCount = count($cohorts);


/*
|--------------------------------------------------------------------------
| LEARNERS IN ASSIGNED COHORTS
|--------------------------------------------------------------------------
|
| One row per active student.
|
| Progress:
| submitted active activities / active activities
|
| Review status:
| submitted but not yet reviewed = Needs review
|--------------------------------------------------------------------------
*/

$learners = [];

$stmt = $conn->prepare(
    "SELECT

        s.id AS student_id,
        s.cohort_id,
        c.name AS cohort_name,

        u.name AS student_name,
        u.email AS student_email,

        s.student_code,
        s.course,
        s.academic_year,

        COUNT(DISTINCT a.id)
            AS activity_count,

        COUNT(DISTINCT CASE
            WHEN sub.status IN ('submitted', 'reviewed')
            THEN sub.id
        END) AS submission_count,

        COUNT(DISTINCT CASE
            WHEN sub.status = 'reviewed'
            THEN sub.id
        END) AS reviewed_count,

        COUNT(DISTINCT CASE
            WHEN sub.status = 'submitted'
            THEN sub.id
        END) AS pending_review_count

     FROM students s

     INNER JOIN users u
        ON u.id = s.user_id

     INNER JOIN cohorts c
        ON c.id = s.cohort_id
       AND c.status = 'active'

     INNER JOIN faculty_cohorts fc
        ON fc.cohort_id = c.id
       AND fc.faculty_id = ?

     LEFT JOIN activities a
        ON a.cohort_id = c.id
       AND a.status = 'active'

     LEFT JOIN submissions sub
        ON sub.activity_id = a.id
       AND sub.student_id = s.id

     WHERE s.status = 'active'

     GROUP BY
        s.id,
        s.cohort_id,
        c.name,
        u.name,
        u.email,
        s.student_code,
        s.course,
        s.academic_year

     ORDER BY
        c.name ASC,
        u.name ASC"
);

if (!$stmt) {
    http_response_code(500);
    exit('Unable to load learners.');
}

$stmt->bind_param(
    "i",
    $facultyId
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    $activityCount =
        (int) $row['activity_count'];

    $submissionCount =
        (int) $row['submission_count'];

    $reviewedCount =
        (int) $row['reviewed_count'];

    $pendingReviewCount =
        (int) $row['pending_review_count'];


    /*
    |--------------------------------------------------------------------------
    | LEARNER PROGRESS
    |--------------------------------------------------------------------------
    */

    $progress = 0;

    if ($activityCount > 0) {

        $progress = min(
            100,
            round(
                (
                    $submissionCount
                    / $activityCount
                ) * 100
            )
        );
    }


    /*
    |--------------------------------------------------------------------------
    | LEARNER STATUS
    |--------------------------------------------------------------------------
    */

    if ($pendingReviewCount > 0) {

        $statusLabel =
            'Needs review';

        $statusClass =
            'status-review';

    } elseif (
        $progress >= 70
    ) {

        $statusLabel =
            'On track';

        $statusClass =
            'status-active';

    } elseif (
        $progress > 0
    ) {

        $statusLabel =
            'In progress';

        $statusClass =
            'status-review';

    } else {

        $statusLabel =
            'Not started';

        $statusClass =
            'status-review';
    }


    $row['progress'] =
        $progress;

    $row['status_label'] =
        $statusLabel;

    $row['status_class'] =
        $statusClass;


    $learners[] =
        $row;
}

$stmt->close();


/*
|--------------------------------------------------------------------------
| COHORT / SYSTEM SUMMARY
|--------------------------------------------------------------------------
*/

$totalStudents =
    count($learners);

$totalProgress = 0;

$totalPendingReviews = 0;

$totalSubmissions = 0;

$totalReviewed = 0;


foreach (
    $learners as $learner
) {

    $totalProgress +=
        (int) $learner['progress'];

    $totalPendingReviews +=
        (int) $learner['pending_review_count'];

    $totalSubmissions +=
        (int) $learner['submission_count'];

    $totalReviewed +=
        (int) $learner['reviewed_count'];
}


$averageProgress =
    $totalStudents > 0

        ? round(
            $totalProgress
            / $totalStudents
        )

        : 0;


/*
|--------------------------------------------------------------------------
| ACTIVE COHORT DISPLAY
|--------------------------------------------------------------------------
*/

$cohortDisplayNames = [];

foreach (
    $cohorts as $cohort
) {

    $cohortDisplayNames[] =
        htmlspecialchars(
            $cohort['name']
        );
}


/*
|--------------------------------------------------------------------------
| SUMMARY LABEL
|--------------------------------------------------------------------------
*/

$learnerSummaryLabel =
    $totalStudents === 1
        ? '1 learner'
        : $totalStudents . ' learners';

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
        My Cohort | AIraut
    </title>

    <link
        rel="stylesheet"
        href="style.css"
    >

    <style>

        /* =========================================================
           FACULTY COHORT — LIVE BACKEND ADDITIONS
        ========================================================= */

        .cohort-empty {
            padding: 45px 0;
            color: #718096;
            font-size: 15px;
            line-height: 1.6;
        }

        .cohort-names {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .cohort-tag {
            display: inline-block;

            padding: 7px 12px;

            border: 1px solid #d9ddd9;

            background: #f5f8f7;

            color: #123454;

            font-size: 13px;

            line-height: 1.3;
        }

        .learner-empty {
            padding: 50px 25px;

            border-bottom: 1px solid #d9ddd9;

            color: #718096;

            text-align: center;

            font-size: 15px;
        }

        .learner-action.disabled {
            color: #a4adb5;
            pointer-events: none;
        }

        .learner-meta {
            margin-top: 7px;

            color: #7a8a98;

            font-size: 12px;

            line-height: 1.5;
        }

        .cohort-stat strong {
            display: block;
        }

        .status-review {
            color: #c88c1a;
        }

        @media (max-width: 850px) {

            .cohort-names {
                margin-top: 12px;
            }

        }

    </style>

</head>


<body>


<!-- =========================================================
     HEADER
========================================================== -->

<header class="site-header">


    <a
        href="faculty-dashboard.php"
        class="brand"
    >

        <img
            src="assets/airaut-logo.png"
            alt="AIraut"
        >

    </a>


    <div class="header-right">


        <span class="role-label">
            FACULTY
        </span>


        <a
            href="faculty-dashboard.php"
            class="back-link"
        >
            ← Faculty Dashboard
        </a>


    </div>


</header>



<!-- =========================================================
     MAIN
========================================================== -->

<main>


    <!-- =====================================================
         PAGE HERO
    ====================================================== -->

    <section class="faculty-page-hero">


        <div class="faculty-page-label">
            MY COHORT
        </div>


        <h1>

            Know your learners.<br>

            <span>
                Guide their journey.
            </span>

        </h1>


        <p>

            View your assigned learners, understand their participation,
            and follow their progress through the AIraut programme.

        </p>


    </section>



    <!-- =====================================================
         COHORT OVERVIEW
    ====================================================== -->

    <section class="cohort-overview">


        <div class="section-heading-row">


            <div>

                <span class="section-label">
                    COHORT
                </span>


                <?php if (
                    $activeCohortCount > 0
                ): ?>


                    <h2>

                        <?= implode(
                            ' · ',
                            $cohortDisplayNames
                        ) ?>

                    </h2>


                <?php else: ?>


                    <h2>
                        No active cohort assigned.
                    </h2>


                <?php endif; ?>


            </div>


            <span class="section-note">

                Current cohort overview

            </span>


        </div>



        <div class="cohort-summary">


            <div class="cohort-description">


                <?php if (
                    $activeCohortCount > 0
                ): ?>


                    <p>

                        Your assigned learners and their journey
                        through the AIraut programme.

                    </p>


                <?php else: ?>


                    <p>

                        No active cohort is currently assigned
                        to your faculty account.

                    </p>


                <?php endif; ?>


            </div>



            <div class="cohort-stat">


                <strong>
                    <?= $totalStudents ?>
                </strong>


                <span>
                    Students
                </span>


            </div>



            <div class="cohort-stat">


                <strong>
                    <?= $averageProgress ?>%
                </strong>


                <span>
                    Average Progress
                </span>


            </div>



            <div class="cohort-stat">


                <strong>
                    <?= $totalPendingReviews ?>
                </strong>


                <span>
                    Awaiting Review
                </span>


            </div>


        </div>


    </section>



    <!-- =====================================================
         LEARNERS
    ====================================================== -->

    <section class="learners-section">


        <div class="section-heading-row">


            <div>

                <span class="section-label">
                    LEARNERS
                </span>


                <h2>
                    Your students
                </h2>


            </div>


            <span class="section-note">

                <?= htmlspecialchars(
                    $learnerSummaryLabel
                ) ?>

            </span>


        </div>



        <div class="learner-list">


            <?php if (
                count($learners) > 0
            ): ?>


                <?php foreach (
                    $learners as
                    $index => $learner
                ): ?>


                    <article class="learner-row">


                        <!-- NUMBER -->

                        <div class="learner-number">

                            <?= str_pad(
                                (string) ($index + 1),
                                2,
                                '0',
                                STR_PAD_LEFT
                            ) ?>

                        </div>



                        <!-- STUDENT -->

                        <div class="learner-info">


                            <h3>

                                <?= htmlspecialchars(
                                    $learner['student_name']
                                ) ?>

                            </h3>


                            <p>

                                Student ·

                                <?= htmlspecialchars(
                                    $learner['cohort_name']
                                ) ?>

                            </p>


                            <?php if (
                                !empty(
                                    $learner['student_code']
                                )
                            ): ?>


                                <div class="learner-meta">

                                    <?= htmlspecialchars(
                                        $learner['student_code']
                                    ) ?>

                                </div>


                            <?php endif; ?>


                        </div>



                        <!-- PROGRESS -->

                        <div class="learner-progress">


                            <strong>

                                <?= (int) $learner['progress'] ?>%

                            </strong>


                            <span>
                                Progress
                            </span>


                        </div>



                        <!-- STATUS -->

                        <div
                            class="learner-status <?= htmlspecialchars(
                                $learner['status_class']
                            ) ?>"
                        >

                            <?= htmlspecialchars(
                                $learner['status_label']
                            ) ?>

                        </div>



                        <!-- ACTION -->

                        <a
                            href="faculty-student.php?student_id=<?= (int) $learner['student_id'] ?>"
                            class="learner-action"
                        >
                            View →
                        </a>


                    </article>


                <?php endforeach; ?>


            <?php else: ?>


                <div class="learner-empty">

                    No active learners are currently assigned
                    to your active cohort(s).

                </div>


            <?php endif; ?>


        </div>


    </section>



    <!-- =====================================================
         WHAT FACULTY CAN SEE
    ====================================================== -->

    <section class="cohort-support">


        <div class="support-intro">


            <span class="section-label">
                FACULTY VIEW
            </span>


            <h2>

                See where learners are<br>

                <span>
                    in their journey.
                </span>

            </h2>


            <p>

                Follow participation, progress and submitted work
                so you can guide learners at the right moments.

            </p>


        </div>



        <div class="support-list">


            <div class="support-row">


                <span>
                    01
                </span>


                <strong>
                    Participation
                </strong>


                <p>
                    See who is engaging with the programme.
                </p>


            </div>



            <div class="support-row">


                <span>
                    02
                </span>


                <strong>
                    Progress
                </strong>


                <p>

                    Understand how learners are moving through
                    their future readiness journey.

                </p>


            </div>



            <div class="support-row">


                <span>
                    03
                </span>


                <strong>
                    Student Work
                </strong>


                <p>
                    Review submitted activities and reflections.
                </p>


            </div>



            <div class="support-row">


                <span>
                    04
                </span>


                <strong>
                    Feedback
                </strong>


                <p>
                    Respond with feedback that supports development.
                </p>


            </div>


        </div>


    </section>



    <!-- =====================================================
         NEXT STEP
    ====================================================== -->

    <section class="faculty-next-section">


        <div>


            <span class="section-label">
                NEXT
            </span>


            <h2>
                Review student work.
            </h2>


            <p>

                See submitted activities and identify what
                needs your attention.

            </p>


        </div>


        <a
            href="faculty-submissions.php"
            class="next-action"
        >
            Student Submissions →
        </a>


    </section>


</main>



<!-- =========================================================
     FOOTER
========================================================== -->

<footer class="site-footer">


    <div>

        <strong>
            AIraut
        </strong>


        <span>
            — Future Readiness Development System
        </span>


    </div>


    <a
        href="faculty-dashboard.php"
    >
        ← Faculty Dashboard
    </a>


</footer>
</body>

</html>
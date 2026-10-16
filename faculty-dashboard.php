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

$cohortNames = [];

$stmt = $conn->prepare(
    "SELECT
        c.id,
        c.name,
        c.start_date,
        c.end_date
     FROM faculty_cohorts fc

     INNER JOIN cohorts c
        ON c.id = fc.cohort_id

     WHERE fc.faculty_id = ?
       AND c.status = 'active'

     ORDER BY
        c.start_date ASC,
        c.id ASC"
);

if (!$stmt) {
    http_response_code(500);
    exit('Unable to load faculty cohorts.');
}

$stmt->bind_param(
    "i",
    $facultyId
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $cohortNames[] = $row;
}

$stmt->close();

$activeCohortCount = count($cohortNames);


/*
|--------------------------------------------------------------------------
| LEARNER PROGRESS
|--------------------------------------------------------------------------
|
| One row per active student in the faculty's active cohorts.
|
*/

$studentProgressRows = [];

$stmt = $conn->prepare(
    "SELECT

        s.id AS student_id,

        c.id AS cohort_id,
        c.name AS cohort_name,

        COUNT(DISTINCT a.id)
            AS activity_count,

        COUNT(DISTINCT sub.id)
            AS submission_count,

        COUNT(
            DISTINCT CASE
                WHEN sub.status = 'reviewed'
                THEN sub.id
            END
        ) AS reviewed_count

     FROM faculty_cohorts fc

     INNER JOIN cohorts c
        ON c.id = fc.cohort_id
       AND c.status = 'active'

     LEFT JOIN students s
        ON s.cohort_id = c.id
       AND s.status = 'active'

     LEFT JOIN activities a
        ON a.cohort_id = c.id
       AND a.status = 'active'

     LEFT JOIN submissions sub
        ON sub.activity_id = a.id
       AND sub.student_id = s.id

     WHERE fc.faculty_id = ?

     GROUP BY
        s.id,
        c.id,
        c.name

     ORDER BY
        c.name ASC,
        s.id ASC"
);

if (!$stmt) {
    http_response_code(500);
    exit('Unable to load learner progress.');
}

$stmt->bind_param(
    "i",
    $facultyId
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    /*
     * LEFT JOIN means a cohort can exist without students.
     * Ignore those empty cohort rows.
     */

    if ($row['student_id'] === null) {
        continue;
    }


    $activityCount =
        (int) $row['activity_count'];

    $submissionCount =
        (int) $row['submission_count'];


    /*
     * Progress is based on submitted active activities.
     */

    $progress =
        $activityCount > 0

            ? min(
                100,
                round(
                    (
                        $submissionCount
                        / $activityCount
                    ) * 100
                )
            )

            : 0;


    $row['progress'] =
        $progress;


    $studentProgressRows[] =
        $row;
}

$stmt->close();


/*
|--------------------------------------------------------------------------
| FACULTY DASHBOARD COUNTS
|--------------------------------------------------------------------------
*/

$activeStudents =
    count($studentProgressRows);


$averageProgress = 0;

if ($activeStudents > 0) {

    $progressTotal = 0;

    foreach (
        $studentProgressRows as $row
    ) {

        $progressTotal +=
            (int) $row['progress'];
    }


    $averageProgress =
        round(
            $progressTotal
            / $activeStudents
        );
}


/*
|--------------------------------------------------------------------------
| PENDING REVIEWS
|--------------------------------------------------------------------------
*/

$pendingReviews = 0;

$stmt = $conn->prepare(
    "SELECT COUNT(DISTINCT sub.id)

     FROM submissions sub

     INNER JOIN students s
        ON s.id = sub.student_id
       AND s.status = 'active'

     INNER JOIN cohorts c
        ON c.id = s.cohort_id
       AND c.status = 'active'

     INNER JOIN faculty_cohorts fc
        ON fc.cohort_id = c.id
       AND fc.faculty_id = ?

     INNER JOIN activities a
        ON a.id = sub.activity_id
       AND a.status = 'active'

     WHERE sub.status = 'submitted'"
);

if (!$stmt) {
    http_response_code(500);
    exit('Unable to load pending reviews.');
}

$stmt->bind_param(
    "i",
    $facultyId
);

$stmt->execute();

$stmt->bind_result(
    $pendingReviews
);

$stmt->fetch();

$stmt->close();

$pendingReviews =
    (int) $pendingReviews;


/*
|--------------------------------------------------------------------------
| RECENT SUBMISSIONS AWAITING REVIEW
|--------------------------------------------------------------------------
*/

$attentionSubmissions = [];

$stmt = $conn->prepare(
    "SELECT

        sub.id AS submission_id,
        sub.submitted_at,

        a.title AS activity_title,

        u.name AS student_name

     FROM submissions sub

     INNER JOIN students s
        ON s.id = sub.student_id
       AND s.status = 'active'

     INNER JOIN users u
        ON u.id = s.user_id

     INNER JOIN cohorts c
        ON c.id = s.cohort_id
       AND c.status = 'active'

     INNER JOIN faculty_cohorts fc
        ON fc.cohort_id = c.id
       AND fc.faculty_id = ?

     INNER JOIN activities a
        ON a.id = sub.activity_id
       AND a.status = 'active'

     WHERE sub.status = 'submitted'

     ORDER BY
        sub.submitted_at DESC,
        sub.id DESC

     LIMIT 3"
);

if (!$stmt) {
    http_response_code(500);
    exit('Unable to load recent submissions.');
}

$stmt->bind_param(
    "i",
    $facultyId
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $attentionSubmissions[] =
        $row;
}

$stmt->close();


/*
|--------------------------------------------------------------------------
| DISPLAY HELPER
|--------------------------------------------------------------------------
*/

function faculty_progress_label(int $progress): string
{
    if ($progress >= 70) {
        return 'ON TRACK';
    }

    if ($progress > 0) {
        return 'IN PROGRESS';
    }

    return 'NOT STARTED';
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
        Faculty Dashboard | AIraut
    </title>

    <link
        rel="stylesheet"
        href="style.css"
    >

</head>


<body>


<!-- =========================================================
     HEADER
========================================================== -->

<header class="site-header">

    <a
        href="platform.html"
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
     MAIN
========================================================== -->

<main>


    <!-- =====================================================
         DASHBOARD INTRO
    ====================================================== -->

    <section class="dashboard-hero">

        <div class="dashboard-eyebrow">
            FACULTY DASHBOARD
        </div>

        <h1>

            Guide learning.<br>

            <span>
                See progress.
            </span>

        </h1>

        <p class="dashboard-intro">

            Welcome,
            <?= htmlspecialchars($facultyName) ?>.

            Support meaningful learning experiences,
            review student work, and see how your cohort
            is progressing through AIraut.

        </p>

    </section>



    <!-- =====================================================
         COHORT SNAPSHOT
    ====================================================== -->

    <section class="dashboard-section">

        <div class="section-heading">

            <span>
                MY COHORT
            </span>

            <small>
                Current cohort overview
            </small>

        </div>


        <div class="cohort-overview">


            <div class="cohort-main">

                <span class="small-label">
                    ASSIGNED COHORTS
                </span>


                <?php if ($activeCohortCount > 0): ?>


                    <h2>

                        <?php

                        $displayNames = [];

                        foreach (
                            $cohortNames as $cohort
                        ) {

                            $displayNames[] =
                                htmlspecialchars(
                                    $cohort['name']
                                );
                        }


                        echo implode(
                            ' · ',
                            $displayNames
                        );

                        ?>

                    </h2>


                    <p>

                        Your assigned learners and their journey
                        through the AIraut programme.

                    </p>


                <?php else: ?>


                    <h2>
                        No active cohort assigned.
                    </h2>


                    <p>

                        Your active AIraut cohort assignments
                        will appear here once they are configured.

                    </p>


                <?php endif; ?>


            </div>



            <div class="cohort-stats">


                <div class="stat-item">

                    <strong>
                        <?= $activeStudents ?>
                    </strong>

                    <span>
                        Students
                    </span>

                </div>



                <div class="stat-item">

                    <strong>
                        <?= $averageProgress ?>%
                    </strong>

                    <span>
                        Average Progress
                    </span>

                </div>



                <div class="stat-item">

                    <strong>
                        <?= $pendingReviews ?>
                    </strong>

                    <span>
                        Awaiting Review
                    </span>

                </div>


            </div>


        </div>

    </section>



    <!-- =====================================================
         FACULTY ACTIONS
    ====================================================== -->

    <section class="dashboard-section">

        <div class="section-heading">

            <span>
                FACULTY WORKSPACE
            </span>

            <small>
                What would you like to do?
            </small>

        </div>


        <div class="faculty-actions">


            <!-- MY COHORT -->

            <a
                href="faculty-cohort.php"
                class="faculty-action"
            >

                <div class="action-number">
                    01
                </div>

                <div class="action-content">

                    <h2>
                        My Cohort
                    </h2>

                    <p>

                        View your learners and their participation
                        across the programme.

                    </p>

                </div>

                <div class="action-arrow">
                    →
                </div>

            </a>



            <!-- SUBMISSIONS -->

            <a
                href="faculty-submissions.php"
                class="faculty-action"
            >

                <div class="action-number">
                    02
                </div>

                <div class="action-content">

                    <h2>
                        Student Submissions
                    </h2>

                    <p>

                        See work submitted by students and identify
                        what needs your attention.

                    </p>

                </div>

                <div class="action-arrow">
                    →
                </div>

            </a>



            <!-- REVIEW -->

            <a
                href="faculty-submissions.php"
                class="faculty-action"
            >

                <div class="action-number">
                    03
                </div>

                <div class="action-content">

                    <h2>
                        Review &amp; Feedback
                    </h2>

                    <p>

                        Review student work and provide meaningful
                        feedback to support development.

                    </p>

                </div>

                <div class="action-arrow">
                    →
                </div>

            </a>



            <!-- COMPLETION -->

            <a
                href="faculty-progress.php"
                class="faculty-action"
            >

                <div class="action-number">
                    04
                </div>

                <div class="action-content">

                    <h2>
                        Completion
                    </h2>

                    <p>

                        See participation and completion across
                        your assigned cohorts.

                    </p>

                </div>

                <div class="action-arrow">
                    →
                </div>

            </a>



            <!-- PROGRESS -->

            <a
                href="faculty-progress.php"
                class="faculty-action"
            >

                <div class="action-number">
                    05
                </div>

                <div class="action-content">

                    <h2>
                        Progress
                    </h2>

                    <p>

                        Understand how learners are progressing
                        through their future readiness journey.

                    </p>

                </div>

                <div class="action-arrow">
                    →
                </div>

            </a>


        </div>

    </section>



    <!-- =====================================================
         NEEDS ATTENTION
    ====================================================== -->

    <section class="dashboard-section attention-section">

        <div class="section-heading">

            <span>
                NEEDS YOUR ATTENTION
            </span>

            <small>
                Current student activity
            </small>

        </div>


        <div class="attention-list">


            <?php if (
                count($attentionSubmissions) > 0
            ): ?>


                <?php foreach (
                    $attentionSubmissions as
                    $index => $submission
                ): ?>


                    <div class="attention-row">


                        <div class="attention-number">

                            <?= str_pad(
                                (string) ($index + 1),
                                2,
                                '0',
                                STR_PAD_LEFT
                            ) ?>

                        </div>


                        <div class="attention-info">

                            <strong>

                                <?= htmlspecialchars(
                                    $submission['student_name']
                                ) ?>

                            </strong>


                            <span>

                                <?= htmlspecialchars(
                                    $submission['activity_title']
                                ) ?>

                                · Awaiting review

                            </span>

                        </div>


                        <span class="attention-status">
                            REVIEW
                        </span>


                        <a
                            href="faculty-review.php?submission_id=<?= (int) $submission['submission_id'] ?>"
                            class="row-arrow"
                        >
                            →
                        </a>


                    </div>


                <?php endforeach; ?>


            <?php else: ?>


                <div class="attention-row">


                    <div class="attention-number">
                        01
                    </div>


                    <div class="attention-info">

                        <strong>
                            No pending reviews
                        </strong>

                        <span>

                            All currently submitted student work
                            has been reviewed.

                        </span>

                    </div>


                    <span class="attention-status">
                        CLEAR
                    </span>


                    <a
                        href="faculty-progress.php"
                        class="row-arrow"
                    >
                        →
                    </a>


                </div>


            <?php endif; ?>


            <div class="attention-row">


                <div class="attention-number">

                    <?= count($attentionSubmissions) > 0

                        ? str_pad(
                            (string) (
                                count(
                                    $attentionSubmissions
                                ) + 1
                            ),
                            2,
                            '0',
                            STR_PAD_LEFT
                        )

                        : '02'
                    ?>

                </div>


                <div class="attention-info">

                    <strong>
                        Cohort participation
                    </strong>

                    <span>

                        View current learner participation
                        and progress.

                    </span>

                </div>


                <span class="attention-status">
                    VIEW
                </span>


                <a
                    href="faculty-progress.php"
                    class="row-arrow"
                >
                    →
                </a>


            </div>


        </div>

    </section>



    <!-- =====================================================
         FACULTY PRINCIPLE
    ====================================================== -->

    <section class="faculty-principle">

        <span class="section-label">
            THE FACULTY ROLE
        </span>

        <h2>

            Facilitate the experience.<br>

            <span>
                Make learning meaningful.
            </span>

        </h2>

        <p>

            AIraut supports faculty in guiding learners through
            experiences, reflection, feedback and application.

        </p>

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


    <a href="platform.html">
        ← Platform
    </a>


</footer>
</body>

</html>
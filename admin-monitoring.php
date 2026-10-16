<?php

require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/config/database.php';

require_role('admin');


/*
|--------------------------------------------------------------------------
| SYSTEM SNAPSHOT
|--------------------------------------------------------------------------
*/

/* Active students */

$stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM students
    WHERE status = 'active'
");

$stmt->execute();
$stmt->bind_result($activeStudents);
$stmt->fetch();
$stmt->close();

$activeStudents = (int) $activeStudents;


/* Active cohorts */

$stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM cohorts
    WHERE status = 'active'
");

$stmt->execute();
$stmt->bind_result($activeCohorts);
$stmt->fetch();
$stmt->close();

$activeCohorts = (int) $activeCohorts;


/* Active programmes */

$stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM programmes
    WHERE status = 'active'
");

$stmt->execute();
$stmt->bind_result($activeProgrammes);
$stmt->fetch();
$stmt->close();


/*
|--------------------------------------------------------------------------
| STUDENT PARTICIPATION
|--------------------------------------------------------------------------
|
| Active students who have submitted at least one activity
| divided by all active students.
|
*/

$participatingStudents = 0;

$stmt = $conn->prepare("
    SELECT COUNT(DISTINCT s.id)
    FROM students s

    INNER JOIN submissions sub
        ON sub.student_id = s.id

    WHERE s.status = 'active'
");

$stmt->execute();
$stmt->bind_result($participatingStudents);
$stmt->fetch();
$stmt->close();

$participatingStudents = (int) $participatingStudents;

$studentParticipation = $activeStudents > 0
    ? round(($participatingStudents / $activeStudents) * 100)
    : 0;


/*
|--------------------------------------------------------------------------
| ACTIVITY COMPLETION
|--------------------------------------------------------------------------
|
| Submitted activity instances divided by the number of
| possible active activity instances across active students.
|
*/

$totalPossibleActivityInstances = 0;
$totalCompletedActivityInstances = 0;


/* Possible */

$stmt = $conn->prepare("
    SELECT
        COUNT(*)
    FROM students s

    INNER JOIN cohorts c
        ON c.id = s.cohort_id
        AND c.status = 'active'

    INNER JOIN activities a
        ON a.cohort_id = c.id
        AND a.status = 'active'

    WHERE s.status = 'active'
");

$stmt->execute();
$stmt->bind_result($totalPossibleActivityInstances);
$stmt->fetch();
$stmt->close();

$totalPossibleActivityInstances =
    (int) $totalPossibleActivityInstances;


/* Completed / submitted */

$stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM submissions sub

    INNER JOIN students s
        ON s.id = sub.student_id
        AND s.status = 'active'

    INNER JOIN activities a
        ON a.id = sub.activity_id
        AND a.status = 'active'

    INNER JOIN cohorts c
        ON c.id = a.cohort_id
        AND c.status = 'active'

    WHERE sub.status IN ('submitted', 'reviewed')
");

$stmt->execute();
$stmt->bind_result($totalCompletedActivityInstances);
$stmt->fetch();
$stmt->close();

$totalCompletedActivityInstances =
    (int) $totalCompletedActivityInstances;

$activityCompletion = $totalPossibleActivityInstances > 0
    ? round(
        (
            $totalCompletedActivityInstances
            / $totalPossibleActivityInstances
        ) * 100
    )
    : 0;


/*
|--------------------------------------------------------------------------
| FACULTY REVIEW
|--------------------------------------------------------------------------
*/

$pendingReviews = 0;

$stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM submissions sub

    INNER JOIN students s
        ON s.id = sub.student_id
        AND s.status = 'active'

    INNER JOIN activities a
        ON a.id = sub.activity_id
        AND a.status = 'active'

    WHERE sub.status = 'submitted'
");

$stmt->execute();
$stmt->bind_result($pendingReviews);
$stmt->fetch();
$stmt->close();

$pendingReviews = (int) $pendingReviews;


/*
|--------------------------------------------------------------------------
| ONBOARDING INSTITUTIONS
|--------------------------------------------------------------------------
*/

$onboardingInstitutions = 0;

$stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM institutions
    WHERE status = 'onboarding'
");

$stmt->execute();
$stmt->bind_result($onboardingInstitutions);
$stmt->fetch();
$stmt->close();


/*
|--------------------------------------------------------------------------
| PROGRAMME PROGRESS
|--------------------------------------------------------------------------
*/

$programmeRows = [];

$sql = "
    SELECT
        p.id,
        p.name,
        p.code,
        p.status,

        COUNT(DISTINCT CASE
            WHEN c.status = 'active'
            THEN c.id
        END) AS cohort_count,

        COUNT(DISTINCT CASE
            WHEN s.status = 'active'
            THEN s.id
        END) AS student_count,

        COUNT(DISTINCT CASE
            WHEN c.status = 'active'
            AND a.status = 'active'
            THEN a.id
        END) AS activity_count,

        COUNT(sub.id) AS submission_count

    FROM programmes p

    LEFT JOIN cohorts c
        ON c.programme_id = p.id

    LEFT JOIN students s
        ON s.cohort_id = c.id

    LEFT JOIN activities a
        ON a.cohort_id = c.id

    LEFT JOIN submissions sub
        ON sub.activity_id = a.id
        AND sub.student_id = s.id
        AND sub.status IN ('submitted', 'reviewed')

    GROUP BY
        p.id,
        p.name,
        p.code,
        p.status

    ORDER BY
        CASE
            WHEN p.status = 'active' THEN 1
            WHEN p.status = 'draft' THEN 2
            ELSE 3
        END,
        p.name ASC
";

$result = $conn->query($sql);

if ($result) {

    while ($row = $result->fetch_assoc()) {

        /*
        |--------------------------------------------------------------------------
        | Calculate programme progress
        |--------------------------------------------------------------------------
        |
        | Possible activity instances =
        | active students × active activities
        |
        */

        $students = (int) $row['student_count'];
        $activities = (int) $row['activity_count'];
        $submissions = (int) $row['submission_count'];

        $possible = $students * $activities;

        $progress = $possible > 0
            ? round(($submissions / $possible) * 100)
            : 0;

        if ($progress > 100) {
            $progress = 100;
        }

        $row['progress'] = $progress;

        $programmeRows[] = $row;
    }
}


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function status_class(string $status): string
{
    if ($status === 'active') {
        return 'status-active';
    }

    if ($status === 'draft') {
        return 'status-pending';
    }

    return 'status-inactive';
}

function status_label(string $status): string
{
    return ucfirst($status);
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

    <title>Monitoring · Admin · AIraut</title>

    <link
        rel="stylesheet"
        href="style.css"
    >

    <style>

        .admin-monitor-card {
            min-height: 220px;
        }

        .monitor-progress {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .monitor-progress strong {
            min-width: 42px;
        }

        .progress-track {
            width: 100%;
            max-width: 150px;
            height: 6px;
            background: #e4e9ea;
            overflow: hidden;
        }

        .progress-track span {
            display: block;
            height: 100%;
            background: #1687a7;
        }

        .monitor-empty {
            padding: 50px 24px;
            text-align: center;
            color: #66809a;
        }

        .admin-monitor-table .admin-table-row {
            grid-template-columns:
                1.6fr
                .7fr
                .8fr
                1.2fr
                .8fr;
        }

        @media (max-width: 900px) {

            .admin-monitor-table {
                min-width: 760px;
            }

            .admin-table-wrap {
                overflow-x: auto;
            }

        }

    </style>

</head>


<body>


<!-- HEADER -->

<header class="site-header">

    <div class="header-inner">


        <a
            href="admin-dashboard.php"
            class="brand"
        >

            <img
                src="assets/airaut-logo.png"
                alt="AIraut"
            >

        </a>


        <div class="header-context">

            <span>
                SYSTEM MANAGEMENT
            </span>

        </div>


        <nav class="header-nav">

            <span class="role-label">
                ADMIN
            </span>

            <a href="admin-dashboard.php">
                Dashboard
            </a>

        </nav>


    </div>

</header>



<!-- MAIN -->

<main class="page-shell">


    <!-- HERO -->

    <section class="admin-page-hero">


        <div class="admin-page-hero-copy">

            <div class="eyebrow">
                SYSTEM MONITORING
            </div>

            <h1>
                Monitoring.
            </h1>

            <p>
                Track participation, programme progress and
                activity across the AIraut system.
            </p>

        </div>


        <a
            href="admin-dashboard.php"
            class="secondary-button"
        >
            Dashboard →
        </a>


    </section>



    <!-- SYSTEM SNAPSHOT -->

    <section class="admin-stat-strip">


        <div class="admin-stat">

            <span class="stat-label">
                ACTIVE STUDENTS
            </span>

            <strong>
                <?= number_format($activeStudents) ?>
            </strong>

        </div>


        <div class="admin-stat">

            <span class="stat-label">
                ACTIVE COHORTS
            </span>

            <strong>
                <?= str_pad(
                    (string) $activeCohorts,
                    2,
                    '0',
                    STR_PAD_LEFT
                ) ?>
            </strong>

        </div>


        <div class="admin-stat">

            <span class="stat-label">
                ACTIVE PROGRAMMES
            </span>

            <strong>
                <?= str_pad(
                    (string) $activeProgrammes,
                    2,
                    '0',
                    STR_PAD_LEFT
                ) ?>
            </strong>

        </div>


    </section>



    <!-- PARTICIPATION -->

    <section class="admin-monitor-section">


        <div class="admin-section-heading">


            <div>

                <div class="eyebrow">
                    PARTICIPATION
                </div>

                <h2>
                    System activity.
                </h2>

            </div>


            <span class="admin-section-note">
                Current programme cycle
            </span>


        </div>



        <div class="admin-monitor-grid">


            <!-- STUDENT PARTICIPATION -->

            <article class="admin-monitor-card">


                <div class="monitor-card-top">

                    <span class="monitor-label">
                        STUDENT PARTICIPATION
                    </span>

                    <span
                        class="monitor-status <?= $studentParticipation >= 70
                            ? 'status-active'
                            : 'status-pending' ?>"
                    >

                        <?= $studentParticipation >= 70
                            ? 'ON TRACK'
                            : 'ATTENTION' ?>

                    </span>

                </div>


                <strong class="monitor-value">
                    <?= $studentParticipation ?>%
                </strong>


                <p>
                    Active students who have submitted at least
                    one activity.
                </p>


            </article>



            <!-- ACTIVITY COMPLETION -->

            <article class="admin-monitor-card">


                <div class="monitor-card-top">

                    <span class="monitor-label">
                        ACTIVITY COMPLETION
                    </span>

                    <span
                        class="monitor-status <?= $activityCompletion >= 70
                            ? 'status-active'
                            : 'status-pending' ?>"
                    >

                        <?= $activityCompletion >= 70
                            ? 'ON TRACK'
                            : 'ATTENTION' ?>

                    </span>

                </div>


                <strong class="monitor-value">
                    <?= $activityCompletion ?>%
                </strong>


                <p>
                    Assigned activities completed across
                    active cohorts.
                </p>


            </article>



            <!-- FACULTY REVIEW -->

            <article class="admin-monitor-card">


                <div class="monitor-card-top">

                    <span class="monitor-label">
                        FACULTY REVIEW
                    </span>


                    <span
                        class="monitor-status <?= $pendingReviews > 0
                            ? 'status-pending'
                            : 'status-active' ?>"
                    >

                        <?= $pendingReviews > 0
                            ? 'ATTENTION'
                            : 'CLEAR' ?>

                    </span>

                </div>


                <strong class="monitor-value">
                    <?= number_format($pendingReviews) ?>
                </strong>


                <p>
                    Student submissions currently awaiting
                    faculty review.
                </p>


            </article>



            <!-- ONBOARDING -->

            <article class="admin-monitor-card">


                <div class="monitor-card-top">

                    <span class="monitor-label">
                        ONBOARDING INSTITUTIONS
                    </span>


                    <span
                        class="monitor-status <?= $onboardingInstitutions > 0
                            ? 'status-pending'
                            : 'status-active' ?>"
                    >

                        <?= $onboardingInstitutions > 0
                            ? 'IN PROGRESS'
                            : 'CLEAR' ?>

                    </span>

                </div>


                <strong class="monitor-value">
                    <?= number_format($onboardingInstitutions) ?>
                </strong>


                <p>
                    Institutions currently in the AIraut
                    onboarding stage.
                </p>


            </article>


        </div>

    </section>



    <!-- PROGRAMME PROGRESS -->

    <section class="admin-monitor-section">


        <div class="admin-section-heading">


            <div>

                <div class="eyebrow">
                    PROGRAMME PROGRESS
                </div>

                <h2>
                    Where programmes stand.
                </h2>

            </div>


            <a
                href="admin-programmes.php"
                class="text-link"
            >
                Programmes →
            </a>


        </div>



        <div class="admin-table-wrap">


            <div class="admin-table admin-monitor-table">


                <!-- HEADER -->

                <div
                    class="admin-table-row admin-table-header"
                >

                    <div>
                        PROGRAMME
                    </div>

                    <div>
                        COHORTS
                    </div>

                    <div>
                        STUDENTS
                    </div>

                    <div>
                        PROGRESS
                    </div>

                    <div>
                        STATUS
                    </div>

                </div>



                <?php if (count($programmeRows) > 0): ?>


                    <?php foreach ($programmeRows as $programme): ?>


                        <div class="admin-table-row">


                            <div class="table-primary">

                                <strong>
                                    <?= htmlspecialchars(
                                        $programme['name']
                                    ) ?>
                                </strong>

                                <span>
                                    <?= htmlspecialchars(
                                        $programme['code']
                                    ) ?>
                                </span>

                            </div>


                            <div>

                                <?= str_pad(
                                    (string) (
                                        (int) $programme[
                                            'cohort_count'
                                        ]
                                    ),
                                    2,
                                    '0',
                                    STR_PAD_LEFT
                                ) ?>

                            </div>


                            <div>

                                <?= number_format(
                                    (int) $programme[
                                        'student_count'
                                    ]
                                ) ?>

                            </div>


                            <div class="monitor-progress">


                                <div class="progress-track">

                                    <span
                                        style="width: <?= (int) $programme['progress'] ?>%;"
                                    ></span>

                                </div>


                                <strong>
                                    <?= (int) $programme['progress'] ?>%
                                </strong>


                            </div>


                            <div>

                                <span
                                    class="status-badge <?= status_class(
                                        $programme['status']
                                    ) ?>"
                                >

                                    <?= htmlspecialchars(
                                        status_label(
                                            $programme['status']
                                        )
                                    ) ?>

                                </span>

                            </div>


                        </div>


                    <?php endforeach; ?>


                <?php else: ?>


                    <div class="monitor-empty">

                        No programmes have been added yet.

                    </div>


                <?php endif; ?>


            </div>

        </div>


    </section>



    <!-- ATTENTION -->

    <section class="admin-next-step">


        <div>

            <div class="eyebrow">
                ATTENTION REQUIRED
            </div>

            <h2>
                Review what needs action.
            </h2>

            <p>
                Monitor pending reviews, participation and
                institutions currently moving through onboarding.
            </p>

        </div>


        <a
            href="admin-students.php"
            class="secondary-button"
        >
            Review Students →
        </a>


    </section>


</main>



<!-- FOOTER -->

<footer class="site-footer">


    <div>

        <strong>
            AIraut
        </strong>

        <span>
            Future Readiness Development System
        </span>

    </div>


    <div>
        ADMIN · MONITORING
    </div>


</footer>
</body>

</html>
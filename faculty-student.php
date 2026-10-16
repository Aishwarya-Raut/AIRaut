<?php

require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/config/database.php';

require_role('faculty');

$userId = current_user_id();


/* =========================================================
   GET STUDENT ID
========================================================= */

$studentId = isset($_GET['student_id'])
    ? (int) $_GET['student_id']
    : 0;

if ($studentId <= 0) {
    http_response_code(400);
    exit('Invalid student.');
}


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
   FIND STUDENT
   AND VERIFY FACULTY ACCESS
========================================================= */

$stmt = $conn->prepare(
    "SELECT
        st.id,
        st.user_id,
        st.cohort_id,
        st.student_code,
        st.course,
        st.academic_year,
        u.name AS student_name,
        u.email AS student_email,
        c.name AS cohort_name

     FROM students st

     INNER JOIN users u
        ON u.id = st.user_id

     INNER JOIN cohorts c
        ON c.id = st.cohort_id

     INNER JOIN faculty_cohorts fc
        ON fc.cohort_id = st.cohort_id
       AND fc.faculty_id = ?

     WHERE st.id = ?
       AND st.status = 'active'

     LIMIT 1"
);

$stmt->bind_param(
    "ii",
    $facultyId,
    $studentId
);

$stmt->execute();

$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    http_response_code(403);
    exit('You are not authorized to view this student.');
}


/* =========================================================
   ACTIVE ACTIVITIES
========================================================= */

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM activities
     WHERE cohort_id = ?
       AND status = 'active'"
);

$stmt->bind_param(
    "i",
    $student['cohort_id']
);

$stmt->execute();

$totalActivities = (int) (
    $stmt->get_result()->fetch_assoc()['total'] ?? 0
);

$stmt->close();


/* =========================================================
   STUDENT SUBMISSIONS + REVIEWS
========================================================= */

$stmt = $conn->prepare(
    "SELECT
        s.id AS submission_id,
        s.response,
        s.status AS submission_status,
        s.submitted_at,

        a.id AS activity_id,
        a.title AS activity_title,
        a.description AS activity_description,
        a.activity_type,
        a.question,

        r.id AS review_id,
        r.score,
        r.feedback,
        r.observation,
        r.reviewed_at

     FROM submissions s

     INNER JOIN activities a
        ON a.id = s.activity_id

     LEFT JOIN reviews r
        ON r.submission_id = s.id

     WHERE s.student_id = ?

     ORDER BY s.submitted_at DESC"
);

$stmt->bind_param(
    "i",
    $studentId
);

$stmt->execute();

$submissions = $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$stmt->close();


/* =========================================================
   METRICS
========================================================= */

$totalSubmissions = count($submissions);

$totalReviewed = 0;

$scoreTotal = 0;
$scoreCount = 0;

foreach ($submissions as $submission) {

    if (!empty($submission['review_id'])) {
        $totalReviewed++;

        if ($submission['score'] !== null) {
            $scoreTotal += (float) $submission['score'];
            $scoreCount++;
        }
    }
}

$averageScore = $scoreCount > 0
    ? round($scoreTotal / $scoreCount, 2)
    : null;

$progress = $totalActivities > 0
    ? min(
        100,
        round(
            ($totalSubmissions / $totalActivities) * 100
        )
    )
    : 0;


/* =========================================================
   STATUS
========================================================= */

if ($totalSubmissions === 0) {

    $studentStatus = 'Needs attention';
    $studentStatusClass = 'attention';

} elseif ($totalReviewed < $totalSubmissions) {

    $studentStatus = 'Review pending';
    $studentStatusClass = 'pending';

} else {

    $studentStatus = 'On track';
    $studentStatusClass = 'on-track';
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
        <?= htmlspecialchars($student['student_name']) ?>
        — Faculty View · AIraut
    </title>

    <style>

        /* =========================================================
           AIraut — Faculty Student Detail
        ========================================================= */

        :root {
            --navy: #123454;
            --teal: #079bb0;
            --gold: #d99a18;
            --muted: #55789a;
            --line: #d9dedf;
            --paper: #fbfaf6;
            --white: #fffefa;
        }


        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }


        body {
            font-family: Arial, Helvetica, sans-serif;
            background: var(--paper);
            color: var(--navy);
            line-height: 1.5;
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

            display: block;
        }


        .header-right {
            display: flex;

            align-items: center;

            gap: 34px;

            font-size: 18px;

            font-weight: 700;
        }


        .role {
            color: var(--teal);

            letter-spacing: 6px;

            font-size: 15px;
        }


        .back {
            color: var(--navy);
        }


        .back:hover {
            color: var(--teal);
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


        .hero h1 {
            max-width: 1050px;

            font-size: clamp(
                60px,
                7vw,
                108px
            );

            line-height: .96;

            letter-spacing: -5px;

            font-weight: 800;
        }


        .hero h1 span {
            color: var(--teal);
        }


        .hero-description {
            max-width: 760px;

            margin-top: 30px;

            color: var(--muted);

            font-size: 21px;

            line-height: 1.6;
        }


        /* =========================================================
           PROFILE
        ========================================================= */

        .profile-section {
            padding: 100px 0;

            border-bottom: 1px solid var(--line);
        }


        .section-label {
            color: var(--teal);

            letter-spacing: 7px;

            font-size: 15px;

            font-weight: 700;

            margin-bottom: 25px;
        }


        .profile-grid {
            display: grid;

            grid-template-columns: 1.2fr .8fr;

            border-top: 1px solid var(--line);

            border-bottom: 1px solid var(--line);

            background: var(--white);
        }


        .profile-main {
            padding: 48px;

            border-right: 1px solid var(--line);
        }


        .student-name {
            font-size: 42px;

            line-height: 1.05;

            letter-spacing: -1.5px;

            margin-bottom: 12px;
        }


        .student-meta {
            color: var(--muted);

            font-size: 17px;

            line-height: 1.7;
        }


        .profile-side {
            display: grid;

            grid-template-columns: 1fr 1fr;
        }


        .profile-stat {
            padding: 36px 30px;

            border-bottom: 1px solid var(--line);
        }


        .profile-stat:nth-child(odd) {
            border-right: 1px solid var(--line);
        }


        .profile-stat:nth-child(3),
        .profile-stat:nth-child(4) {
            border-bottom: none;
        }


        .profile-stat-label {
            color: var(--muted);

            font-size: 12px;

            margin-bottom: 10px;
        }


        .profile-stat-value {
            font-size: 34px;

            font-weight: 800;

            letter-spacing: -1px;
        }


        .status-chip {
            display: inline-block;

            margin-top: 12px;

            padding: 7px 12px;

            border-radius: 50px;

            font-size: 10px;

            font-weight: 700;

            letter-spacing: 2px;
        }


        .status-chip.on-track {
            color: var(--teal);

            border: 1px solid var(--teal);
        }


        .status-chip.pending {
            color: var(--gold);

            border: 1px solid var(--gold);
        }


        .status-chip.attention {
            color: #8a5c00;

            border: 1px solid var(--gold);
        }


        /* =========================================================
           PROGRESS
        ========================================================= */

        .progress-section {
            padding: 105px 0;

            border-bottom: 1px solid var(--line);
        }


        .progress-grid {
            display: grid;

            grid-template-columns: 1fr 1fr;

            gap: 80px;

            align-items: end;
        }


        .progress-title {
            font-size: 54px;

            line-height: 1.05;

            letter-spacing: -2px;
        }


        .progress-copy {
            color: var(--muted);

            font-size: 19px;

            line-height: 1.6;

            max-width: 600px;
        }


        .progress-bar {
            margin-top: 50px;

            height: 9px;

            background: #e8e6df;

            overflow: hidden;
        }


        .progress-fill {
            width: <?= $progress ?>%;

            height: 100%;

            background: var(--teal);
        }


        .progress-meta {
            margin-top: 12px;

            display: flex;

            justify-content: space-between;

            color: var(--muted);

            font-size: 14px;
        }


        .progress-meta strong {
            color: var(--navy);
        }


        /* =========================================================
           SUBMISSIONS
        ========================================================= */

        .submission-section {
            padding: 110px 0;

            border-bottom: 1px solid var(--line);
        }


        .submission-heading {
            display: flex;

            justify-content: space-between;

            align-items: flex-end;

            gap: 30px;

            padding-bottom: 28px;

            border-bottom: 1px solid var(--line);
        }


        .submission-heading-title {
            font-size: 56px;

            line-height: 1;

            letter-spacing: -3px;
        }


        .submission-heading-copy {
            color: var(--muted);

            font-size: 18px;
        }


        .submission-list {
            border-top: 1px solid var(--line);

            margin-top: 45px;
        }


        .submission-card {
            border-bottom: 1px solid var(--line);

            background: var(--white);
        }


        .submission-card-header {
            padding: 35px 32px;

            display: grid;

            grid-template-columns:
                65px
                1fr
                170px;

            gap: 30px;

            align-items: start;
        }


        .submission-number {
            color: var(--teal);

            font-size: 14px;

            font-weight: 700;

            letter-spacing: 4px;
        }


        .submission-type {
            color: var(--teal);

            font-size: 12px;

            font-weight: 700;

            letter-spacing: 4px;

            margin-bottom: 10px;
        }


        .submission-card h3 {
            font-size: 29px;

            line-height: 1.1;

            letter-spacing: -.8px;
        }


        .submission-date {
            color: var(--muted);

            font-size: 14px;

            margin-top: 8px;
        }


        .review-state {
            justify-self: end;

            padding: 8px 12px;

            border-radius: 50px;

            font-size: 10px;

            font-weight: 700;

            letter-spacing: 2px;

            white-space: nowrap;
        }


        .review-state.reviewed {
            color: var(--teal);

            border: 1px solid var(--teal);
        }


        .review-state.pending {
            color: var(--gold);

            border: 1px solid var(--gold);
        }


        .submission-body {
            padding: 0 32px 38px 127px;
        }


        .submission-label {
            color: var(--teal);

            font-size: 11px;

            font-weight: 700;

            letter-spacing: 3px;

            margin-bottom: 10px;
        }


        .question-box,
        .response-box,
        .review-box {
            padding: 20px;

            border: 1px solid var(--line);

            background: #fbfaf7;

            color: var(--navy);

            font-size: 16px;

            line-height: 1.7;
        }


        .response-block,
        .review-block {
            margin-top: 28px;
        }


        .review-box {
            background: #e8f6f5;
        }


        .score-line {
            margin-top: 16px;

            font-size: 22px;

            font-weight: 800;
        }


        .score-line span {
            color: var(--muted);

            font-size: 15px;

            font-weight: 600;
        }


        /* =========================================================
           EMPTY
        ========================================================= */

        .empty-state {
            padding: 75px 30px;

            text-align: center;

            border-bottom: 1px solid var(--line);
        }


        .empty-state h3 {
            font-size: 30px;

            margin-bottom: 12px;
        }


        .empty-state p {
            max-width: 600px;

            margin: 0 auto;

            color: var(--muted);

            font-size: 17px;

            line-height: 1.6;
        }


        /* =========================================================
           FOOTER
        ========================================================= */

        footer {
            padding: 38px 0 48px;

            display: flex;

            justify-content: space-between;

            gap: 30px;

            color: var(--muted);

            font-size: 15px;
        }


        footer strong {
            color: var(--navy);
        }


        footer a:hover {
            color: var(--teal);
        }


        /* =========================================================
           MOBILE
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


            .role {
                display: none;
            }


            .header-right {
                font-size: 14px;
            }


            .hero {
                padding: 70px 0 90px;
            }


            .hero h1 {
                font-size: 62px;

                letter-spacing: -3px;
            }


            .profile-grid,
            .progress-grid {
                grid-template-columns: 1fr;
            }


            .profile-main {
                border-right: none;

                border-bottom: 1px solid var(--line);
            }


            .submission-card-header {
                grid-template-columns:
                    45px
                    1fr;
            }


            .review-state {
                grid-column: 2;

                justify-self: start;
            }


            .submission-body {
                padding: 0 32px 32px 109px;
            }

        }


        @media (max-width: 600px) {

            .logo {
                width: 115px;
            }


            .back {
                font-size: 14px;
            }


            .hero h1 {
                font-size: 50px;

                letter-spacing: -2.5px;
            }


            .hero-description {
                font-size: 18px;
            }


            .profile-main {
                padding: 30px;
            }


            .profile-side {
                grid-template-columns: 1fr;
            }


            .profile-stat {
                border-right: none !important;

                border-bottom: 1px solid var(--line) !important;
            }


            .profile-stat:last-child {
                border-bottom: none !important;
            }


            .progress-title {
                font-size: 42px;
            }


            .submission-heading {
                align-items: flex-start;

                flex-direction: column;
            }


            .submission-card-header {
                padding: 28px 20px;
            }


            .submission-body {
                padding: 0 20px 28px 20px;
            }


            .submission-card h3 {
                font-size: 25px;
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

        <a
            href="faculty-progress.php"
            aria-label="Faculty progress"
        >

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
                href="faculty-progress.php"
                class="back"
            >
                ← Faculty Progress
            </a>

        </div>

    </header>


    <!-- =========================================================
         HERO
    ========================================================= -->

    <section class="hero">

        <div class="eyebrow">
            LEARNER PROFILE
        </div>


        <h1>

            <?= htmlspecialchars(
                $student['student_name']
            ) ?>

            <span>
                journey.
            </span>

        </h1>


        <p class="hero-description">

            Review this learner's participation,
            submitted work and faculty evaluations across
            <?= htmlspecialchars($student['cohort_name']) ?>.

        </p>

    </section>


    <!-- =========================================================
         PROFILE
    ========================================================= -->

    <section class="profile-section">

        <div class="section-label">
            STUDENT OVERVIEW
        </div>


        <div class="profile-grid">


            <div class="profile-main">

                <div class="student-name">

                    <?= htmlspecialchars(
                        $student['student_name']
                    ) ?>

                </div>


                <div class="student-meta">

                    Cohort:
                    <strong>
                        <?= htmlspecialchars(
                            $student['cohort_name']
                        ) ?>
                    </strong>

                    <br>

                    Student code:
                    <strong>
                        <?= htmlspecialchars(
                            $student['student_code']
                        ) ?>
                    </strong>

                    <br>

                    Course:
                    <strong>
                        <?= htmlspecialchars(
                            $student['course']
                        ) ?>
                    </strong>

                    <br>

                    Academic year:
                    <strong>
                        <?= htmlspecialchars(
                            $student['academic_year']
                        ) ?>
                    </strong>

                </div>


                <span
                    class="status-chip <?= $studentStatusClass ?>"
                >
                    <?= htmlspecialchars($studentStatus) ?>
                </span>

            </div>


            <div class="profile-side">


                <div class="profile-stat">

                    <div class="profile-stat-label">
                        ACTIVITIES
                    </div>

                    <div class="profile-stat-value">
                        <?= $totalActivities ?>
                    </div>

                </div>


                <div class="profile-stat">

                    <div class="profile-stat-label">
                        SUBMISSIONS
                    </div>

                    <div class="profile-stat-value">
                        <?= $totalSubmissions ?>
                    </div>

                </div>


                <div class="profile-stat">

                    <div class="profile-stat-label">
                        REVIEWED
                    </div>

                    <div class="profile-stat-value">
                        <?= $totalReviewed ?>
                    </div>

                </div>


                <div class="profile-stat">

                    <div class="profile-stat-label">
                        AVERAGE SCORE
                    </div>

                    <div class="profile-stat-value">

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

    </section>


    <!-- =========================================================
         PROGRESS
    ========================================================= -->

    <section class="progress-section">

        <div class="section-label">
            JOURNEY PROGRESS
        </div>


        <div class="progress-grid">

            <h2 class="progress-title">

                <?= $progress ?>%
                of assigned activities submitted.

            </h2>


            <p class="progress-copy">

                This progress view is calculated from the active
                activities assigned to the learner's cohort and
                the work they have submitted.

            </p>

        </div>


        <div class="progress-bar">

            <div class="progress-fill"></div>

        </div>


        <div class="progress-meta">

            <span>
                Submission progress
            </span>

            <strong>
                <?= $totalSubmissions ?>
                / <?= $totalActivities ?>
            </strong>

        </div>

    </section>


    <!-- =========================================================
         SUBMISSIONS
    ========================================================= -->

    <section class="submission-section">


        <div class="section-label">
            LEARNER WORK
        </div>


        <div class="submission-heading">

            <h2 class="submission-heading-title">
                Submitted experiences.
            </h2>


            <p class="submission-heading-copy">
                Work and evaluations from this learner's journey.
            </p>

        </div>


        <div class="submission-list">


            <?php if (empty($submissions)): ?>


                <div class="empty-state">

                    <h3>
                        No submissions yet.
                    </h3>


                    <p>

                        This learner has not submitted any
                        activities yet.

                    </p>

                </div>


            <?php else: ?>


                <?php foreach ($submissions as $index => $submission): ?>


                    <article class="submission-card">


                        <!-- HEADER -->

                        <div class="submission-card-header">


                            <div class="submission-number">

                                <?= str_pad(
                                    $index + 1,
                                    2,
                                    '0',
                                    STR_PAD_LEFT
                                ) ?>

                            </div>


                            <div>


                                <div class="submission-type">

                                    <?= htmlspecialchars(
                                        strtoupper(
                                            $submission['activity_type']
                                            ?? 'ACTIVITY'
                                        )
                                    ) ?>

                                </div>


                                <h3>

                                    <?= htmlspecialchars(
                                        $submission['activity_title']
                                    ) ?>

                                </h3>


                                <?php if (
                                    !empty(
                                        $submission['submitted_at']
                                    )
                                ): ?>

                                    <div class="submission-date">

                                        Submitted on
                                        <?= htmlspecialchars(
                                            $submission['submitted_at']
                                        ) ?>

                                    </div>

                                <?php endif; ?>


                            </div>


                            <div
                                class="review-state <?= empty(
                                    $submission['review_id']
                                )
                                    ? 'pending'
                                    : 'reviewed'
                                ?>"
                            >

                                <?= empty(
                                    $submission['review_id']
                                )
                                    ? 'AWAITING REVIEW'
                                    : 'REVIEWED'
                                ?>

                            </div>


                        </div>


                        <!-- BODY -->

                        <div class="submission-body">


                            <!-- QUESTION -->

                            <div>

                                <div class="submission-label">
                                    ACTIVITY QUESTION
                                </div>


                                <div class="question-box">

                                    <?= htmlspecialchars(
                                        $submission['question']
                                        ?? ''
                                    ) ?>

                                </div>

                            </div>


                            <!-- RESPONSE -->

                            <div class="response-block">

                                <div class="submission-label">
                                    STUDENT RESPONSE
                                </div>


                                <div class="response-box">

                                    <?= nl2br(
                                        htmlspecialchars(
                                            $submission['response']
                                        )
                                    ) ?>

                                </div>

                            </div>


                            <?php if (
                                !empty(
                                    $submission['review_id']
                                )
                            ): ?>


                                <!-- REVIEW -->

                                <div class="review-block">

                                    <div class="submission-label">
                                        FACULTY REVIEW
                                    </div>


                                    <div class="review-box">

                                        <?php if (
                                            $submission['score']
                                            !== null
                                        ): ?>

                                            <div class="score-line">

                                                Score:
                                                <?= htmlspecialchars(
                                                    number_format(
                                                        (float)
                                                        $submission['score'],
                                                        2
                                                    )
                                                ) ?>

                                                <span>
                                                    / 10
                                                </span>

                                            </div>

                                        <?php endif; ?>


                                        <?php if (
                                            trim(
                                                (string)
                                                $submission['observation']
                                            ) !== ''
                                        ): ?>

                                            <br>

                                            <strong>
                                                Observation
                                            </strong>

                                            <br><br>

                                            <?= nl2br(
                                                htmlspecialchars(
                                                    $submission['observation']
                                                )
                                            ) ?>

                                        <?php endif; ?>


                                        <?php if (
                                            trim(
                                                (string)
                                                $submission['feedback']
                                            ) !== ''
                                        ): ?>

                                            <br><br>

                                            <strong>
                                                Feedback
                                            </strong>

                                            <br><br>

                                            <?= nl2br(
                                                htmlspecialchars(
                                                    $submission['feedback']
                                                )
                                            ) ?>

                                        <?php endif; ?>


                                        <?php if (
                                            !empty(
                                                $submission['reviewed_at']
                                            )
                                        ): ?>

                                            <br><br>

                                            <small>

                                                Reviewed on
                                                <?= htmlspecialchars(
                                                    $submission['reviewed_at']
                                                ) ?>

                                            </small>

                                        <?php endif; ?>

                                    </div>

                                </div>


                            <?php endif; ?>


                        </div>


                    </article>


                <?php endforeach; ?>


            <?php endif; ?>


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


        <a href="faculty-progress.php">
            ← Faculty Progress
        </a>

    </footer>


</div>

</body>

</html>
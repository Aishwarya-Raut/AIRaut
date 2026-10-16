<?php

require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/config/database.php';

require_role('faculty');

$userId = current_user_id();


/* =========================================================
   FIND LOGGED-IN FACULTY
========================================================= */

$stmt = $conn->prepare(
    "SELECT
        id,
        institution_id
     FROM faculty
     WHERE user_id = ?
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

$faculty =
    $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$faculty) {
    http_response_code(404);
    exit('Faculty profile not found.');
}

$facultyId =
    (int) $faculty['id'];


/* =========================================================
   FIND SUBMISSIONS FOR FACULTY'S ASSIGNED COHORTS
========================================================= */

$stmt = $conn->prepare(
    "SELECT

        s.id AS submission_id,
        s.response,
        s.submitted_at,

        a.id AS activity_id,
        a.title AS activity_title,
        a.description AS activity_description,
        a.activity_type,

        st.id AS student_id,
        u.name AS student_name,

        r.id AS review_id

     FROM submissions s

     INNER JOIN activities a
        ON a.id = s.activity_id

     INNER JOIN students st
        ON st.id = s.student_id
       AND st.status = 'active'

     INNER JOIN users u
        ON u.id = st.user_id

     INNER JOIN faculty_cohorts fc
        ON fc.cohort_id = st.cohort_id
       AND fc.faculty_id = ?

     LEFT JOIN reviews r
        ON r.submission_id = s.id

     ORDER BY

        CASE
            WHEN r.id IS NULL THEN 0
            ELSE 1
        END ASC,

        s.submitted_at DESC,

        s.id DESC"
);

if (!$stmt) {
    http_response_code(500);
    exit('Unable to load student submissions.');
}

$stmt->bind_param(
    "i",
    $facultyId
);

$stmt->execute();

$submissions =
    $stmt->get_result()->fetch_all(
        MYSQLI_ASSOC
    );

$stmt->close();


/* =========================================================
   COUNTS
========================================================= */

$pendingCount = 0;
$reviewedCount = 0;


foreach (
    $submissions as $submission
) {

    if (
        empty(
            $submission['review_id']
        )
    ) {

        $pendingCount++;

    } else {

        $reviewedCount++;
    }
}


$totalCount =
    count($submissions);

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
        Review &amp; Feedback — AIraut
    </title>

    <link
        rel="stylesheet"
        href="style.css"
    >


    <style>

        /* =========================================================
           AIraut — Faculty Review & Feedback
        ========================================================= */

        .page-shell {
            min-height: 100vh;

            background: #fbfaf7;

            color: #123454;
        }


        /* =========================================================
           HEADER
        ========================================================= */

        .page-header {
            width: 100%;

            padding: 42px 7.2vw 0;

            display: flex;

            align-items: center;

            justify-content: space-between;
        }

        .brand-logo {
            width: 150px;

            height: auto;

            display: block;
        }

        .header-right {
            display: flex;

            align-items: center;

            gap: 38px;

            font-weight: 600;

            font-size: 18px;
        }

        .role-label {
            color: #079db1;

            letter-spacing: 7px;

            font-size: 15px;

            font-weight: 700;
        }

        .back-link {
            color: #123454;

            text-decoration: none;

            transition: opacity 0.2s ease;
        }

        .back-link:hover {
            opacity: 0.6;
        }


        /* =========================================================
           HERO
        ========================================================= */

        .review-hero {
            padding: 105px 7.2vw 125px;

            border-bottom: 1px solid #d9ddd9;
        }

        .eyebrow {
            margin: 0 0 24px;

            color: #079db1;

            font-size: 15px;

            font-weight: 700;

            letter-spacing: 7px;
        }

        .hero-title {
            margin: 0;

            max-width: 900px;

            font-size:
                clamp(60px, 6.4vw, 104px);

            line-height: 0.94;

            letter-spacing: -4px;

            font-weight: 750;

            color: #123454;
        }

        .hero-title span {
            color: #079db1;
        }

        .hero-description {
            margin: 26px 0 0;

            max-width: 820px;

            font-size: 21px;

            line-height: 1.55;

            color: #52769d;
        }


        /* =========================================================
           SUBMISSIONS
        ========================================================= */

        .submissions-section {
            padding: 125px 7.2vw 0;
        }

        .section-heading {
            display: flex;

            justify-content: space-between;

            align-items: flex-end;

            gap: 40px;

            padding-bottom: 28px;

            border-bottom: 1px solid #d9ddd9;
        }

        .section-label {
            margin: 0;

            color: #079db1;

            font-size: 15px;

            font-weight: 700;

            letter-spacing: 7px;
        }

        .section-status {
            margin: 0;

            color: #52769d;

            font-size: 17px;
        }


        /* =========================================================
           SUBMISSION ROW
        ========================================================= */

        .submission-list {
            width: 100%;
        }

        .submission {
            display: grid;

            grid-template-columns:
                55px
                minmax(0, 1fr)
                180px
                145px;

            column-gap: 40px;

            align-items: center;

            min-height: 215px;

            border-bottom: 1px solid #d9ddd9;
        }

        .submission-number {
            color: #079db1;

            font-size: 17px;

            font-weight: 600;

            letter-spacing: 1px;
        }

        .submission-type {
            margin: 0 0 17px;

            color: #079db1;

            font-size: 14px;

            font-weight: 700;

            letter-spacing: 5px;
        }

        .submission-title {
            margin: 0 0 12px;

            color: #123454;

            font-size: 30px;

            line-height: 1.1;

            font-weight: 700;
        }

        .student-name {
            margin: 0 0 8px;

            color: #123454;

            font-size: 18px;

            font-weight: 600;
        }

        .submission-description {
            margin: 0;

            max-width: 680px;

            color: #52769d;

            font-size: 17px;

            line-height: 1.5;
        }

        .submission-status {
            font-size: 17px;

            font-weight: 600;

            text-align: left;
        }

        .submission-status.pending {
            color: #d89200;
        }

        .submission-status.reviewed {
            color: #079db1;
        }

        .review-link {
            justify-self: end;

            color: #123454;

            font-size: 17px;

            font-weight: 700;

            text-decoration: none;

            white-space: nowrap;

            transition:
                color 0.2s ease,
                transform 0.2s ease;
        }

        .review-link:hover {
            color: #079db1;

            transform: translateX(4px);
        }

        .review-link.disabled {
            color: #9aa8b5;

            pointer-events: none;
        }


        /* =========================================================
           EMPTY STATE
        ========================================================= */

        .empty-state {
            padding: 80px 20px;

            border-bottom: 1px solid #d9ddd9;

            text-align: center;
        }

        .empty-state h3 {
            margin: 0;

            color: #123454;

            font-size: 30px;
        }

        .empty-state p {
            max-width: 600px;

            margin: 15px auto 0;

            color: #52769d;

            font-size: 17px;

            line-height: 1.6;
        }


        /* =========================================================
           BOTTOM MESSAGE
        ========================================================= */

        .closing-section {
            padding: 170px 7.2vw 115px;

            border-bottom: 1px solid #d9ddd9;
        }

        .closing-label {
            margin: 0 0 28px;

            color: #079db1;

            font-size: 15px;

            font-weight: 700;

            letter-spacing: 7px;
        }

        .closing-title {
            margin: 0;

            max-width: 1000px;

            color: #123454;

            font-size:
                clamp(48px, 5.5vw, 88px);

            line-height: 0.98;

            letter-spacing: -3px;

            font-weight: 750;
        }

        .closing-title span {
            color: #079db1;
        }

        .closing-description {
            margin: 28px 0 0;

            max-width: 650px;

            color: #52769d;

            font-size: 19px;

            line-height: 1.55;
        }


        /* =========================================================
           FOOTER
        ========================================================= */

        .page-footer {
            padding: 42px 7.2vw 52px;

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 30px;
        }

        .footer-brand {
            margin: 0;

            color: #123454;

            font-size: 17px;
        }

        .footer-brand strong {
            font-weight: 700;
        }

        .footer-link {
            color: #52769d;

            text-decoration: none;

            font-size: 17px;
        }

        .footer-link:hover {
            color: #079db1;
        }


        /* =========================================================
           RESPONSIVE
        ========================================================= */

        @media (max-width: 900px) {

            .page-header {
                padding: 30px 6vw 0;
            }

            .header-right {
                gap: 18px;

                font-size: 15px;
            }

            .role-label {
                font-size: 12px;

                letter-spacing: 4px;
            }

            .review-hero {
                padding: 85px 6vw 95px;
            }

            .submissions-section {
                padding: 90px 6vw 0;
            }

            .submission {
                grid-template-columns:
                    45px
                    minmax(0, 1fr);

                row-gap: 20px;

                padding: 38px 0;
            }

            .submission-status {
                grid-column: 2;
            }

            .review-link {
                grid-column: 2;

                justify-self: start;
            }

            .closing-section {
                padding: 120px 6vw 90px;
            }

            .page-footer {
                padding: 35px 6vw 45px;
            }

        }


        @media (max-width: 600px) {

            .brand-logo {
                width: 115px;
            }

            .header-right {
                gap: 10px;
            }

            .back-link {
                font-size: 14px;
            }

            .hero-title {
                font-size: 52px;

                letter-spacing: -2.5px;
            }

            .hero-description {
                font-size: 18px;
            }

            .section-heading {
                align-items: flex-start;

                flex-direction: column;

                gap: 14px;
            }

            .submission {
                grid-template-columns:
                    35px
                    minmax(0, 1fr);
            }

            .submission-title {
                font-size: 26px;
            }

            .submission-description {
                font-size: 16px;
            }

            .closing-title {
                font-size: 48px;
            }

            .page-footer {
                align-items: flex-start;

                flex-direction: column;
            }

        }

    </style>

</head>


<body>

<main class="page-shell">


    <!-- =========================================================
         HEADER
    ========================================================= -->

    <header class="page-header">


        <a
            href="index.html"
            aria-label="AIraut home"
        >

            <img
                src="assets/airaut-logo.png"
                alt="AIraut"
                class="brand-logo"
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
         HERO
    ========================================================= -->

    <section class="review-hero">


        <p class="eyebrow">
            REVIEW &amp; FEEDBACK
        </p>


        <h1 class="hero-title">

            Review student work.<br>

            <span>
                Support their growth.
            </span>

        </h1>


        <p class="hero-description">

            Review submitted activities and reflections,
            and provide feedback that helps learners move forward.

        </p>


    </section>



    <!-- =========================================================
         SUBMISSIONS
    ========================================================= -->

    <section class="submissions-section">


        <div class="section-heading">


            <p class="section-label">
                SUBMISSIONS
            </p>


            <p class="section-status">

                <?php if ($pendingCount > 0): ?>

                    <?= $pendingCount ?>
                    awaiting your review

                <?php elseif ($totalCount > 0): ?>

                    All submissions reviewed

                <?php else: ?>

                    No submissions yet

                <?php endif; ?>

            </p>


        </div>



        <div class="submission-list">


            <?php if (
                empty($submissions)
            ): ?>


                <!-- EMPTY STATE -->

                <div class="empty-state">


                    <h3>
                        No student submissions yet.
                    </h3>


                    <p>

                        Student work submitted by your assigned cohorts
                        will appear here for review.

                    </p>


                </div>


            <?php else: ?>


                <?php foreach (
                    $submissions as
                    $index => $submission
                ): ?>


                    <article class="submission">


                        <!-- NUMBER -->

                        <div class="submission-number">

                            <?= str_pad(
                                (string) ($index + 1),
                                2,
                                '0',
                                STR_PAD_LEFT
                            ) ?>

                        </div>



                        <!-- SUBMISSION -->

                        <div class="submission-main">


                            <p class="submission-type">

                                <?= htmlspecialchars(
                                    strtoupper(
                                        $submission['activity_type']
                                        ?? 'ACTIVITY'
                                    )
                                ) ?>

                            </p>


                            <h2 class="submission-title">

                                <?= htmlspecialchars(
                                    $submission['activity_title']
                                ) ?>

                            </h2>


                            <p class="student-name">

                                <?= htmlspecialchars(
                                    $submission['student_name']
                                ) ?>

                            </p>


                            <p class="submission-description">

                                <?= htmlspecialchars(
                                    $submission['activity_description']
                                    ??
                                    'Student submission available for review.'
                                ) ?>

                            </p>


                        </div>



                        <!-- STATUS -->

                        <?php if (
                            empty(
                                $submission['review_id']
                            )
                        ): ?>


                            <div class="submission-status pending">

                                Awaiting review

                            </div>


                        <?php else: ?>


                            <div class="submission-status reviewed">

                                Reviewed

                            </div>


                        <?php endif; ?>



                        <!-- REVIEW -->

                        <a
                            href="faculty-review.php?submission_id=<?= (int) $submission['submission_id'] ?>"
                            class="review-link"
                        >

                            <?= empty(
                                $submission['review_id']
                            )

                                ? 'Review →'

                                : 'View Review →'
                            ?>

                        </a>


                    </article>


                <?php endforeach; ?>


            <?php endif; ?>


        </div>


    </section>



    <!-- =========================================================
         CLOSING
    ========================================================= -->

    <section class="closing-section">


        <p class="closing-label">
            NEXT
        </p>


        <h2 class="closing-title">

            See student work and<br>

            give meaningful
            <span>
                feedback.
            </span>

        </h2>


        <p class="closing-description">

            Review submitted activities and reflections.

        </p>


    </section>



    <!-- =========================================================
         FOOTER
    ========================================================= -->

    <footer class="page-footer">


        <p class="footer-brand">

            <strong>
                AIraut
            </strong>

            — Future Readiness Development System

        </p>


        <a
            href="faculty-dashboard.php"
            class="footer-link"
        >

            ← Faculty Dashboard

        </a>


    </footer>


</main>
</body>

</html>
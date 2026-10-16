<?php

require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/config/database.php';

require_role('student');

$userId = current_user_id();


/* =========================================================
   FIND LOGGED-IN STUDENT
========================================================= */

$stmt = $conn->prepare(
    "SELECT id
     FROM students
     WHERE user_id = ?
     LIMIT 1"
);

$stmt->bind_param("i", $userId);
$stmt->execute();

$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    http_response_code(404);
    exit('Student profile not found.');
}

$studentId = (int) $student['id'];


/* =========================================================
   FIND ALL REVIEWS FOR THIS STUDENT
========================================================= */

$stmt = $conn->prepare(
    "SELECT
        r.id AS review_id,
        r.score,
        r.feedback,
        r.observation,
        r.reviewed_at,

        s.id AS submission_id,
        s.submitted_at,

        a.id AS activity_id,
        a.title AS activity_title,
        a.description AS activity_description,
        a.activity_type

     FROM reviews r

     INNER JOIN submissions s
        ON s.id = r.submission_id

     INNER JOIN activities a
        ON a.id = s.activity_id

     WHERE s.student_id = ?

     ORDER BY r.reviewed_at DESC"
);

$stmt->bind_param("i", $studentId);
$stmt->execute();

$reviews = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Feedback — AIraut</title>

    <link
        rel="stylesheet"
        href="style.css"
    >


    <style>

        /* =========================================================
           AIraut — Student Feedback
        ========================================================= */

        .feedback-page {
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

        .feedback-intro {
            max-width: 1280px;

            margin: 0 auto;

            padding: 115px 5vw 120px;
        }

        .feedback-eyebrow {
            color: #008892;

            font-size: 12px;

            font-weight: 700;

            letter-spacing: 4px;

            margin-bottom: 30px;
        }

        .feedback-intro h1 {
            max-width: 950px;

            margin: 0;

            font-size: clamp(56px, 7vw, 94px);

            line-height: .94;

            letter-spacing: -4px;

            font-weight: 750;
        }

        .feedback-intro h1 span {
            color: #008892;
        }

        .feedback-intro p {
            max-width: 700px;

            margin-top: 35px;

            font-size: 19px;

            line-height: 1.65;

            color: #526777;
        }


        /* =========================================================
           SECTION
        ========================================================= */

        .feedback-section {
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
           FEEDBACK ITEM
        ========================================================= */

        .feedback-item {
            margin-top: 55px;

            border: 1px solid #d9d5cb;

            background: #fffefa;
        }

        .feedback-item-header {
            padding: 38px 42px;

            border-bottom: 1px solid #dedbd2;

            display: flex;

            justify-content: space-between;

            align-items: flex-start;

            gap: 30px;
        }

        .feedback-number {
            color: #c79d4d;

            font-size: 12px;

            font-weight: 700;

            letter-spacing: 3px;

            margin-bottom: 13px;
        }

        .feedback-item-header h2 {
            margin: 0;

            font-size: 30px;

            letter-spacing: -.8px;
        }

        .feedback-item-header p {
            margin: 10px 0 0;

            color: #526777;

            font-size: 15px;

            line-height: 1.5;
        }

        .reviewed {
            border: 1px solid #008892;

            border-radius: 50px;

            padding: 9px 15px;

            color: #008892;

            font-size: 10px;

            font-weight: 700;

            letter-spacing: 2px;

            white-space: nowrap;
        }


        /* =========================================================
           FEEDBACK BODY
        ========================================================= */

        .feedback-body {
            padding: 42px;
        }

        .feedback-block {
            padding: 28px 0;

            border-bottom: 1px solid #e2dfd7;
        }

        .feedback-block:first-child {
            padding-top: 0;
        }

        .feedback-block:last-child {
            border-bottom: none;

            padding-bottom: 0;
        }

        .feedback-label {
            color: #008892;

            font-size: 11px;

            font-weight: 700;

            letter-spacing: 2px;

            margin-bottom: 14px;
        }

        .feedback-block h3 {
            margin: 0;

            font-size: 24px;

            line-height: 1.3;

            letter-spacing: -.5px;
        }

        .feedback-block p {
            max-width: 850px;

            margin: 12px 0 0;

            color: #526777;

            font-size: 16px;

            line-height: 1.7;
        }


        /* =========================================================
           SCORE
        ========================================================= */

        .score-display {
            display: flex;

            align-items: baseline;

            gap: 8px;
        }

        .score-number {
            color: #102a43;

            font-size: 48px;

            line-height: 1;

            font-weight: 750;

            letter-spacing: -2px;
        }

        .score-total {
            color: #718096;

            font-size: 18px;

            font-weight: 600;
        }


        /* =========================================================
           DATE
        ========================================================= */

        .review-date {
            display: block;

            margin-top: 18px;

            color: #718096;

            font-size: 13px;
        }


        /* =========================================================
           EMPTY STATE
        ========================================================= */

        .feedback-empty {
            margin-top: 55px;

            padding: 65px 45px;

            border: 1px solid #d9d5cb;

            background: #fffefa;

            text-align: center;
        }

        .feedback-empty h2 {
            margin: 0;

            font-size: 30px;

            letter-spacing: -.8px;
        }

        .feedback-empty p {
            max-width: 650px;

            margin: 14px auto 0;

            color: #526777;

            font-size: 16px;

            line-height: 1.65;
        }


        /* =========================================================
           DEVELOPMENT STRIP
        ========================================================= */

        .development-box {
            margin-top: 55px;

            padding: 45px 50px;

            background: #e5f5f4;

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 40px;
        }

        .development-box h2 {
            margin: 0;

            font-size: 28px;

            letter-spacing: -.7px;
        }

        .development-box p {
            max-width: 750px;

            margin: 12px 0 0;

            color: #526777;

            font-size: 15px;

            line-height: 1.6;
        }

        .development-mark {
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

            .feedback-intro {
                padding: 80px 24px 90px;
            }

            .feedback-intro h1 {
                font-size: clamp(48px, 14vw, 72px);

                letter-spacing: -3px;
            }

            .feedback-section {
                padding: 0 24px 90px;
            }

            .section-heading {
                align-items: flex-start;

                flex-direction: column;

                gap: 10px;
            }

            .feedback-item-header {
                flex-direction: column;

                padding: 28px;
            }

            .feedback-body {
                padding: 28px;
            }

            .feedback-block h3 {
                font-size: 22px;
            }

            .development-box {
                padding: 32px 25px;
            }

            .development-mark {
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

<div class="feedback-page">


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


    <main>


        <!-- =====================================================
             INTRO
        ====================================================== -->

        <section class="feedback-intro">

            <div class="feedback-eyebrow">
                YOUR FEEDBACK
            </div>

            <h1>

                See how your
                <span>thinking grows.</span>

            </h1>

            <p>

                Feedback helps you understand what you have developed,
                where you can grow and how your learning connects to
                real-world readiness.

            </p>

        </section>


        <!-- =====================================================
             FEEDBACK
        ====================================================== -->

        <section class="feedback-section">


            <div class="section-heading">

                <span>
                    FEEDBACK ON YOUR WORK
                </span>

                <span>

                    <?php if (count($reviews) > 0): ?>

                        <?= count($reviews) ?>
                        reviewed
                        <?= count($reviews) === 1
                            ? 'experience'
                            : 'experiences'
                        ?>

                    <?php else: ?>

                        No reviewed experiences yet

                    <?php endif; ?>

                </span>

            </div>


            <?php if (empty($reviews)): ?>


                <!-- =================================================
                     EMPTY STATE
                ================================================== -->

                <div class="feedback-empty">

                    <h2>
                        Your feedback will appear here.
                    </h2>

                    <p>

                        Once your faculty reviews one of your
                        submitted activities, your score and
                        feedback will appear here.

                    </p>

                </div>


            <?php else: ?>


                <!-- =================================================
                     REVIEWED ACTIVITIES
                ================================================== -->

                <?php foreach ($reviews as $index => $item): ?>


                    <article class="feedback-item">


                        <!-- HEADER -->

                        <div class="feedback-item-header">

                            <div>

                                <div class="feedback-number">

                                    <?= str_pad(
                                        $index + 1,
                                        2,
                                        '0',
                                        STR_PAD_LEFT
                                    ) ?>

                                </div>


                                <h2>

                                    <?= htmlspecialchars(
                                        $item['activity_title']
                                    ) ?>

                                </h2>


                                <p>

                                    <?= htmlspecialchars(
                                        $item['activity_type']
                                        ?? 'Activity'
                                    ) ?>

                                    — Feedback from your faculty

                                </p>

                            </div>


                            <div class="reviewed">

                                REVIEWED

                            </div>

                        </div>


                        <!-- BODY -->

                        <div class="feedback-body">


                            <!-- SCORE -->

                            <div class="feedback-block">

                                <div class="feedback-label">
                                    YOUR SCORE
                                </div>


                                <div class="score-display">

                                    <span class="score-number">

                                        <?= htmlspecialchars(
                                            number_format(
                                                (float) $item['score'],
                                                2
                                            )
                                        ) ?>

                                    </span>

                                    <span class="score-total">
                                        / 10
                                    </span>

                                </div>


                                <span class="review-date">

                                    Reviewed on
                                    <?= htmlspecialchars(
                                        $item['reviewed_at']
                                    ) ?>

                                </span>

                            </div>


                            <!-- OBSERVATION -->

                            <div class="feedback-block">

                                <div class="feedback-label">
                                    CLASSROOM OBSERVATION
                                </div>


                                <?php if (
                                    trim(
                                        (string)
                                        $item['observation']
                                    ) !== ''
                                ): ?>

                                    <h3>

                                        Your faculty's
                                        observation of your
                                        participation and work.

                                    </h3>


                                    <p>

                                        <?= nl2br(
                                            htmlspecialchars(
                                                $item['observation']
                                            )
                                        ) ?>

                                    </p>

                                <?php else: ?>

                                    <h3>
                                        No classroom observation was added.
                                    </h3>

                                <?php endif; ?>

                            </div>


                            <!-- PERSONAL FEEDBACK -->

                            <div class="feedback-block">

                                <div class="feedback-label">
                                    PERSONAL FEEDBACK
                                </div>


                                <h3>
                                    Your faculty's feedback to you.
                                </h3>


                                <p>

                                    <?= nl2br(
                                        htmlspecialchars(
                                            $item['feedback']
                                        )
                                    ) ?>

                                </p>

                            </div>


                        </div>

                    </article>


                <?php endforeach; ?>


            <?php endif; ?>


            <!-- =====================================================
                 DEVELOPMENT
            ====================================================== -->

            <div class="development-box">

                <div>

                    <h2>
                        Feedback is part of the journey.
                    </h2>

                    <p>

                        What you learn from one experience can shape
                        how you approach the next.

                    </p>

                </div>


                <div class="development-mark">
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
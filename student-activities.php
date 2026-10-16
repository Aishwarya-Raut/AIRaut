<?php

require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/config/database.php';

require_role('student');

$userId = current_user_id();

$stmt = $conn->prepare(
    "SELECT id, cohort_id
     FROM students
     WHERE user_id = ?
     LIMIT 1"
);

$stmt->bind_param("i", $userId);
$stmt->execute();

$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    die("Student profile not found.");
}

$cohortId = (int) $student['cohort_id'];

$stmt = $conn->prepare(
    "SELECT id, title, description, activity_type, due_at
     FROM activities
     WHERE cohort_id = ?
       AND status = 'active'
     ORDER BY id ASC"
);

$stmt->bind_param("i", $cohortId);
$stmt->execute();

$activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Activities â€” AIraut</title>

    <link rel="stylesheet" href="style.css">

    <style>
        /* =========================================================
           STUDENT ACTIVITIES PAGE
        ========================================================= */

        .activities-page {
            min-height: 100vh;
            background: #faf9f5;
            color: #102a43;
        }

        /* =========================
           HEADER
        ========================= */

        .platform-header {
            width: 100%;
            padding: 38px 5vw;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .platform-logo img {
            width: 105px;
            height: auto;
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

        /* =========================
           INTRO
        ========================= */

        .activities-intro {
            max-width: 1280px;
            margin: 0 auto;
            padding: 115px 5vw 125px;
        }

        .activities-eyebrow {
            color: #008892;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 4px;
            margin-bottom: 30px;
        }

        .activities-intro h1 {
            max-width: 850px;
            font-size: clamp(56px, 7vw, 94px);
            line-height: .94;
            letter-spacing: -4px;
            font-weight: 750;
            margin: 0;
        }

        .activities-intro h1 span {
            color: #008892;
        }

        .activities-intro p {
            max-width: 670px;
            margin-top: 35px;
            font-size: 19px;
            line-height: 1.65;
            color: #526777;
        }

        /* =========================
           ACTIVITY AREA
        ========================================================= */

        .activity-section {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 5vw 140px;
        }

        .activity-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 22px 0;
            border-top: 1px solid #dedbd2;
            border-bottom: 1px solid #dedbd2;
        }

        .activity-heading span:first-child {
            color: #008892;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 4px;
        }

        .activity-heading span:last-child {
            color: #718096;
            font-size: 14px;
        }

        /* =========================
           ACTIVITY ROW
        ========================= */

        .activity-list {
            display: flex;
            flex-direction: column;
        }

        .activity-card {
            display: grid;
            grid-template-columns: 90px 1fr auto;
            align-items: center;
            gap: 35px;

            min-height: 190px;
            padding: 38px 20px;

            border-bottom: 1px solid #dedbd2;

            text-decoration: none;
            color: inherit;

            transition:
                background .3s ease,
                padding .3s ease;
        }

        .activity-card:hover {
            background: #e8f6f5;
            padding-left: 32px;
            padding-right: 32px;
        }

        .activity-number {
            color: #c79d4d;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 3px;
            align-self: start;
            padding-top: 7px;
        }

        .activity-main h2 {
            margin: 0;
            font-size: 32px;
            line-height: 1.15;
            letter-spacing: -1px;
        }

        .activity-main p {
            max-width: 650px;
            margin: 12px 0 0;
            color: #526777;
            font-size: 16px;
            line-height: 1.55;
        }

        .activity-meta {
            display: flex;
            align-items: center;
            gap: 25px;
            white-space: nowrap;
        }

        .activity-type {
            padding: 8px 13px;
            border: 1px solid #d9d5cb;
            border-radius: 50px;

            font-size: 10px;
            font-weight: 700;
            letter-spacing: 2px;
            color: #102a43;
        }

        .activity-arrow {
            color: #008892;
            font-size: 25px;
            transition: transform .3s ease;
        }

        .activity-card:hover .activity-arrow {
            transform: translateX(7px);
        }

        /* =========================
           EMPTY / FUTURE AREA
        ========================= */

        .activities-note {
            margin-top: 70px;
            padding: 55px 60px;

            background: #e5f5f4;

            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 50px;
        }

        .activities-note h3 {
            margin: 0;
            font-size: 28px;
            letter-spacing: -.8px;
        }

        .activities-note p {
            max-width: 600px;
            margin: 10px 0 0;
            color: #526777;
            font-size: 16px;
        }

        .note-mark {
            width: 70px;
            height: 70px;
            border: 1px solid #d4ad5b;
            border-radius: 50%;

            display: grid;
            place-items: center;

            color: #c79d4d;
            font-size: 30px;
            flex-shrink: 0;
        }

        /* =========================
           FOOTER
        ========================= */

        .platform-footer {
            max-width: 1280px;
            margin: 0 auto;
            padding: 35px 5vw 45px;

            border-top: 1px solid #dedbd2;

            display: flex;
            justify-content: space-between;
            align-items: center;

            color: #718096;
            font-size: 14px;
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

        /* =========================
           MOBILE
        ========================= */

        @media (max-width: 800px) {

            .platform-header {
                padding: 25px 24px;
            }

            .platform-nav {
                gap: 15px;
            }

            .platform-label {
                display: none;
            }

            .activities-intro {
                padding: 80px 24px 90px;
            }

            .activities-intro h1 {
                font-size: clamp(48px, 14vw, 72px);
                letter-spacing: -3px;
            }

            .activities-intro p {
                font-size: 17px;
            }

            .activity-section {
                padding: 0 24px 90px;
            }

            .activity-card {
                grid-template-columns: 45px 1fr;
                gap: 18px;
                padding: 30px 0;
            }

            .activity-meta {
                grid-column: 2;
                margin-top: 10px;
            }

            .activity-main h2 {
                font-size: 26px;
            }

            .activities-note {
                padding: 35px 25px;
            }

            .note-mark {
                display: none;
            }

            .platform-footer {
                padding: 30px 24px;
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
        }
    </style>
</head>


<body>

<div class="activities-page">

    <!-- =========================================================
         HEADER
    ========================================================= -->

    <header class="platform-header">

        <a href="index.html" class="platform-logo">
            <img src="assets/airaut-logo.png" alt="AIraut">
        </a>

        <div class="platform-nav">
            <span class="platform-label">STUDENT</span>

            <a href="student-dashboard.php" class="back-link">
                â† Student Dashboard
            </a>
        </div>

    </header>


    <!-- =========================================================
         ACTIVITIES INTRO
    ========================================================= -->

    <main>

        <section class="activities-intro">

            <div class="activities-eyebrow">
                MY ACTIVITIES
            </div>

            <h1>
                Learn by
                <span>doing.</span>
            </h1>

            <p>
                Discover experiences designed to help you question,
                explore, create and apply what you are developing
                throughout your readiness journey.
            </p>

        </section>


        <!-- =====================================================
             YOUR EXPERIENCES
        ===================================================== -->

        <section class="activity-section">

            <div class="activity-heading">
                <span>YOUR EXPERIENCES</span>
                <span>Activities assigned to you</span>
            </div>


           <?php if (empty($activities)): ?>

    <div class="activities-note">
        <div>
            <h3>
                No activities yet.
            </h3>

            <p>
                New experiences assigned to your cohort will appear
                here as your journey develops.
            </p>
        </div>

        <div class="note-mark">
            +
        </div>
    </div>

<?php else: ?>

    <?php foreach ($activities as $index => $activity): ?>

        <a
            href="student-submit.php?activity_id=<?= (int) $activity['id'] ?>"
            class="activity-card"
        >

            <div class="activity-number">
                <?= str_pad($index + 1, 2, '0', STR_PAD_LEFT) ?>
            </div>

            <div class="activity-main">

                <h2>
                    <?= htmlspecialchars($activity['title']) ?>
                </h2>

                <p>
                    <?= htmlspecialchars($activity['description'] ?? '') ?>
                </p>

            </div>

            <div class="activity-meta">

                <span class="activity-type">
                    <?= htmlspecialchars(
                        strtoupper($activity['activity_type'] ?? 'ACTIVITY')
                    ) ?>
                </span>

                <span class="activity-arrow">
                    â†’
                </span>

            </div>

        </a>

    <?php endforeach; ?>

<?php endif; ?>

            <!-- =================================================
                 FUTURE ACTIVITY AREA
            ================================================== -->

            <div class="activities-note">

                <div>

                    <h3>
                        Your journey will grow here.
                    </h3>

                    <p>
                        New experiences assigned by your institution
                        will appear in this space as your journey
                        develops.
                    </p>

                </div>

                <div class="note-mark">
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
            <strong>AIraut</strong>
            â€” Future Readiness Development System
        </div>

        <a href="student-dashboard.php" class="dashboard-link">
            â† Student Dashboard
        </a>

    </footer>

</div>

</body>
</html>

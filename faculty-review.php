<?php

require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/config/database.php';

require_role('faculty');

$userId = current_user_id();


/* =========================================================
   GET SUBMISSION ID
========================================================= */

$submissionId = isset($_GET['submission_id'])
    ? (int) $_GET['submission_id']
    : (int) ($_POST['submission_id'] ?? 0);

if ($submissionId <= 0) {
    http_response_code(400);
    exit('Invalid submission.');
}


/* =========================================================
   FIND LOGGED-IN FACULTY
========================================================= */

$stmt = $conn->prepare(
    "SELECT id, institution_id
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
   FIND SUBMISSION
   AND VERIFY THIS FACULTY IS ASSIGNED TO THE
   STUDENT'S COHORT
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
        a.question,

        st.id AS student_id,
        st.cohort_id,

        u.name AS student_name,
        u.email AS student_email

     FROM submissions s

     INNER JOIN activities a
        ON a.id = s.activity_id

     INNER JOIN students st
        ON st.id = s.student_id

     INNER JOIN users u
        ON u.id = st.user_id

     INNER JOIN faculty_cohorts fc
        ON fc.cohort_id = st.cohort_id
       AND fc.faculty_id = ?

     WHERE s.id = ?

     LIMIT 1"
);

$stmt->bind_param(
    "ii",
    $facultyId,
    $submissionId
);

$stmt->execute();

$submission = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$submission) {
    http_response_code(403);
    exit('You are not authorized to review this submission.');
}


/* =========================================================
   LOAD ATTACHMENT
========================================================= */

$stmt = $conn->prepare(
    "SELECT
        id,
        original_name,
        stored_name,
        file_path,
        mime_type,
        file_size,
        uploaded_at
     FROM submission_files
     WHERE submission_id = ?
     LIMIT 1"
);

$stmt->bind_param(
    "i",
    $submissionId
);

$stmt->execute();

$attachment = $stmt->get_result()->fetch_assoc();
$stmt->close();


/* =========================================================
   SECURE ATTACHMENT DOWNLOAD
========================================================= */

$downloadRequested =
    isset($_GET['download_file']) &&
    $_GET['download_file'] === '1';

if ($downloadRequested) {

    if (!$attachment) {
        http_response_code(404);
        exit('Attachment not found.');
    }

    /*
     * The database stores the generated random filename.
     * basename() prevents path traversal.
     */

    $safeStoredName =
        basename(
            $attachment['stored_name']
        );

    $physicalFile =
        __DIR__
        . DIRECTORY_SEPARATOR
        . 'uploads'
        . DIRECTORY_SEPARATOR
        . 'submissions'
        . DIRECTORY_SEPARATOR
        . $safeStoredName;

    if (
        !file_exists($physicalFile) ||
        !is_file($physicalFile)
    ) {
        http_response_code(404);
        exit('The uploaded file could not be found on the server.');
    }


    /*
     * Prevent PHP/browser output from corrupting the file.
     */

    if (ob_get_level() > 0) {
        ob_end_clean();
    }


    $downloadName =
        $attachment['original_name'];


    /*
     * Safe ASCII fallback filename.
     */

    $fallbackName =
        preg_replace(
            '/[^A-Za-z0-9._-]/',
            '_',
            $downloadName
        );

    if (
        !is_string($fallbackName) ||
        $fallbackName === ''
    ) {
        $fallbackName = 'student-work';
    }


    header(
        'Content-Type: '
        . $attachment['mime_type']
    );

    header(
        'Content-Length: '
        . filesize($physicalFile)
    );

    header(
        'Content-Disposition: attachment; filename="'
        . $fallbackName
        . '"; filename*=UTF-8\'\''
        . rawurlencode($downloadName)
    );

    header('X-Content-Type-Options: nosniff');

    readfile($physicalFile);

    exit;
}


/* =========================================================
   HANDLE REVIEW SUBMISSION
========================================================= */

$saveError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* ---------------------------------------------
       CSRF PROTECTION
    --------------------------------------------- */

    require_csrf_token();


    $score = isset($_POST['score'])
        ? (float) $_POST['score']
        : -1;

    $observation = trim(
        $_POST['observation'] ?? ''
    );

    $feedback = trim(
        $_POST['feedback'] ?? ''
    );


    /* ---------------------------------------------
       Validate score
    --------------------------------------------- */

    if ($score < 0 || $score > 10) {

        $saveError =
            'Please enter a score between 0 and 10.';

    } elseif ($feedback === '') {

        $saveError =
            'Please enter personal feedback for the student.';

    } else {

        /* -----------------------------------------
           Check whether a review already exists
        ----------------------------------------- */

        $stmt = $conn->prepare(
            "SELECT id
             FROM reviews
             WHERE submission_id = ?
             LIMIT 1"
        );

        $stmt->bind_param(
            "i",
            $submissionId
        );

        $stmt->execute();

        $existingReview =
            $stmt->get_result()->fetch_assoc();

        $stmt->close();


        /* -----------------------------------------
           Update existing review
        ----------------------------------------- */

        if ($existingReview) {

            $reviewId =
                (int) $existingReview['id'];

            $stmt = $conn->prepare(
                "UPDATE reviews
                 SET
                    faculty_id = ?,
                    score = ?,
                    feedback = ?,
                    observation = ?,
                    reviewed_at = NOW()
                 WHERE id = ?"
            );

            $stmt->bind_param(
                "idssi",
                $facultyId,
                $score,
                $feedback,
                $observation,
                $reviewId
            );

            if (!$stmt->execute()) {

                $saveError =
                    'Unable to update the review.';
            }

            $stmt->close();


        } else {

            /* -------------------------------------
               Create new review
            ------------------------------------- */

            $stmt = $conn->prepare(
                "INSERT INTO reviews
                (
                    submission_id,
                    faculty_id,
                    score,
                    feedback,
                    observation,
                    reviewed_at
                )
                VALUES (?, ?, ?, ?, ?, NOW())"
            );

            $stmt->bind_param(
                "iidss",
                $submissionId,
                $facultyId,
                $score,
                $feedback,
                $observation
            );

            if (!$stmt->execute()) {

                $saveError =
                    'Unable to save the review.';
            }

            $stmt->close();
        }


        /* -----------------------------------------
           Mark submission as reviewed
        ----------------------------------------- */

        if ($saveError === '') {

            $stmt = $conn->prepare(
                "UPDATE submissions
                 SET status = 'reviewed'
                 WHERE id = ?"
            );

            $stmt->bind_param(
                "i",
                $submissionId
            );

            $stmt->execute();
            $stmt->close();


            /* -------------------------------------
               Prevent duplicate form submission
            ------------------------------------- */

            header(
                'Location: faculty-review.php?submission_id='
                . $submissionId
                . '&saved=1'
            );

            exit;
        }
    }
}


/* =========================================================
   LOAD EXISTING REVIEW
========================================================= */

$stmt = $conn->prepare(
    "SELECT
        score,
        feedback,
        observation,
        reviewed_at
     FROM reviews
     WHERE submission_id = ?
     LIMIT 1"
);

$stmt->bind_param(
    "i",
    $submissionId
);

$stmt->execute();

$review = $stmt->get_result()->fetch_assoc();

$stmt->close();


$reviewed = !empty($review);

$saved =
    isset($_GET['saved']) &&
    $_GET['saved'] === '1';


/* =========================================================
   DISPLAY VALUES
========================================================= */

$currentScore =
    $review['score'] ?? '';

$currentFeedback =
    $review['feedback'] ?? '';

$currentObservation =
    $review['observation'] ?? '';



/* =========================================================
   ATTACHMENT DISPLAY HELPERS
========================================================= */

function format_file_size(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }

    if ($bytes < 1024 * 1024 * 1024) {
        return round(
            $bytes / (1024 * 1024),
            1
        ) . ' MB';
    }

    return round(
        $bytes / (1024 * 1024 * 1024),
        1
    ) . ' GB';
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
        Review <?= htmlspecialchars($submission['student_name']) ?>
        — AIraut
    </title>

    <link
        rel="stylesheet"
        href="style.css"
    >


    <style>

        /* =========================================================
           AIraut — Faculty Review
        ========================================================= */

        .review-page {
            min-height: 100vh;

            background: #fbfaf7;

            color: #123454;
        }


        /* HEADER */

        .review-header {
            width: 100%;

            padding: 42px 7.2vw 0;

            display: flex;

            align-items: center;

            justify-content: space-between;
        }

        .review-brand img {
            width: 150px;

            display: block;
        }

        .review-header-right {
            display: flex;

            align-items: center;

            gap: 38px;

            font-size: 18px;
        }

        .review-role {
            color: #079db1;

            font-size: 15px;

            font-weight: 700;

            letter-spacing: 7px;
        }

        .review-back {
            color: #123454;

            text-decoration: none;

            font-weight: 600;
        }

        .review-back:hover {
            color: #079db1;
        }


        /* HERO */

        .review-page-hero {
            padding: 105px 7.2vw 115px;

            border-bottom: 1px solid #d9ddd9;
        }

        .review-eyebrow {
            margin: 0 0 24px;

            color: #079db1;

            font-size: 15px;

            font-weight: 700;

            letter-spacing: 7px;
        }

        .review-page-hero h1 {
            max-width: 1000px;

            margin: 0;

            font-size: clamp(58px, 6vw, 96px);

            line-height: .95;

            letter-spacing: -4px;
        }

        .review-page-hero h1 span {
            color: #079db1;
        }

        .review-page-hero p {
            max-width: 800px;

            margin-top: 28px;

            color: #52769d;

            font-size: 20px;

            line-height: 1.6;
        }


        /* WORK */

        .review-work {
            padding: 110px 7.2vw 0;
        }

        .review-topline {
            display: flex;

            justify-content: space-between;

            align-items: flex-end;

            gap: 30px;

            padding-bottom: 26px;

            border-bottom: 1px solid #d9ddd9;
        }

        .review-topline span:first-child {
            color: #079db1;

            font-size: 15px;

            font-weight: 700;

            letter-spacing: 7px;
        }

        .review-topline span:last-child {
            color: #52769d;

            font-size: 16px;
        }


        /* SUBMISSION */

        .student-work {
            margin-top: 55px;

            border: 1px solid #d9ddd9;

            background: #fffefa;
        }

        .student-work-header {
            padding: 38px 42px;

            border-bottom: 1px solid #d9ddd9;

            display: flex;

            justify-content: space-between;

            align-items: flex-start;

            gap: 30px;
        }

        .activity-type {
            margin: 0 0 12px;

            color: #079db1;

            font-size: 13px;

            font-weight: 700;

            letter-spacing: 4px;
        }

        .student-work-header h2 {
            margin: 0;

            font-size: 34px;

            letter-spacing: -.8px;
        }

        .student-name {
            margin: 9px 0 0;

            color: #52769d;

            font-size: 17px;
        }

        .review-status {
            padding: 9px 15px;

            border: 1px solid #d9d5cb;

            border-radius: 50px;

            color: #d89200;

            font-size: 10px;

            font-weight: 700;

            letter-spacing: 2px;

            white-space: nowrap;
        }

        .review-status.reviewed {
            color: #079db1;

            border-color: #079db1;
        }


        /* QUESTION */

        .review-content {
            padding: 42px;
        }

        .review-label {
            display: block;

            margin-bottom: 12px;

            color: #123454;

            font-size: 11px;

            font-weight: 700;

            letter-spacing: 2px;
        }

        .review-question {
            padding: 22px;

            background: #f3f8f7;

            border: 1px solid #d9ddd9;

            color: #123454;

            font-size: 16px;

            line-height: 1.65;
        }


        /* STUDENT RESPONSE */

        .student-response {
            margin-top: 38px;
        }

        .response-box {
            padding: 25px;

            border: 1px solid #d9ddd9;

            background: #fbfaf7;

            color: #123454;

            font-size: 17px;

            line-height: 1.75;

            white-space: pre-wrap;
        }


        /* =========================================================
           ATTACHMENT
        ========================================================= */

        .student-attachment {
            margin-top: 38px;
        }

        .attachment-box {
            padding: 20px 24px;

            border: 1px solid #d9ddd9;

            background: #f3f8f7;

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 25px;
        }

        .attachment-info {
            min-width: 0;
        }

        .attachment-name {
            display: block;

            color: #123454;

            font-size: 16px;

            font-weight: 700;

            word-break: break-word;
        }

        .attachment-meta {
            display: block;

            margin-top: 6px;

            color: #718096;

            font-size: 13px;
        }

        .attachment-action {
            flex-shrink: 0;

            display: inline-block;

            padding: 12px 18px;

            border: 1px solid #123454;

            background: #123454;

            color: #ffffff;

            text-decoration: none;

            font-size: 13px;

            font-weight: 700;

            transition: all .25s ease;
        }

        .attachment-action:hover {
            background: #079db1;

            border-color: #079db1;
        }

        .no-attachment {
            padding: 20px 24px;

            border: 1px solid #d9ddd9;

            background: #fbfaf7;

            color: #718096;

            font-size: 14px;
        }


        /* FORM */

        .evaluation-form {
            margin-top: 45px;

            padding-top: 45px;

            border-top: 1px solid #d9ddd9;
        }

        .evaluation-group {
            margin-bottom: 32px;
        }

        .evaluation-label {
            display: block;

            margin-bottom: 12px;

            color: #123454;

            font-size: 11px;

            font-weight: 700;

            letter-spacing: 2px;
        }

        .evaluation-help {
            margin: -4px 0 12px;

            color: #718096;

            font-size: 13px;

            line-height: 1.5;
        }

        .score-input {
            width: 140px;

            padding: 16px 18px;

            border: 1px solid #d9d5cb;

            background: #fbfaf7;

            color: #123454;

            font-family: inherit;

            font-size: 20px;

            font-weight: 700;

            outline: none;
        }

        .score-input:focus,
        .evaluation-form textarea:focus {
            border-color: #079db1;

            outline: none;
        }

        .evaluation-form textarea {
            width: 100%;

            min-height: 145px;

            box-sizing: border-box;

            padding: 16px 18px;

            border: 1px solid #d9d5cb;

            background: #fbfaf7;

            color: #123454;

            font-family: inherit;

            font-size: 16px;

            line-height: 1.65;

            resize: vertical;
        }

        .review-save {
            margin-top: 15px;

            display: flex;

            justify-content: flex-end;
        }

        .review-save button {
            border: 1px solid #123454;

            background: #123454;

            color: #ffffff;

            padding: 16px 28px;

            font-family: inherit;

            font-size: 14px;

            font-weight: 700;

            cursor: pointer;
        }

        .review-save button:hover {
            background: #079db1;

            border-color: #079db1;
        }


        /* MESSAGES */

        .review-message {
            margin-bottom: 30px;

            padding: 17px 20px;

            border: 1px solid #d9ddd9;

            background: #e8f6f5;

            color: #123454;

            font-size: 14px;

            line-height: 1.5;
        }

        .review-error {
            background: #fff7eb;

            color: #805a18;
        }


        /* FOOTER */

        .review-footer {
            margin-top: 120px;

            padding: 42px 7.2vw 52px;

            border-top: 1px solid #d9ddd9;

            display: flex;

            justify-content: space-between;

            gap: 30px;

            color: #718096;

            font-size: 14px;
        }

        .review-footer a {
            color: #52769d;

            text-decoration: none;
        }

        .review-footer a:hover {
            color: #079db1;
        }


        /* MOBILE */

        @media (max-width: 900px) {

            .review-header {
                padding: 30px 6vw 0;
            }

            .review-header-right {
                gap: 18px;

                font-size: 15px;
            }

            .review-role {
                font-size: 12px;

                letter-spacing: 4px;
            }

            .review-page-hero {
                padding: 85px 6vw 95px;
            }

            .review-work {
                padding: 90px 6vw 0;
            }

            .student-work-header {
                flex-direction: column;

                padding: 30px;
            }

            .review-content {
                padding: 30px;
            }

            .attachment-box {
                align-items: flex-start;

                flex-direction: column;
            }

            .review-footer {
                padding: 35px 6vw 45px;

                flex-direction: column;
            }

        }


        @media (max-width: 600px) {

            .review-brand img {
                width: 115px;
            }

            .review-header-right {
                gap: 10px;
            }

            .review-role {
                display: none;
            }

            .review-page-hero h1 {
                font-size: 52px;

                letter-spacing: -2.5px;
            }

            .review-topline {
                align-items: flex-start;

                flex-direction: column;
            }

            .student-work-header h2 {
                font-size: 28px;
            }

            .review-content {
                padding: 24px;
            }

            .attachment-box {
                padding: 18px;
            }

            .attachment-action {
                width: 100%;

                box-sizing: border-box;

                text-align: center;
            }

            .review-footer {
                padding: 30px 24px;
            }

        }

    </style>

</head>


<body>

<div class="review-page">


    <!-- =========================================================
         HEADER
    ========================================================= -->

    <header class="review-header">

        <a
            href="faculty-dashboard.php"
            class="review-brand"
        >

            <img
                src="assets/airaut-logo.png"
                alt="AIraut"
            >

        </a>


        <div class="review-header-right">

            <span class="review-role">
                FACULTY
            </span>

            <a
                href="faculty-submissions.php"
                class="review-back"
            >
                ← Submissions
            </a>

        </div>

    </header>


    <!-- =========================================================
         HERO
    ========================================================= -->

    <section class="review-page-hero">

        <p class="review-eyebrow">
            REVIEW &amp; FEEDBACK
        </p>

        <h1>

            Review student work.<br>

            <span>
                Support their growth.
            </span>

        </h1>

        <p>

            Review the learner's work, consider their classroom
            performance, and provide feedback that helps them move forward.

        </p>

    </section>


    <!-- =========================================================
         WORK
    ========================================================= -->

    <section class="review-work">


        <div class="review-topline">

            <span>
                STUDENT SUBMISSION
            </span>

            <span>

                <?= $reviewed
                    ? 'Review completed'
                    : 'Awaiting your review'
                ?>

            </span>

        </div>


        <?php if ($saved): ?>

            <div
                class="review-message"
                style="margin-top: 30px;"
            >

                Review saved successfully.
                The student's feedback is now available in their
                feedback record.

            </div>

        <?php endif; ?>


        <?php if ($saveError !== ''): ?>

            <div
                class="review-message review-error"
                style="margin-top: 30px;"
            >

                <?= htmlspecialchars($saveError) ?>

            </div>

        <?php endif; ?>


        <div class="student-work">


            <!-- STUDENT + ACTIVITY HEADER -->

            <div class="student-work-header">

                <div>

                    <p class="activity-type">

                        <?= htmlspecialchars(
                            strtoupper(
                                $submission['activity_type']
                                ?? 'ACTIVITY'
                            )
                        ) ?>

                    </p>


                    <h2>

                        <?= htmlspecialchars(
                            $submission['activity_title']
                        ) ?>

                    </h2>


                    <p class="student-name">

                        <?= htmlspecialchars(
                            $submission['student_name']
                        ) ?>

                    </p>

                </div>


                <div
                    class="review-status <?= $reviewed ? 'reviewed' : '' ?>"
                >

                    <?= $reviewed
                        ? 'REVIEWED'
                        : 'AWAITING REVIEW'
                    ?>

                </div>

            </div>


            <!-- REVIEW CONTENT -->

            <div class="review-content">


                <!-- QUESTION -->

                <div class="evaluation-group">

                    <label class="review-label">
                        ACTIVITY QUESTION
                    </label>


                    <div class="review-question">

                        <?= htmlspecialchars(
                            $submission['question'] ?? ''
                        ) ?>

                    </div>

                </div>


                <!-- STUDENT RESPONSE -->

                <div class="student-response">

                    <label class="review-label">
                        STUDENT RESPONSE
                    </label>


                    <div class="response-box">

                        <?= htmlspecialchars(
                            $submission['response']
                        ) ?>

                    </div>

                </div>


                <!-- ATTACHMENT -->

                <div class="student-attachment">

                    <label class="review-label">
                        STUDENT ATTACHMENT
                    </label>


                    <?php if ($attachment): ?>

                        <div class="attachment-box">


                            <div class="attachment-info">

                                <span class="attachment-name">

                                    <?= htmlspecialchars(
                                        $attachment['original_name']
                                    ) ?>

                                </span>


                                <span class="attachment-meta">

                                    <?= htmlspecialchars(
                                        strtoupper(
                                            pathinfo(
                                                $attachment['original_name'],
                                                PATHINFO_EXTENSION
                                            )
                                        )
                                    ) ?>

                                    ·

                                    <?= htmlspecialchars(
                                        format_file_size(
                                            (int) $attachment['file_size']
                                        )
                                    ) ?>

                                    · Uploaded
                                    <?= htmlspecialchars(
                                        $attachment['uploaded_at']
                                    ) ?>

                                </span>

                            </div>


                            <a
                                href="faculty-review.php?submission_id=<?= $submissionId ?>&download_file=1"
                                class="attachment-action"
                            >
                                Download Attachment →
                            </a>


                        </div>


                    <?php else: ?>


                        <div class="no-attachment">

                            No attachment was submitted with this work.

                        </div>


                    <?php endif; ?>


                </div>


                <!-- EVALUATION FORM -->

                <form
                    class="evaluation-form"
                    method="POST"
                    action="faculty-review.php?submission_id=<?= $submissionId ?>"
                >


                    <input
                        type="hidden"
                        name="submission_id"
                        value="<?= $submissionId ?>"
                    >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars(
                            csrf_token(),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                    >


                    <!-- SCORE -->

                    <div class="evaluation-group">

                        <label
                            class="evaluation-label"
                            for="score"
                        >
                            SCORE / 10
                        </label>


                        <p class="evaluation-help">

                            Give the learner a score between 0 and 10.

                        </p>


                        <input
                            id="score"
                            name="score"
                            class="score-input"
                            type="number"
                            min="0"
                            max="10"
                            step="0.5"
                            value="<?= htmlspecialchars(
                                (string) $currentScore
                            ) ?>"
                            required
                        >

                    </div>


                    <!-- OBSERVATION -->

                    <div class="evaluation-group">

                        <label
                            class="evaluation-label"
                            for="observation"
                        >
                            CLASSROOM OBSERVATION
                        </label>


                        <p class="evaluation-help">

                            Record what you observed during the activity
                            or classroom interaction.

                        </p>


                        <textarea
                            id="observation"
                            name="observation"
                            placeholder="Record your observation..."
                        ><?= htmlspecialchars(
                            $currentObservation
                        ) ?></textarea>

                    </div>


                    <!-- FEEDBACK -->

                    <div class="evaluation-group">

                        <label
                            class="evaluation-label"
                            for="feedback"
                        >
                            PERSONAL FEEDBACK TO STUDENT
                        </label>


                        <p class="evaluation-help">

                            This feedback will be visible to the student.

                        </p>


                        <textarea
                            id="feedback"
                            name="feedback"
                            placeholder="Write personal feedback for the student..."
                            required
                        ><?= htmlspecialchars(
                            $currentFeedback
                        ) ?></textarea>

                    </div>


                    <!-- SAVE -->

                    <div class="review-save">

                        <button type="submit">

                            <?= $reviewed
                                ? 'Update Review →'
                                : 'Save Review →'
                            ?>

                        </button>

                    </div>


                </form>


            </div>

        </div>

    </section>


    <!-- =========================================================
         FOOTER
    ========================================================= -->

    <footer class="review-footer">

        <div>

            <strong>
                AIraut
            </strong>

            — Future Readiness Development System

        </div>


        <a href="faculty-submissions.php">
            ← Back to Submissions
        </a>

    </footer>


</div>

</body>

</html>
<?php

require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/config/database.php';

require_role('student');

$userId = current_user_id();

$activityId = isset($_GET['activity_id'])
    ? (int) $_GET['activity_id']
    : 0;

if ($activityId <= 0) {
    http_response_code(400);
    exit('Invalid activity.');
}


/* =========================================================
   FIND LOGGED-IN STUDENT
========================================================= */

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
    http_response_code(404);
    exit('Student profile not found.');
}

$studentId = (int) $student['id'];
$cohortId = (int) $student['cohort_id'];


/* =========================================================
   FIND ACTIVITY
   - Must belong to the student's cohort
   - Must be active
========================================================= */

$stmt = $conn->prepare(
    "SELECT
        id,
        title,
        description,
        activity_type,
        question,
        due_at
     FROM activities
     WHERE id = ?
       AND cohort_id = ?
       AND status = 'active'
     LIMIT 1"
);

$stmt->bind_param("ii", $activityId, $cohortId);
$stmt->execute();

$activity = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$activity) {
    http_response_code(404);
    exit('Activity not found.');
}


/* =========================================================
   CHECK EXISTING SUBMISSION
========================================================= */

$stmt = $conn->prepare(
    "SELECT
        id,
        response,
        submitted_at
     FROM submissions
     WHERE activity_id = ?
       AND student_id = ?
     LIMIT 1"
);

$stmt->bind_param("ii", $activityId, $studentId);
$stmt->execute();

$existingSubmission = $stmt->get_result()->fetch_assoc();
$stmt->close();


/* =========================================================
   PAGE STATE
========================================================= */

$submitted = !empty($existingSubmission);

$justSubmitted =
    isset($_GET['submitted']) &&
    $_GET['submitted'] === '1';

$errorCode = $_GET['error'] ?? '';

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
        <?= htmlspecialchars($activity['title']) ?> · Submit Work · AIraut
    </title>

    <link rel="stylesheet" href="style.css">


    <style>

        /* =========================================================
           STUDENT SUBMIT PAGE
        ========================================================= */

        .submit-page {
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

            transition: color .25s ease;
        }

        .back-link:hover {
            color: #008892;
        }


        /* =========================================================
           INTRO
        ========================================================= */

        .submit-intro {
            max-width: 1280px;
            margin: 0 auto;

            padding: 115px 5vw 120px;
        }

        .submit-eyebrow {
            color: #008892;

            font-size: 12px;
            font-weight: 700;

            letter-spacing: 4px;

            margin-bottom: 30px;
        }

        .submit-intro h1 {
            max-width: 900px;

            margin: 0;

            font-size: clamp(56px, 7vw, 94px);
            line-height: .94;

            letter-spacing: -4px;

            font-weight: 750;
        }

        .submit-intro h1 span {
            color: #008892;
        }

        .submit-intro p {
            max-width: 680px;

            margin-top: 35px;

            font-size: 19px;
            line-height: 1.65;

            color: #526777;
        }


        /* =========================================================
           WORKSPACE
        ========================================================= */

        .submit-section {
            max-width: 1280px;
            margin: 0 auto;

            padding: 0 5vw 140px;
        }

        .submit-heading {
            padding: 22px 0;

            border-top: 1px solid #dedbd2;
            border-bottom: 1px solid #dedbd2;

            display: flex;
            justify-content: space-between;
            gap: 30px;
        }

        .submit-heading span:first-child {
            color: #008892;

            font-size: 12px;
            font-weight: 700;

            letter-spacing: 4px;
        }

        .submit-heading span:last-child {
            color: #718096;

            font-size: 14px;
        }


        /* =========================================================
           SUBMISSION BOX
        ========================================================= */

        .submission-box {
            margin-top: 55px;

            border: 1px solid #d9d5cb;

            background: #fffefa;
        }

        .submission-top {
            padding: 38px 42px;

            border-bottom: 1px solid #dedbd2;

            display: flex;
            justify-content: space-between;
            align-items: flex-start;

            gap: 30px;
        }

        .submission-top h2 {
            margin: 0;

            font-size: 30px;
            letter-spacing: -.8px;
        }

        .submission-top p {
            margin: 10px 0 0;

            color: #526777;

            font-size: 16px;
            line-height: 1.55;
        }


        /* =========================================================
           STATUS
        ========================================================= */

        .status {
            border: 1px solid #d9d5cb;

            border-radius: 50px;

            padding: 9px 15px;

            font-size: 10px;
            font-weight: 700;

            letter-spacing: 2px;

            white-space: nowrap;
        }

        .status-submitted {
            border-color: #008892;
            color: #008892;
        }


        /* =========================================================
           FORM
        ========================================================= */

        .submission-form {
            padding: 42px;
        }

        .form-label {
            display: block;

            margin-bottom: 12px;

            font-size: 11px;
            font-weight: 700;

            letter-spacing: 2px;

            color: #102a43;
        }

        .submission-form input,
        .submission-form textarea {
            width: 100%;

            box-sizing: border-box;

            border: 1px solid #d9d5cb;

            background: #faf9f5;

            padding: 16px 18px;

            font-family: inherit;

            font-size: 16px;

            color: #102a43;

            outline: none;
        }

        .submission-form input:focus,
        .submission-form textarea:focus {
            border-color: #008892;
        }

        .submission-form textarea {
            min-height: 150px;

            resize: vertical;
        }

        .form-group {
            margin-bottom: 30px;
        }


        /* =========================================================
           ACTIVITY QUESTION
        ========================================================= */

        .activity-question {
            padding: 20px;

            border: 1px solid #d9d5cb;

            background: #faf9f5;

            font-size: 16px;

            line-height: 1.65;

            color: #102a43;
        }


        /* =========================================================
           UPLOAD
        ========================================================= */

        .upload-area {
            border: 1px dashed #c9c5ba;

            background: #f3f8f7;

            min-height: 190px;

            display: flex;

            align-items: center;

            justify-content: center;

            text-align: center;

            cursor: pointer;

            transition:
                background .25s ease,
                border-color .25s ease;
        }

        .upload-area:hover {
            background: #e8f6f5;

            border-color: #008892;
        }

        .upload-area.file-selected {
            border-color: #008892;
            background: #e8f6f5;
        }

        .upload-content strong {
            display: block;

            font-size: 18px;

            margin-bottom: 8px;
        }

        .upload-content span {
            color: #718096;

            font-size: 14px;
        }

        .upload-icon {
            width: 45px;
            height: 45px;

            margin: 0 auto 18px;

            border: 1px solid #d4ad5b;

            border-radius: 50%;

            display: grid;
            place-items: center;

            color: #c79d4d;

            font-size: 21px;
        }

        .selected-file-name {
            display: block;

            margin-top: 12px;

            color: #008892 !important;

            font-size: 13px !important;

            font-weight: 700;
        }

        #fileInput {
            display: none;
        }


        /* =========================================================
           ACTION
        ========================================================= */

        .submit-action {
            margin-top: 40px;

            display: flex;

            justify-content: flex-end;
        }

        .submit-button {
            border: 1px solid #102a43;

            background: #102a43;

            color: white;

            padding: 15px 25px;

            font-family: inherit;

            font-size: 14px;
            font-weight: 700;

            cursor: pointer;

            transition: all .25s ease;
        }

        .submit-button:hover {
            background: #008892;

            border-color: #008892;
        }


        /* =========================================================
           SUCCESS MESSAGE
        ========================================================= */

        .submission-success {
            padding: 55px 42px;

            background: #e8f6f5;

            text-align: center;
        }

        .success-mark {
            width: 62px;
            height: 62px;

            margin: 0 auto 20px;

            border: 1px solid #008892;

            border-radius: 50%;

            display: grid;
            place-items: center;

            color: #008892;

            font-size: 28px;
            font-weight: 700;
        }

        .submission-success h3 {
            margin: 0;

            font-size: 28px;

            color: #102a43;
        }

        .submission-success p {
            max-width: 600px;

            margin: 12px auto 0;

            color: #526777;

            font-size: 16px;

            line-height: 1.6;
        }

        .submission-success span {
            display: block;

            margin-top: 18px;

            color: #718096;

            font-size: 13px;
        }


        /* =========================================================
           ERROR MESSAGE
        ========================================================= */

        .submission-error {
            margin: 30px 42px 0;

            padding: 16px 18px;

            border: 1px solid #d9d5cb;

            background: #fff8ef;

            color: #7a5a2a;

            font-size: 14px;

            line-height: 1.5;
        }


        /* =========================================================
           NOTE
        ========================================================= */

        .submit-note {
            margin-top: 55px;

            padding: 45px 50px;

            background: #e5f5f4;

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 40px;
        }

        .submit-note h3 {
            margin: 0;

            font-size: 26px;

            letter-spacing: -.6px;
        }

        .submit-note p {
            margin: 10px 0 0;

            color: #526777;

            font-size: 15px;
        }

        .note-mark {
            width: 65px;
            height: 65px;

            flex-shrink: 0;

            border: 1px solid #d4ad5b;

            border-radius: 50%;

            display: grid;

            place-items: center;

            color: #c79d4d;

            font-size: 27px;
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

            .submit-intro {
                padding: 80px 24px 90px;
            }

            .submit-intro h1 {
                font-size: clamp(48px, 14vw, 72px);

                letter-spacing: -3px;
            }

            .submit-intro p {
                font-size: 17px;
            }

            .submit-section {
                padding: 0 24px 90px;
            }

            .submit-heading {
                flex-direction: column;
                gap: 8px;
            }

            .submission-top {
                flex-direction: column;

                padding: 28px;
            }

            .submission-form {
                padding: 28px;
            }

            .submission-success {
                padding: 40px 28px;
            }

            .submission-error {
                margin: 24px 28px 0;
            }

            .submit-note {
                padding: 32px 25px;
            }

            .note-mark {
                display: none;
            }

            .platform-footer {
                padding: 30px 24px;

                flex-direction: column;

                gap: 15px;

                align-items: flex-start;
            }

        }

    </style>

</head>


<body>

<div class="submit-page">


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
         MAIN
    ========================================================= -->

    <main>


        <!-- INTRO -->

        <section class="submit-intro">

            <div class="submit-eyebrow">
                SUBMIT WORK
            </div>

            <h1>
                Make your
                <span>thinking visible.</span>
            </h1>

            <p>
                Share what you have explored, created or applied.
                Your work becomes part of your readiness journey.
            </p>

        </section>


        <!-- SUBMISSION -->

        <section class="submit-section">

            <div class="submit-heading">

                <span>
                    YOUR SUBMISSION
                </span>

                <span>
                    Show what you have developed
                </span>

            </div>


            <div class="submission-box">


                <!-- ACTIVITY HEADER -->

                <div class="submission-top">

                    <div>

                        <h2>
                            <?= htmlspecialchars($activity['title']) ?>
                        </h2>

                        <p>
                            <?= htmlspecialchars(
                                $activity['description'] ?? ''
                            ) ?>
                        </p>

                    </div>


                    <div
                        class="status <?= $submitted ? 'status-submitted' : '' ?>"
                    >

                        <?= $submitted
                            ? 'SUBMITTED'
                            : 'READY TO SUBMIT'
                        ?>

                    </div>

                </div>


                <?php if ($errorCode === 'already_submitted'): ?>

                    <div class="submission-error">

                        You have already submitted this activity.
                        Your faculty will review your work and share
                        feedback with you.

                    </div>

                <?php endif; ?>


                <?php if ($justSubmitted || $submitted): ?>


                    <!-- =================================================
                         SUBMITTED STATE
                    ================================================= -->

                    <div class="submission-success">

                        <div class="success-mark">
                            ✓
                        </div>


                        <h3>
                            Submission received.
                        </h3>


                        <p>

                            Your work has been submitted successfully.
                            Your faculty will review it and share your
                            feedback with you.

                        </p>


                        <?php if (
                            !empty($existingSubmission['submitted_at'])
                        ): ?>

                            <span>

                                Submitted on
                                <?= htmlspecialchars(
                                    $existingSubmission['submitted_at']
                                ) ?>

                            </span>

                        <?php endif; ?>

                    </div>


                <?php else: ?>


                    <!-- =================================================
                         SUBMISSION FORM
                    ================================================= -->

                    <form
                        class="submission-form"
                        action="submit-work.php"
                        method="POST"
                        enctype="multipart/form-data"
                    >


                        <!-- CSRF TOKEN -->

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars(
                                csrf_token(),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                        >


                        <!-- ACTIVITY QUESTION -->

                        <div class="form-group">

                            <label class="form-label">
                                ACTIVITY QUESTION
                            </label>


                            <div class="activity-question">

                                <?= htmlspecialchars(
                                    $activity['question'] ?? ''
                                ) ?>

                            </div>

                        </div>


                        <!-- HIDDEN ACTIVITY ID -->

                        <input
                            type="hidden"
                            name="activity_id"
                            value="<?= (int) $activity['id'] ?>"
                        >


                        <!-- REFLECTION -->

                        <div class="form-group">

                            <label
                                class="form-label"
                                for="studentResponse"
                            >
                                YOUR REFLECTION
                            </label>


                            <textarea
                                id="studentResponse"
                                name="response"
                                placeholder="Tell us about what you explored, created or learned..."
                                required
                            ></textarea>

                        </div>


                        <!-- FILE AREA -->

                        <div class="form-group">

                            <label class="form-label">
                                YOUR WORK
                            </label>


                            <label
                                class="upload-area"
                                for="fileInput"
                                id="uploadArea"
                            >

                                <div class="upload-content">

                                    <div class="upload-icon">
                                        ↑
                                    </div>


                                    <strong id="uploadTitle">
                                        Add your work
                                    </strong>


                                    <span id="uploadText">
                                        Upload a file or document
                                    </span>

                                    <span
                                        class="selected-file-name"
                                        id="selectedFileName"
                                        style="display:none;"
                                    ></span>

                                </div>

                            </label>


                            <input
                                id="fileInput"
                                name="work_file"
                                type="file"
                                accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.zip"
                            >

                        </div>


                        <!-- SUBMIT -->

                        <div class="submit-action">

                            <button
                                type="submit"
                                class="submit-button"
                            >
                                Submit Work →
                            </button>

                        </div>


                    </form>


                <?php endif; ?>


            </div>


            <!-- =====================================================
                 NOTE
            ====================================================== -->

            <div class="submit-note">

                <div>

                    <h3>
                        Your work is part of your journey.
                    </h3>

                    <p>
                        Submissions can help you reflect on your
                        development and demonstrate what you are
                        becoming ready to do.
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


<script>

/*
|--------------------------------------------------------------------------
| FILE SELECTION DISPLAY
|--------------------------------------------------------------------------
*/

const fileInput =
    document.getElementById('fileInput');

const uploadArea =
    document.getElementById('uploadArea');

const uploadTitle =
    document.getElementById('uploadTitle');

const uploadText =
    document.getElementById('uploadText');

const selectedFileName =
    document.getElementById('selectedFileName');


if (fileInput) {

    fileInput.addEventListener(
        'change',
        function () {

            if (this.files && this.files.length > 0) {

                const file =
                    this.files[0];

                uploadArea.classList.add(
                    'file-selected'
                );

                uploadTitle.textContent =
                    'Work selected';

                uploadText.textContent =
                    'Ready to upload with your submission';

                selectedFileName.textContent =
                    file.name;

                selectedFileName.style.display =
                    'block';

            } else {

                uploadArea.classList.remove(
                    'file-selected'
                );

                uploadTitle.textContent =
                    'Add your work';

                uploadText.textContent =
                    'Upload a file or document';

                selectedFileName.textContent =
                    '';

                selectedFileName.style.display =
                    'none';

            }

        }
    );

}

</script>


</body>

</html>
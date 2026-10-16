<?php

require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/config/database.php';

require_role('admin');

$message = '';
$error = '';

/*
|--------------------------------------------------------------------------
| ADD STUDENT
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    require_csrf_token();

    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $institutionId = (int) ($_POST['institution_id'] ?? 0);
    $cohortId = (int) ($_POST['cohort_id'] ?? 0);
    $studentCode = strtoupper(trim($_POST['student_code'] ?? ''));
    $course = trim($_POST['course'] ?? '');
    $academicYear = trim($_POST['academic_year'] ?? '');
    $status = $_POST['status'] ?? 'active';

    if ($name === '') {

        $error = 'Student name is required.';

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = 'Please enter a valid email address.';

    } elseif (strlen($password) < 8) {

        $error = 'Password must contain at least 8 characters.';

    } elseif ($institutionId <= 0) {

        $error = 'Please select an institution.';

    } elseif ($cohortId <= 0) {

        $error = 'Please select a cohort.';

    } elseif ($studentCode === '') {

        $error = 'Student code is required.';

    } elseif (!in_array($status, ['active', 'inactive'], true)) {

        $error = 'Invalid student status.';

    } else {

        /*
        |--------------------------------------------------------------------------
        | Check email
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT id
            FROM users
            WHERE email = ?
            LIMIT 1
        ");

        $stmt->bind_param('s', $email);
        $stmt->execute();

        $result = $stmt->get_result();
        $existingUser = $result->fetch_assoc();

        $stmt->close();


        if ($existingUser) {

            $error = 'A user with this email already exists.';

        } else {

            /*
            |--------------------------------------------------------------------------
            | Check student code
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT id
                FROM students
                WHERE student_code = ?
                LIMIT 1
            ");

            $stmt->bind_param('s', $studentCode);
            $stmt->execute();

            $result = $stmt->get_result();
            $existingStudent = $result->fetch_assoc();

            $stmt->close();


            if ($existingStudent) {

                $error = 'A student with this code already exists.';

            } else {

                /*
                |--------------------------------------------------------------------------
                | Verify cohort belongs to selected institution
                |--------------------------------------------------------------------------
                */

                $stmt = $conn->prepare("
                    SELECT id
                    FROM cohorts
                    WHERE id = ?
                      AND institution_id = ?
                    LIMIT 1
                ");

                $stmt->bind_param(
                    'ii',
                    $cohortId,
                    $institutionId
                );

                $stmt->execute();

                $result = $stmt->get_result();
                $cohortExists = $result->fetch_assoc();

                $stmt->close();


                if (!$cohortExists) {

                    $error =
                        'Selected cohort does not belong to the selected institution.';

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | Create user + student record
                    |--------------------------------------------------------------------------
                    */

                    $conn->begin_transaction();

                    try {

                        $passwordHash = password_hash(
                            $password,
                            PASSWORD_DEFAULT
                        );

                        $userStatus =
                            $status === 'active'
                                ? 'active'
                                : 'inactive';


                        $stmt = $conn->prepare("
                            INSERT INTO users
                                (
                                    name,
                                    email,
                                    password_hash,
                                    role,
                                    status
                                )
                            VALUES
                                (?, ?, ?, 'student', ?)
                        ");

                        $stmt->bind_param(
                            'ssss',
                            $name,
                            $email,
                            $passwordHash,
                            $userStatus
                        );

                        $stmt->execute();

                        $userId = $stmt->insert_id;

                        $stmt->close();


                        $stmt = $conn->prepare("
                            INSERT INTO students
                                (
                                    user_id,
                                    institution_id,
                                    cohort_id,
                                    student_code,
                                    course,
                                    academic_year,
                                    status
                                )
                            VALUES
                                (?, ?, ?, ?, ?, ?, ?)
                        ");

                        $stmt->bind_param(
                            'iiissss',
                            $userId,
                            $institutionId,
                            $cohortId,
                            $studentCode,
                            $course,
                            $academicYear,
                            $status
                        );

                        $stmt->execute();

                        $stmt->close();

                        $conn->commit();

                        $message =
                            'Student added successfully.';

                    } catch (Throwable $e) {

                        $conn->rollback();

                        $error =
                            'Unable to create the student.';
                    }
                }
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| SUMMARY COUNTS
|--------------------------------------------------------------------------
*/

$activeCount = 0;
$inactiveCount = 0;
$totalCount = 0;

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'active') AS active_count,
        SUM(status = 'inactive') AS inactive_count
    FROM students
");

$stmt->execute();

$result = $stmt->get_result();
$summary = $result->fetch_assoc();

$stmt->close();

$totalCount = (int) ($summary['total'] ?? 0);
$activeCount = (int) ($summary['active_count'] ?? 0);
$inactiveCount = (int) ($summary['inactive_count'] ?? 0);


/*
|--------------------------------------------------------------------------
| LOAD INSTITUTIONS
|--------------------------------------------------------------------------
*/

$institutions = [];

$result = $conn->query("
    SELECT
        id,
        name,
        code
    FROM institutions
    WHERE status != 'inactive'
    ORDER BY name ASC
");

if ($result) {

    while ($row = $result->fetch_assoc()) {
        $institutions[] = $row;
    }
}


/*
|--------------------------------------------------------------------------
| LOAD COHORTS
|--------------------------------------------------------------------------
*/

$cohorts = [];

$result = $conn->query("
    SELECT
        id,
        institution_id,
        name,
        status
    FROM cohorts
    WHERE status != 'inactive'
    ORDER BY name ASC
");

if ($result) {

    while ($row = $result->fetch_assoc()) {
        $cohorts[] = $row;
    }
}


/*
|--------------------------------------------------------------------------
| LOAD STUDENT DIRECTORY
|--------------------------------------------------------------------------
*/

$students = [];

$sql = "
    SELECT
        s.id,
        s.student_code,
        s.course,
        s.academic_year,
        s.status,

        u.name AS student_name,
        u.email AS student_email,

        i.name AS institution_name,
        i.code AS institution_code,

        c.name AS cohort_name,

        p.name AS programme_name

    FROM students s

    INNER JOIN users u
        ON u.id = s.user_id

    INNER JOIN institutions i
        ON i.id = s.institution_id

    INNER JOIN cohorts c
        ON c.id = s.cohort_id

    LEFT JOIN programmes p
        ON p.id = c.programme_id

    ORDER BY
        s.id DESC
";

$result = $conn->query($sql);

if ($result) {

    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
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

    <title>Students · Admin · AIraut</title>

    <link
        rel="stylesheet"
        href="style.css"
    >

    <style>

        .admin-form-panel {
            margin-top: 42px;
            padding: 32px;
            border: 1px solid #d9dfe2;
            background: #fafaf8;
        }

        .admin-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
            margin-top: 24px;
        }

        .admin-form-field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .admin-form-field.full {
            grid-column: 1 / -1;
        }

        .admin-form-field label {
            font-size: 12px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: #486581;
            font-weight: 700;
        }

        .admin-form-field input,
        .admin-form-field select {
            width: 100%;
            box-sizing: border-box;
            padding: 13px 14px;
            border: 1px solid #cfd8dc;
            background: #ffffff;
            color: #163654;
            font: inherit;
            outline: none;
        }

        .admin-form-field input:focus,
        .admin-form-field select:focus {
            border-color: #1687a7;
        }

        .admin-form-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 24px;
        }

        .admin-form-message {
            margin-top: 20px;
            padding: 13px 16px;
            border-left: 3px solid #1ca58d;
            background: #f2faf7;
            color: #245b50;
        }

        .admin-form-error {
            margin-top: 20px;
            padding: 13px 16px;
            border-left: 3px solid #b35c4b;
            background: #fff7f4;
            color: #7b3f34;
        }

        .admin-empty-state {
            padding: 50px 24px;
            text-align: center;
            color: #66809a;
        }

        .directory-search {
            min-width: 260px;
            padding: 11px 13px;
            border: 1px solid #d0d9dd;
            background: #ffffff;
            color: #163654;
            font: inherit;
            outline: none;
        }

        .directory-search:focus {
            border-color: #1687a7;
        }

        .student-secondary {
            display: block;
            margin-top: 5px;
            font-size: 12px;
            color: #7890a3;
        }

        .status-inactive {
            background: #eeeeec;
            color: #69747b;
        }

        .admin-students-table .admin-table-row {
            grid-template-columns:
                1.5fr
                1.2fr
                1fr
                1.2fr
                .8fr
                .6fr;
        }

        @media (max-width: 900px) {

            .admin-form-grid {
                grid-template-columns: 1fr;
            }

            .admin-form-field.full {
                grid-column: auto;
            }

            .directory-heading {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
            }

            .directory-search {
                width: 100%;
                min-width: 0;
                box-sizing: border-box;
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
            <span>SYSTEM MANAGEMENT</span>
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
                SYSTEM MANAGEMENT
            </div>

            <h1>
                Students.
            </h1>

            <p class="platform-intro-text">
                Manage student access, participation and programme
                activity across AIraut institutions.
            </p>

        </div>


        <button
            type="button"
            class="primary-button"
            onclick="document.getElementById('addStudentPanel').scrollIntoView({behavior:'smooth'})"
        >
            + Add Student
        </button>


    </section>



    <!-- MESSAGES -->

    <?php if ($message): ?>

        <div class="admin-form-message">
            <?= htmlspecialchars($message) ?>
        </div>

    <?php endif; ?>


    <?php if ($error): ?>

        <div class="admin-form-error">
            <?= htmlspecialchars($error) ?>
        </div>

    <?php endif; ?>



    <!-- SUMMARY -->

    <section class="admin-stat-strip">


        <div class="admin-stat">

            <span class="stat-label">
                ACTIVE
            </span>

            <strong>
                <?= number_format($activeCount) ?>
            </strong>

        </div>


        <div class="admin-stat">

            <span class="stat-label">
                INACTIVE
            </span>

            <strong>
                <?= number_format($inactiveCount) ?>
            </strong>

        </div>


        <div class="admin-stat">

            <span class="stat-label">
                TOTAL
            </span>

            <strong>
                <?= number_format($totalCount) ?>
            </strong>

        </div>


    </section>



    <!-- DIRECTORY -->

    <section class="admin-directory">


        <div class="directory-heading">


            <div>

                <div class="eyebrow">
                    STUDENT DIRECTORY
                </div>

                <h2>
                    All students.
                </h2>

            </div>


            <input
                type="search"
                id="studentSearch"
                class="directory-search"
                placeholder="Search students..."
                aria-label="Search students"
            >


        </div>



        <!-- TABLE -->

        <div class="admin-table-wrap">


            <div
                class="admin-table admin-students-table"
                id="studentTable"
            >


                <!-- HEADER -->

                <div class="admin-table-row admin-table-header">

                    <div>
                        STUDENT
                    </div>

                    <div>
                        INSTITUTION
                    </div>

                    <div>
                        COHORT
                    </div>

                    <div>
                        PROGRAMME
                    </div>

                    <div>
                        STATUS
                    </div>

                    <div>
                    </div>

                </div>



                <?php if (count($students) > 0): ?>


                    <?php foreach ($students as $student): ?>


                        <?php

                        $status = $student['status'];

                        $statusLabel = ucfirst($status);

                        $searchText = strtolower(
                            $student['student_name'] . ' ' .
                            $student['student_code'] . ' ' .
                            $student['student_email'] . ' ' .
                            $student['institution_name'] . ' ' .
                            $student['institution_code'] . ' ' .
                            $student['cohort_name'] . ' ' .
                            ($student['programme_name'] ?? '') . ' ' .
                            ($student['course'] ?? '') . ' ' .
                            ($student['academic_year'] ?? '') . ' ' .
                            $status
                        );

                        ?>


                        <div
                            class="admin-table-row student-row"
                            data-search="<?= htmlspecialchars(
                                $searchText
                            ) ?>"
                        >


                            <div class="table-primary">

                                <strong>
                                    <?= htmlspecialchars(
                                        $student['student_name']
                                    ) ?>
                                </strong>

                                <span>
                                    Student ID ·
                                    <?= htmlspecialchars(
                                        $student['student_code']
                                    ) ?>
                                </span>

                                <span class="student-secondary">
                                    <?= htmlspecialchars(
                                        $student['student_email']
                                    ) ?>
                                </span>

                            </div>


                            <div>

                                <?= htmlspecialchars(
                                    $student['institution_name']
                                ) ?>

                            </div>


                            <div>

                                <?= htmlspecialchars(
                                    $student['cohort_name']
                                ) ?>

                            </div>


                            <div>

                                <?= !empty(
                                    $student['programme_name']
                                )
                                    ? htmlspecialchars(
                                        $student['programme_name']
                                    )
                                    : '—'
                                ?>

                            </div>


                            <div>

                                <span
                                    class="status-badge <?= $status === 'active'
                                        ? 'status-active'
                                        : 'status-inactive' ?>"
                                >
                                    <?= htmlspecialchars(
                                        $statusLabel
                                    ) ?>
                                </span>

                            </div>


                            <div class="table-action">

                                <span>
                                    View →
                                </span>

                            </div>


                        </div>


                    <?php endforeach; ?>


                    <div
                        id="noSearchResults"
                        class="admin-empty-state"
                        style="display:none;"
                    >

                        No students match your search.

                    </div>


                <?php else: ?>


                    <div class="admin-empty-state">

                        No students have been added yet.

                    </div>


                <?php endif; ?>


            </div>

        </div>


    </section>



    <!-- ADD STUDENT -->

    <section
        class="admin-form-panel"
        id="addStudentPanel"
    >


        <div>

            <div class="eyebrow">
                NEXT STEP
            </div>

            <h2>
                Add a student.
            </h2>

            <p>
                Create student access and connect the learner
                with an institution and cohort.
            </p>

        </div>



        <form
            method="POST"
            action="admin-students.php"
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


            <div class="admin-form-grid">


                <!-- NAME -->

                <div class="admin-form-field">

                    <label for="name">
                        Student Name
                    </label>

                    <input
                        type="text"
                        id="name"
                        name="name"
                        placeholder="e.g. Aishwarya Sharma"
                        maxlength="150"
                        required
                    >

                </div>



                <!-- EMAIL -->

                <div class="admin-form-field">

                    <label for="email">
                        Email
                    </label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="student@example.com"
                        maxlength="190"
                        required
                    >

                </div>



                <!-- PASSWORD -->

                <div class="admin-form-field">

                    <label for="password">
                        Initial Password
                    </label>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Minimum 8 characters"
                        minlength="8"
                        required
                    >

                </div>



                <!-- STUDENT CODE -->

                <div class="admin-form-field">

                    <label for="student_code">
                        Student Code
                    </label>

                    <input
                        type="text"
                        id="student_code"
                        name="student_code"
                        placeholder="e.g. STU-002"
                        maxlength="50"
                        required
                    >

                </div>



                <!-- INSTITUTION -->

                <div class="admin-form-field">

                    <label for="institutionSelect">
                        Institution
                    </label>

                    <select
                        id="institutionSelect"
                        name="institution_id"
                        required
                    >

                        <option value="">
                            Select institution
                        </option>


                        <?php foreach (
                            $institutions as $institution
                        ): ?>

                            <option
                                value="<?= (int) $institution['id'] ?>"
                            >

                                <?= htmlspecialchars(
                                    $institution['name']
                                ) ?>

                                ·

                                <?= htmlspecialchars(
                                    $institution['code']
                                ) ?>

                            </option>

                        <?php endforeach; ?>


                    </select>

                </div>



                <!-- COHORT -->

                <div class="admin-form-field">

                    <label for="cohortSelect">
                        Cohort
                    </label>

                    <select
                        id="cohortSelect"
                        name="cohort_id"
                        required
                    >

                        <option value="">
                            Select cohort
                        </option>


                        <?php foreach ($cohorts as $cohort): ?>

                            <option
                                value="<?= (int) $cohort['id'] ?>"
                                data-institution="<?= (int) $cohort['institution_id'] ?>"
                            >

                                <?= htmlspecialchars(
                                    $cohort['name']
                                ) ?>

                            </option>

                        <?php endforeach; ?>


                    </select>

                </div>



                <!-- COURSE -->

                <div class="admin-form-field">

                    <label for="course">
                        Course
                    </label>

                    <input
                        type="text"
                        id="course"
                        name="course"
                        placeholder="e.g. Computer Engineering"
                        maxlength="150"
                    >

                </div>



                <!-- ACADEMIC YEAR -->

                <div class="admin-form-field">

                    <label for="academic_year">
                        Academic Year
                    </label>

                    <input
                        type="text"
                        id="academic_year"
                        name="academic_year"
                        placeholder="e.g. 2026–27"
                        maxlength="50"
                    >

                </div>



                <!-- STATUS -->

                <div class="admin-form-field full">

                    <label for="status">
                        Status
                    </label>

                    <select
                        id="status"
                        name="status"
                    >

                        <option value="active">
                            Active
                        </option>

                        <option value="inactive">
                            Inactive
                        </option>

                    </select>

                </div>


            </div>



            <div class="admin-form-actions">

                <button
                    type="submit"
                    class="primary-button"
                >
                    Add Student →
                </button>

            </div>


        </form>


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
        ADMIN · STUDENTS
    </div>


</footer>



<script>

/*
|--------------------------------------------------------------------------
| STUDENT SEARCH
|--------------------------------------------------------------------------
*/

const searchInput =
    document.getElementById('studentSearch');

const studentRows =
    document.querySelectorAll('.student-row');

const noSearchResults =
    document.getElementById('noSearchResults');


if (searchInput) {

    searchInput.addEventListener(
        'input',
        function () {

            const query =
                this.value
                    .toLowerCase()
                    .trim();

            let visibleRows = 0;


            studentRows.forEach(
                function (row) {

                    const searchableText =
                        row.getAttribute(
                            'data-search'
                        ) || '';

                    const matches =
                        searchableText.includes(
                            query
                        );

                    row.style.display =
                        matches
                            ? ''
                            : 'none';

                    if (matches) {
                        visibleRows++;
                    }

                }
            );


            if (noSearchResults) {

                noSearchResults.style.display =
                    visibleRows === 0 &&
                    query !== ''
                        ? 'block'
                        : 'none';

            }

        }
    );

}


/*
|--------------------------------------------------------------------------
| FILTER COHORTS BY INSTITUTION
|--------------------------------------------------------------------------
*/

const institutionSelect =
    document.getElementById(
        'institutionSelect'
    );

const cohortSelect =
    document.getElementById(
        'cohortSelect'
    );


if (institutionSelect && cohortSelect) {

    institutionSelect.addEventListener(
        'change',
        function () {

            const institutionId =
                this.value;


            const options =
                cohortSelect.querySelectorAll(
                    'option[data-institution]'
                );


            cohortSelect.value = '';


            options.forEach(
                function (option) {

                    const matches =
                        option.getAttribute(
                            'data-institution'
                        ) === institutionId;

                    option.hidden =
                        institutionId !== '' &&
                        !matches;

                }
            );

        }
    );

}

</script>


</body>

</html>
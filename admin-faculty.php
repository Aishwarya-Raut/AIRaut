<?php

require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/config/database.php';

require_role('admin');

$message = '';
$error = '';

/*
|--------------------------------------------------------------------------
| ADD FACULTY
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    require_csrf_token();

    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $institutionId = (int) ($_POST['institution_id'] ?? 0);
    $facultyCode = strtoupper(trim($_POST['faculty_code'] ?? ''));
    $designation = trim($_POST['designation'] ?? '');
    $status = $_POST['status'] ?? 'active';

    if ($name === '') {

        $error = 'Faculty name is required.';

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = 'Please enter a valid email address.';

    } elseif (strlen($password) < 8) {

        $error = 'Password must contain at least 8 characters.';

    } elseif ($institutionId <= 0) {

        $error = 'Please select an institution.';

    } elseif ($facultyCode === '') {

        $error = 'Faculty code is required.';

    } elseif (!in_array($status, ['active', 'inactive'], true)) {

        $error = 'Invalid faculty status.';

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


        /*
        |--------------------------------------------------------------------------
        | Check faculty code
        |--------------------------------------------------------------------------
        */

        if ($existingUser) {

            $error = 'A user with this email already exists.';

        } else {

            $stmt = $conn->prepare("
                SELECT id
                FROM faculty
                WHERE faculty_code = ?
                LIMIT 1
            ");

            $stmt->bind_param('s', $facultyCode);
            $stmt->execute();

            $result = $stmt->get_result();
            $existingFaculty = $result->fetch_assoc();

            $stmt->close();

            if ($existingFaculty) {

                $error = 'A faculty member with this code already exists.';

            } else {

                /*
                |--------------------------------------------------------------------------
                | Verify institution
                |--------------------------------------------------------------------------
                */

                $stmt = $conn->prepare("
                    SELECT id
                    FROM institutions
                    WHERE id = ?
                    LIMIT 1
                ");

                $stmt->bind_param('i', $institutionId);
                $stmt->execute();

                $result = $stmt->get_result();
                $institutionExists = $result->fetch_assoc();

                $stmt->close();


                if (!$institutionExists) {

                    $error = 'Selected institution was not found.';

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | Create user + faculty record
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
                                (?, ?, ?, 'faculty', ?)
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
                            INSERT INTO faculty
                                (
                                    user_id,
                                    institution_id,
                                    faculty_code,
                                    designation,
                                    status
                                )
                            VALUES
                                (?, ?, ?, ?, ?)
                        ");

                        $stmt->bind_param(
                            'iisss',
                            $userId,
                            $institutionId,
                            $facultyCode,
                            $designation,
                            $status
                        );

                        $stmt->execute();

                        $stmt->close();

                        $conn->commit();

                        $message =
                            'Faculty member added successfully.';

                    } catch (Throwable $e) {

                        $conn->rollback();

                        $error =
                            'Unable to create the faculty member.';
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
    FROM faculty
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
| LOAD FACULTY DIRECTORY
|--------------------------------------------------------------------------
*/

$facultyMembers = [];

$sql = "
    SELECT
        f.id,
        f.faculty_code,
        f.designation,
        f.status,

        u.name AS faculty_name,
        u.email AS faculty_email,

        i.name AS institution_name,
        i.code AS institution_code,

        COUNT(DISTINCT fc.cohort_id) AS cohort_count,

        COUNT(
            DISTINCT CASE
                WHEN s.status = 'active'
                THEN s.id
            END
        ) AS student_count

    FROM faculty f

    INNER JOIN users u
        ON u.id = f.user_id

    INNER JOIN institutions i
        ON i.id = f.institution_id

    LEFT JOIN faculty_cohorts fc
        ON fc.faculty_id = f.id

    LEFT JOIN students s
        ON s.cohort_id = fc.cohort_id

    GROUP BY
        f.id,
        f.faculty_code,
        f.designation,
        f.status,
        u.name,
        u.email,
        i.name,
        i.code

    ORDER BY
        f.id DESC
";

$result = $conn->query($sql);

if ($result) {

    while ($row = $result->fetch_assoc()) {
        $facultyMembers[] = $row;
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

    <title>Faculty | AIraut Admin</title>

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

        .admin-search {
            min-width: 260px;
        }

        .admin-search input {
            width: 100%;
            box-sizing: border-box;
            padding: 11px 13px;
            border: 1px solid #d0d9dd;
            background: #ffffff;
            font: inherit;
            color: #163654;
            outline: none;
        }

        .admin-search input:focus {
            border-color: #1687a7;
        }

        .admin-status-badge.inactive {
            background: #eeeeec;
            color: #69747b;
        }

        .faculty-contact {
            display: block;
            margin-top: 5px;
            font-size: 12px;
            color: #7890a3;
        }

        @media (max-width: 800px) {

            .admin-form-grid {
                grid-template-columns: 1fr;
            }

            .admin-form-field.full {
                grid-column: auto;
            }

            .admin-section-heading {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
            }

            .admin-search {
                width: 100%;
                min-width: 0;
            }

        }

    </style>

</head>


<body>


<!-- HEADER -->

<header class="platform-header">

    <a
        href="admin-dashboard.php"
        class="platform-brand"
    >

        <img
            src="assets/airaut-logo.png"
            alt="AIraut"
        >

    </a>


    <div class="platform-header-right">

        <span class="platform-role">
            ADMIN
        </span>

        <a
            href="admin-dashboard.php"
            class="platform-back"
        >
            Dashboard
        </a>

    </div>

</header>



<!-- MAIN -->

<main class="platform-page admin-list-page">


    <!-- PAGE INTRO -->

    <section class="platform-intro">

        <div>

            <p class="platform-eyebrow">
                System Management
            </p>

            <h1>
                Faculty.
            </h1>

            <p class="platform-intro-text">
                Manage faculty access, assignments and participation
                across AIraut institutions.
            </p>

        </div>


        <button
            class="admin-primary-button"
            type="button"
            onclick="document.getElementById('addFacultyPanel').scrollIntoView({behavior:'smooth'})"
        >
            + Add Faculty
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

    <section class="admin-list-summary">

        <div>

            <span>
                ACTIVE
            </span>

            <strong>
                <?= str_pad(
                    (string) $activeCount,
                    2,
                    '0',
                    STR_PAD_LEFT
                ) ?>
            </strong>

        </div>


        <div>

            <span>
                INACTIVE
            </span>

            <strong>
                <?= str_pad(
                    (string) $inactiveCount,
                    2,
                    '0',
                    STR_PAD_LEFT
                ) ?>
            </strong>

        </div>


        <div>

            <span>
                TOTAL
            </span>

            <strong>
                <?= str_pad(
                    (string) $totalCount,
                    2,
                    '0',
                    STR_PAD_LEFT
                ) ?>
            </strong>

        </div>

    </section>



    <!-- FACULTY DIRECTORY -->

    <section class="admin-table-section">


        <div class="admin-section-heading admin-list-heading">

            <div>

                <p class="platform-eyebrow">
                    Faculty Directory
                </p>

                <h2>
                    All faculty.
                </h2>

            </div>


            <div class="admin-search">

                <input
                    type="search"
                    id="facultySearch"
                    placeholder="Search faculty..."
                    aria-label="Search faculty"
                >

            </div>

        </div>



        <div class="admin-table-wrap">

            <table class="admin-table">

                <thead>

                    <tr>

                        <th>
                            Faculty
                        </th>

                        <th>
                            Institution
                        </th>

                        <th>
                            Cohorts
                        </th>

                        <th>
                            Students
                        </th>

                        <th>
                            Status
                        </th>

                        <th>
                        </th>

                    </tr>

                </thead>


                <tbody id="facultyTableBody">


                <?php if (count($facultyMembers) > 0): ?>


                    <?php foreach (
                        $facultyMembers as $faculty
                    ): ?>


                        <?php

                        $status = $faculty['status'];

                        $statusLabel = ucfirst($status);

                        $searchText = strtolower(
                            $faculty['faculty_name'] . ' ' .
                            $faculty['faculty_code'] . ' ' .
                            ($faculty['designation'] ?? '') . ' ' .
                            $faculty['faculty_email'] . ' ' .
                            $faculty['institution_name'] . ' ' .
                            $faculty['institution_code'] . ' ' .
                            $status
                        );

                        ?>


                        <tr
                            data-search="<?= htmlspecialchars(
                                $searchText
                            ) ?>"
                        >


                            <td>

                                <strong>
                                    <?= htmlspecialchars(
                                        $faculty['faculty_name']
                                    ) ?>
                                </strong>

                                <small>
                                    Faculty ID ·
                                    <?= htmlspecialchars(
                                        $faculty['faculty_code']
                                    ) ?>
                                </small>


                                <?php if (
                                    !empty($faculty['designation'])
                                ): ?>

                                    <span class="faculty-contact">
                                        <?= htmlspecialchars(
                                            $faculty['designation']
                                        ) ?>
                                    </span>

                                <?php endif; ?>


                                <span class="faculty-contact">
                                    <?= htmlspecialchars(
                                        $faculty['faculty_email']
                                    ) ?>
                                </span>

                            </td>


                            <td>

                                <strong>
                                    <?= htmlspecialchars(
                                        $faculty['institution_name']
                                    ) ?>
                                </strong>

                                <small>
                                    <?= htmlspecialchars(
                                        $faculty['institution_code']
                                    ) ?>
                                </small>

                            </td>


                            <td>

                                <?= str_pad(
                                    (string) (
                                        (int) $faculty['cohort_count']
                                    ),
                                    2,
                                    '0',
                                    STR_PAD_LEFT
                                ) ?>

                            </td>


                            <td>

                                <?= number_format(
                                    (int) $faculty['student_count']
                                ) ?>

                            </td>


                            <td>

                                <span
                                    class="admin-status-badge <?= htmlspecialchars(
                                        $status
                                    ) ?>"
                                >

                                    <?= htmlspecialchars(
                                        $statusLabel
                                    ) ?>

                                </span>

                            </td>


                            <td>

                                <span class="admin-table-action">
                                    View →
                                </span>

                            </td>


                        </tr>


                    <?php endforeach; ?>


                    <tr
                        id="noSearchResults"
                        style="display:none;"
                    >

                        <td
                            colspan="6"
                            class="admin-empty-state"
                        >

                            No faculty members match your search.

                        </td>

                    </tr>


                <?php else: ?>


                    <tr>

                        <td
                            colspan="6"
                            class="admin-empty-state"
                        >

                            No faculty members have been added yet.

                        </td>

                    </tr>


                <?php endif; ?>


                </tbody>

            </table>

        </div>

    </section>



    <!-- ADD FACULTY -->

    <section
        class="admin-form-panel"
        id="addFacultyPanel"
    >

        <div>

            <p class="platform-eyebrow">
                New Faculty
            </p>

            <h2>
                Add a faculty member.
            </h2>

            <p>
                Create faculty access and connect the member with
                an institution.
            </p>

        </div>


        <form
            method="POST"
            action="admin-faculty.php"
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
                        Faculty Name
                    </label>

                    <input
                        type="text"
                        id="name"
                        name="name"
                        placeholder="e.g. Priya Sharma"
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
                        placeholder="faculty@example.com"
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



                <!-- FACULTY CODE -->

                <div class="admin-form-field">

                    <label for="faculty_code">
                        Faculty Code
                    </label>

                    <input
                        type="text"
                        id="faculty_code"
                        name="faculty_code"
                        placeholder="e.g. FAC-002"
                        maxlength="50"
                        required
                    >

                </div>



                <!-- INSTITUTION -->

                <div class="admin-form-field">

                    <label for="institution_id">
                        Institution
                    </label>

                    <select
                        id="institution_id"
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



                <!-- DESIGNATION -->

                <div class="admin-form-field">

                    <label for="designation">
                        Designation
                    </label>

                    <input
                        type="text"
                        id="designation"
                        name="designation"
                        placeholder="e.g. Faculty Coordinator"
                        maxlength="150"
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
                    class="admin-primary-button"
                >
                    Add Faculty →
                </button>

            </div>


        </form>

    </section>


</main>



<!-- FOOTER -->

<footer class="platform-footer">

    <div>

        <strong>
            AIraut
        </strong>

        <span>
            Future Readiness Development System
        </span>

    </div>


    <span>
        ADMIN · FACULTY
    </span>

</footer>



<script>

const searchInput =
    document.getElementById('facultySearch');

const rows =
    document.querySelectorAll(
        '#facultyTableBody tr[data-search]'
    );

const noResults =
    document.getElementById('noSearchResults');


if (searchInput) {

    searchInput.addEventListener(
        'input',
        function () {

            const query =
                this.value.toLowerCase().trim();

            let visibleRows = 0;


            rows.forEach(function (row) {

                const searchableText =
                    row.getAttribute(
                        'data-search'
                    ) || '';

                const matches =
                    searchableText.includes(query);

                row.style.display =
                    matches ? '' : 'none';

                if (matches) {
                    visibleRows++;
                }

            });


            if (noResults) {

                noResults.style.display =
                    visibleRows === 0 &&
                    query !== ''
                        ? ''
                        : 'none';

            }

        }
    );

}

</script>


</body>

</html>
<?php

require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/config/database.php';

require_role('admin');

$message = '';
$error = '';

/*
|--------------------------------------------------------------------------
| CREATE COHORT
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    require_csrf_token();

    $name = trim($_POST['name'] ?? '');
    $institutionId = (int) ($_POST['institution_id'] ?? 0);
    $programmeId = (int) ($_POST['programme_id'] ?? 0);
    $startDate = trim($_POST['start_date'] ?? '');
    $endDate = trim($_POST['end_date'] ?? '');
    $status = $_POST['status'] ?? 'upcoming';

    if ($name === '') {

        $error = 'Cohort name is required.';

    } elseif ($institutionId <= 0) {

        $error = 'Please select an institution.';

    } elseif ($programmeId <= 0) {

        $error = 'Please select a programme.';

    } elseif (!in_array(
        $status,
        ['upcoming', 'active', 'completed', 'inactive'],
        true
    )) {

        $error = 'Invalid cohort status.';

    } elseif (
        $startDate !== '' &&
        $endDate !== '' &&
        $endDate < $startDate
    ) {

        $error = 'End date cannot be earlier than the start date.';

    } else {

        /*
        |--------------------------------------------------------------------------
        | Verify institution exists
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

        $institutionResult = $stmt->get_result();
        $institutionExists = $institutionResult->fetch_assoc();

        $stmt->close();


        /*
        |--------------------------------------------------------------------------
        | Verify programme exists
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT id
            FROM programmes
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->bind_param('i', $programmeId);
        $stmt->execute();

        $programmeResult = $stmt->get_result();
        $programmeExists = $programmeResult->fetch_assoc();

        $stmt->close();


        if (!$institutionExists) {

            $error = 'Selected institution was not found.';

        } elseif (!$programmeExists) {

            $error = 'Selected programme was not found.';

        } else {

            /*
            |--------------------------------------------------------------------------
            | Create cohort
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                INSERT INTO cohorts
                    (
                        institution_id,
                        programme_id,
                        name,
                        start_date,
                        end_date,
                        status
                    )
                VALUES
                    (?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?)
            ");

            $stmt->bind_param(
                'iissss',
                $institutionId,
                $programmeId,
                $name,
                $startDate,
                $endDate,
                $status
            );

            if ($stmt->execute()) {

                $message = 'Cohort created successfully.';

            } else {

                $error = 'Unable to create the cohort.';
            }

            $stmt->close();
        }
    }
}


/*
|--------------------------------------------------------------------------
| SUMMARY COUNTS
|--------------------------------------------------------------------------
*/

$activeCount = 0;
$completedCount = 0;
$totalCount = 0;

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'active') AS active_count,
        SUM(status = 'completed') AS completed_count
    FROM cohorts
");

$stmt->execute();

$result = $stmt->get_result();
$summary = $result->fetch_assoc();

$stmt->close();

$totalCount = (int) ($summary['total'] ?? 0);
$activeCount = (int) ($summary['active_count'] ?? 0);
$completedCount = (int) ($summary['completed_count'] ?? 0);


/*
|--------------------------------------------------------------------------
| LOAD INSTITUTIONS FOR CREATE FORM
|--------------------------------------------------------------------------
*/

$institutions = [];

$result = $conn->query("
    SELECT
        id,
        name,
        code,
        status
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
| LOAD PROGRAMMES FOR CREATE FORM
|--------------------------------------------------------------------------
*/

$programmes = [];

$result = $conn->query("
    SELECT
        id,
        name,
        code,
        status
    FROM programmes
    WHERE status != 'inactive'
    ORDER BY name ASC
");

if ($result) {

    while ($row = $result->fetch_assoc()) {
        $programmes[] = $row;
    }
}


/*
|--------------------------------------------------------------------------
| LOAD COHORT DIRECTORY
|--------------------------------------------------------------------------
*/

$cohorts = [];

$sql = "
    SELECT
        c.id,
        c.name,
        c.start_date,
        c.end_date,
        c.status,

        i.name AS institution_name,
        i.code AS institution_code,

        p.name AS programme_name,
        p.code AS programme_code,

        COUNT(DISTINCT CASE
            WHEN s.status = 'active'
            THEN s.id
        END) AS student_count

    FROM cohorts c

    INNER JOIN institutions i
        ON i.id = c.institution_id

    LEFT JOIN programmes p
        ON p.id = c.programme_id

    LEFT JOIN students s
        ON s.cohort_id = c.id

    GROUP BY
        c.id,
        c.name,
        c.start_date,
        c.end_date,
        c.status,
        i.name,
        i.code,
        p.name,
        p.code

    ORDER BY
        c.id DESC
";

$result = $conn->query($sql);

if ($result) {

    while ($row = $result->fetch_assoc()) {
        $cohorts[] = $row;
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

    <title>Cohorts | AIraut Admin</title>

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

        .admin-form-field.full {
            grid-column: 1 / -1;
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

        .admin-status-badge.completed {
            background: #eef4f1;
            color: #397060;
        }

        .admin-status-badge.upcoming {
            background: #f3f0e8;
            color: #806d45;
        }

        .admin-status-badge.inactive {
            background: #eeeeec;
            color: #69747b;
        }

        .cohort-date {
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
                Cohorts.
            </h1>

            <p class="platform-intro-text">
                Create, organise and manage student cohorts across
                AIraut institutions.
            </p>

        </div>


        <button
            class="admin-primary-button"
            type="button"
            onclick="document.getElementById('createCohortPanel').scrollIntoView({behavior:'smooth'})"
        >
            + Create Cohort
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
                COMPLETED
            </span>

            <strong>
                <?= str_pad(
                    (string) $completedCount,
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



    <!-- COHORT DIRECTORY -->

    <section class="admin-table-section">


        <div class="admin-section-heading admin-list-heading">

            <div>

                <p class="platform-eyebrow">
                    Cohort Directory
                </p>

                <h2>
                    All cohorts.
                </h2>

            </div>


            <div class="admin-search">

                <input
                    type="search"
                    id="cohortSearch"
                    placeholder="Search cohorts..."
                    aria-label="Search cohorts"
                >

            </div>

        </div>



        <div class="admin-table-wrap">

            <table class="admin-table">

                <thead>

                    <tr>

                        <th>
                            Cohort
                        </th>

                        <th>
                            Institution
                        </th>

                        <th>
                            Programme
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


                <tbody id="cohortTableBody">


                <?php if (count($cohorts) > 0): ?>


                    <?php foreach ($cohorts as $cohort): ?>


                        <?php

                        $status = $cohort['status'];

                        $statusLabel = ucfirst($status);

                        if ($status === 'upcoming') {
                            $statusLabel = 'Upcoming';
                        }

                        $searchText = strtolower(
                            $cohort['name'] . ' ' .
                            ($cohort['institution_name'] ?? '') . ' ' .
                            ($cohort['institution_code'] ?? '') . ' ' .
                            ($cohort['programme_name'] ?? '') . ' ' .
                            ($cohort['programme_code'] ?? '') . ' ' .
                            $status
                        );

                        ?>


                        <tr
                            data-search="<?= htmlspecialchars($searchText) ?>"
                        >


                            <td>

                                <strong>
                                    <?= htmlspecialchars(
                                        $cohort['name']
                                    ) ?>
                                </strong>

                                <small>
                                    Cohort ID ·
                                    COH-<?= str_pad(
                                        (string) $cohort['id'],
                                        3,
                                        '0',
                                        STR_PAD_LEFT
                                    ) ?>
                                </small>


                                <?php if (
                                    !empty($cohort['start_date']) ||
                                    !empty($cohort['end_date'])
                                ): ?>

                                    <span class="cohort-date">

                                        <?= !empty($cohort['start_date'])
                                            ? htmlspecialchars(
                                                date(
                                                    'd M Y',
                                                    strtotime(
                                                        $cohort['start_date']
                                                    )
                                                )
                                            )
                                            : '—'
                                        ?>

                                        →

                                        <?= !empty($cohort['end_date'])
                                            ? htmlspecialchars(
                                                date(
                                                    'd M Y',
                                                    strtotime(
                                                        $cohort['end_date']
                                                    )
                                                )
                                            )
                                            : '—'
                                        ?>

                                    </span>

                                <?php endif; ?>

                            </td>


                            <td>

                                <strong>
                                    <?= htmlspecialchars(
                                        $cohort['institution_name']
                                    ) ?>
                                </strong>

                                <small>
                                    <?= htmlspecialchars(
                                        $cohort['institution_code']
                                    ) ?>
                                </small>

                            </td>


                            <td>

                                <?php if (
                                    !empty($cohort['programme_name'])
                                ): ?>

                                    <strong>
                                        <?= htmlspecialchars(
                                            $cohort['programme_name']
                                        ) ?>
                                    </strong>

                                    <?php if (
                                        !empty($cohort['programme_code'])
                                    ): ?>

                                        <small>
                                            <?= htmlspecialchars(
                                                $cohort['programme_code']
                                            ) ?>
                                        </small>

                                    <?php endif; ?>

                                <?php else: ?>

                                    <span>
                                        No programme
                                    </span>

                                <?php endif; ?>

                            </td>


                            <td>

                                <?= number_format(
                                    (int) $cohort['student_count']
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

                            No cohorts match your search.

                        </td>

                    </tr>


                <?php else: ?>


                    <tr>

                        <td
                            colspan="6"
                            class="admin-empty-state"
                        >

                            No cohorts have been created yet.

                        </td>

                    </tr>


                <?php endif; ?>


                </tbody>

            </table>

        </div>

    </section>



    <!-- CREATE COHORT -->

    <section
        class="admin-form-panel"
        id="createCohortPanel"
    >

        <div>

            <p class="platform-eyebrow">
                New Cohort
            </p>

            <h2>
                Create a new cohort.
            </h2>

            <p>
                Connect a cohort with an institution and AIraut
                programme, then define its learning period.
            </p>

        </div>


        <form
            method="POST"
            action="admin-cohorts.php"
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


                <!-- COHORT NAME -->

                <div class="admin-form-field">

                    <label for="name">
                        Cohort Name
                    </label>

                    <input
                        type="text"
                        id="name"
                        name="name"
                        placeholder="e.g. Future Ready Cohort 2026"
                        maxlength="150"
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



                <!-- PROGRAMME -->

                <div class="admin-form-field">

                    <label for="programme_id">
                        Programme
                    </label>

                    <select
                        id="programme_id"
                        name="programme_id"
                        required
                    >

                        <option value="">
                            Select programme
                        </option>


                        <?php foreach (
                            $programmes as $programme
                        ): ?>

                            <option
                                value="<?= (int) $programme['id'] ?>"
                            >

                                <?= htmlspecialchars(
                                    $programme['name']
                                ) ?>

                                ·

                                <?= htmlspecialchars(
                                    $programme['code']
                                ) ?>

                            </option>

                        <?php endforeach; ?>


                    </select>

                </div>



                <!-- STATUS -->

                <div class="admin-form-field">

                    <label for="status">
                        Status
                    </label>

                    <select
                        id="status"
                        name="status"
                    >

                        <option value="upcoming">
                            Upcoming
                        </option>

                        <option value="active">
                            Active
                        </option>

                        <option value="completed">
                            Completed
                        </option>

                        <option value="inactive">
                            Inactive
                        </option>

                    </select>

                </div>



                <!-- START DATE -->

                <div class="admin-form-field">

                    <label for="start_date">
                        Start Date
                    </label>

                    <input
                        type="date"
                        id="start_date"
                        name="start_date"
                    >

                </div>



                <!-- END DATE -->

                <div class="admin-form-field">

                    <label for="end_date">
                        End Date
                    </label>

                    <input
                        type="date"
                        id="end_date"
                        name="end_date"
                    >

                </div>


            </div>



            <div class="admin-form-actions">

                <button
                    type="submit"
                    class="admin-primary-button"
                >
                    Create Cohort →
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
        ADMIN · COHORTS
    </span>

</footer>



<script>

const searchInput =
    document.getElementById('cohortSearch');

const rows =
    document.querySelectorAll(
        '#cohortTableBody tr[data-search]'
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
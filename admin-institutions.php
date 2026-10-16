<?php

require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/config/database.php';

require_role('admin');

$message = '';
$error = '';

/*
|--------------------------------------------------------------------------
| ADD INSTITUTION
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    require_csrf_token();

    $name = trim($_POST['name'] ?? '');
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $contactName = trim($_POST['contact_name'] ?? '');
    $contactEmail = trim($_POST['contact_email'] ?? '');
    $status = $_POST['status'] ?? 'onboarding';

    if ($name === '' || $code === '') {
        $error = 'Institution name and institution code are required.';
    } elseif (!in_array($status, ['active', 'onboarding', 'inactive'], true)) {
        $error = 'Invalid institution status.';
    } elseif ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid contact email.';
    } else {

        $stmt = $conn->prepare("
            SELECT id
            FROM institutions
            WHERE code = ?
            LIMIT 1
        ");

        $stmt->bind_param('s', $code);
        $stmt->execute();

        $result = $stmt->get_result();
        $existing = $result->fetch_assoc();

        $stmt->close();

        if ($existing) {

            $error = 'An institution with this code already exists.';

        } else {

            $stmt = $conn->prepare("
                INSERT INTO institutions
                    (name, code, contact_name, contact_email, status)
                VALUES
                    (?, ?, ?, ?, ?)
            ");

            $stmt->bind_param(
                'sssss',
                $name,
                $code,
                $contactName,
                $contactEmail,
                $status
            );

            if ($stmt->execute()) {
                $message = 'Institution added successfully.';
            } else {
                $error = 'Unable to add the institution.';
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
$onboardingCount = 0;
$totalCount = 0;

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'active') AS active_count,
        SUM(status = 'onboarding') AS onboarding_count
    FROM institutions
");

$stmt->execute();

$result = $stmt->get_result();
$summary = $result->fetch_assoc();

$stmt->close();

$totalCount = (int) ($summary['total'] ?? 0);
$activeCount = (int) ($summary['active_count'] ?? 0);
$onboardingCount = (int) ($summary['onboarding_count'] ?? 0);


/*
|--------------------------------------------------------------------------
| INSTITUTION DIRECTORY
|--------------------------------------------------------------------------
|
| Cohort and student counts are calculated directly from the database.
|
*/

$institutions = [];

$sql = "
    SELECT
        i.id,
        i.name,
        i.code,
        i.contact_name,
        i.contact_email,
        i.status,
        i.created_at,

        COUNT(DISTINCT CASE
            WHEN c.status = 'active'
            THEN c.id
        END) AS cohort_count,

        COUNT(DISTINCT CASE
            WHEN s.status = 'active'
            THEN s.id
        END) AS student_count

    FROM institutions i

    LEFT JOIN cohorts c
        ON c.institution_id = i.id

    LEFT JOIN students s
        ON s.institution_id = i.id

    GROUP BY
        i.id,
        i.name,
        i.code,
        i.contact_name,
        i.contact_email,
        i.status,
        i.created_at

    ORDER BY
        i.created_at DESC
";

$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $institutions[] = $row;
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

    <title>Institutions | AIraut Admin</title>

    <link rel="stylesheet" href="style.css">

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
            align-items: center;
            justify-content: flex-end;
            gap: 14px;
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

        .admin-table-search {
            min-width: 260px;
        }

        .admin-table-search input {
            width: 100%;
            box-sizing: border-box;
            padding: 11px 13px;
            border: 1px solid #d0d9dd;
            background: #ffffff;
            font: inherit;
            color: #163654;
            outline: none;
        }

        .admin-table-search input:focus {
            border-color: #1687a7;
        }

        .admin-status-badge.inactive {
            background: #eeeeec;
            color: #69747b;
        }

        @media (max-width: 800px) {

            .admin-form-grid {
                grid-template-columns: 1fr;
            }

            .admin-form-field.full {
                grid-column: auto;
            }

            .admin-list-heading {
                gap: 20px;
                flex-direction: column;
                align-items: flex-start;
            }

            .admin-table-search {
                width: 100%;
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
                Institutions.
            </h1>

            <p class="platform-intro-text">
                Manage institutions using AIraut and oversee their
                programme participation.
            </p>

        </div>


        <button
            class="admin-primary-button"
            type="button"
            onclick="document.getElementById('addInstitutionPanel').scrollIntoView({behavior:'smooth'})"
        >
            + Add Institution
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
                <?= str_pad((string) $activeCount, 2, '0', STR_PAD_LEFT) ?>
            </strong>

        </div>


        <div>

            <span>
                ONBOARDING
            </span>

            <strong>
                <?= str_pad((string) $onboardingCount, 2, '0', STR_PAD_LEFT) ?>
            </strong>

        </div>


        <div>

            <span>
                TOTAL
            </span>

            <strong>
                <?= str_pad((string) $totalCount, 2, '0', STR_PAD_LEFT) ?>
            </strong>

        </div>

    </section>



    <!-- INSTITUTION LIST -->

    <section class="admin-table-section">


        <div class="admin-section-heading admin-list-heading">

            <div>

                <p class="platform-eyebrow">
                    Institution Directory
                </p>

                <h2>
                    All institutions.
                </h2>

            </div>


            <div class="admin-table-search">

                <input
                    type="search"
                    id="institutionSearch"
                    placeholder="Search institutions..."
                    aria-label="Search institutions"
                >

            </div>

        </div>



        <div class="admin-table-wrap">

            <table class="admin-table">

                <thead>

                    <tr>

                        <th>
                            Institution
                        </th>

                        <th>
                            Contact
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


                <tbody id="institutionTableBody">

                <?php if (count($institutions) > 0): ?>

                    <?php foreach ($institutions as $institution): ?>

                        <?php

                        $status = $institution['status'];

                        $statusLabel = ucfirst($status);

                        $statusClass = $status;

                        $searchText = strtolower(
                            $institution['name'] . ' ' .
                            $institution['code'] . ' ' .
                            ($institution['contact_name'] ?? '') . ' ' .
                            ($institution['contact_email'] ?? '') . ' ' .
                            $status
                        );

                        ?>

                        <tr
                            data-search="<?= htmlspecialchars($searchText) ?>"
                        >

                            <td>

                                <strong>
                                    <?= htmlspecialchars($institution['name']) ?>
                                </strong>

                                <small>
                                    Institution ID ·
                                    <?= htmlspecialchars($institution['code']) ?>
                                </small>

                            </td>


                            <td>

                                <?php if (!empty($institution['contact_name'])): ?>

                                    <strong>
                                        <?= htmlspecialchars($institution['contact_name']) ?>
                                    </strong>

                                <?php else: ?>

                                    <span>
                                        —
                                    </span>

                                <?php endif; ?>


                                <?php if (!empty($institution['contact_email'])): ?>

                                    <small>
                                        <?= htmlspecialchars($institution['contact_email']) ?>
                                    </small>

                                <?php endif; ?>

                            </td>


                            <td>

                                <?= str_pad(
                                    (string) ((int) $institution['cohort_count']),
                                    2,
                                    '0',
                                    STR_PAD_LEFT
                                ) ?>

                            </td>


                            <td>

                                <?= number_format(
                                    (int) $institution['student_count']
                                ) ?>

                            </td>


                            <td>

                                <span
                                    class="admin-status-badge <?= htmlspecialchars($statusClass) ?>"
                                >
                                    <?= htmlspecialchars($statusLabel) ?>
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
                            No institutions match your search.
                        </td>

                    </tr>


                <?php else: ?>

                    <tr>

                        <td
                            colspan="6"
                            class="admin-empty-state"
                        >

                            No institutions have been added yet.

                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>



    <!-- ADD INSTITUTION -->

    <section
        class="admin-form-panel"
        id="addInstitutionPanel"
    >

        <div>

            <p class="platform-eyebrow">
                New Institution
            </p>

            <h2>
                Bring another institution onto AIraut.
            </h2>

            <p>
                Add an institution to begin setting up its cohorts,
                faculty and students.
            </p>

        </div>


        <form
            method="POST"
            action="admin-institutions.php"
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


                <div class="admin-form-field">

                    <label for="name">
                        Institution Name
                    </label>

                    <input
                        type="text"
                        id="name"
                        name="name"
                        placeholder="e.g. AIraut Demo Institution"
                        required
                    >

                </div>



                <div class="admin-form-field">

                    <label for="code">
                        Institution Code
                    </label>

                    <input
                        type="text"
                        id="code"
                        name="code"
                        placeholder="e.g. AIR-001"
                        maxlength="50"
                        required
                    >

                </div>



                <div class="admin-form-field">

                    <label for="contact_name">
                        Contact Name
                    </label>

                    <input
                        type="text"
                        id="contact_name"
                        name="contact_name"
                        placeholder="Primary contact"
                    >

                </div>



                <div class="admin-form-field">

                    <label for="contact_email">
                        Contact Email
                    </label>

                    <input
                        type="email"
                        id="contact_email"
                        name="contact_email"
                        placeholder="contact@example.com"
                    >

                </div>



                <div class="admin-form-field full">

                    <label for="status">
                        Status
                    </label>

                    <select
                        id="status"
                        name="status"
                    >

                        <option value="onboarding">
                            Onboarding
                        </option>

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
                    Add Institution →
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
        ADMIN · INSTITUTIONS
    </span>

</footer>



<script>

const searchInput = document.getElementById('institutionSearch');

const rows = document.querySelectorAll(
    '#institutionTableBody tr[data-search]'
);

const noResults = document.getElementById(
    'noSearchResults'
);


if (searchInput) {

    searchInput.addEventListener('input', function () {

        const query = this.value
            .toLowerCase()
            .trim();

        let visibleRows = 0;

        rows.forEach(function (row) {

            const searchableText =
                row.getAttribute('data-search') || '';

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
                visibleRows === 0 && query !== ''
                    ? ''
                    : 'none';

        }

    });

}

</script>


</body>

</html>
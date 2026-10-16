<?php

require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/config/database.php';

require_role('admin');

$message = '';
$error = '';

/*
|--------------------------------------------------------------------------
| ADD PROGRAMME
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    require_csrf_token();

    $name = trim($_POST['name'] ?? '');
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $status = $_POST['status'] ?? 'draft';

    if ($name === '') {

        $error = 'Programme name is required.';

    } elseif ($code === '') {

        $error = 'Programme code is required.';

    } elseif (!in_array(
        $status,
        ['active', 'draft', 'inactive'],
        true
    )) {

        $error = 'Invalid programme status.';

    } else {

        /*
        |--------------------------------------------------------------------------
        | Check programme code
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT id
            FROM programmes
            WHERE code = ?
            LIMIT 1
        ");

        $stmt->bind_param('s', $code);
        $stmt->execute();

        $result = $stmt->get_result();
        $existingProgramme = $result->fetch_assoc();

        $stmt->close();


        if ($existingProgramme) {

            $error =
                'A programme with this code already exists.';

        } else {

            /*
            |--------------------------------------------------------------------------
            | Create programme
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                INSERT INTO programmes
                    (
                        name,
                        code,
                        status
                    )
                VALUES
                    (?, ?, ?)
            ");

            $stmt->bind_param(
                'sss',
                $name,
                $code,
                $status
            );

            if ($stmt->execute()) {

                $message =
                    'Programme added successfully.';

            } else {

                $error =
                    'Unable to add the programme.';
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
$draftCount = 0;
$inactiveCount = 0;
$totalCount = 0;

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'active') AS active_count,
        SUM(status = 'draft') AS draft_count,
        SUM(status = 'inactive') AS inactive_count
    FROM programmes
");

$stmt->execute();

$result = $stmt->get_result();
$summary = $result->fetch_assoc();

$stmt->close();

$totalCount =
    (int) ($summary['total'] ?? 0);

$activeCount =
    (int) ($summary['active_count'] ?? 0);

$draftCount =
    (int) ($summary['draft_count'] ?? 0);

$inactiveCount =
    (int) ($summary['inactive_count'] ?? 0);


/*
|--------------------------------------------------------------------------
| LOAD PROGRAMMES
|--------------------------------------------------------------------------
*/

$programmes = [];

$sql = "
    SELECT
        p.id,
        p.name,
        p.code,
        p.status,

        COUNT(DISTINCT c.institution_id)
            AS institution_count,

        COUNT(DISTINCT c.id)
            AS cohort_count,

        COUNT(DISTINCT CASE
            WHEN s.status = 'active'
            THEN s.id
        END) AS student_count

    FROM programmes p

    LEFT JOIN cohorts c
        ON c.programme_id = p.id

    LEFT JOIN students s
        ON s.cohort_id = c.id

    GROUP BY
        p.id,
        p.name,
        p.code,
        p.status

    ORDER BY
        p.id DESC
";

$result = $conn->query($sql);

if ($result) {

    while ($row = $result->fetch_assoc()) {
        $programmes[] = $row;
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

    <title>Programmes · Admin · AIraut</title>

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

        .programme-secondary {
            display: block;
            margin-top: 5px;
            font-size: 12px;
            color: #7890a3;
        }

        .status-draft {
            background: #f3f0e8;
            color: #806d45;
        }

        .status-inactive {
            background: #eeeeec;
            color: #69747b;
        }

        .admin-programmes-table .admin-table-row {
            grid-template-columns:
                1.7fr
                .8fr
                .8fr
                .8fr
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

            <span>
                SYSTEM MANAGEMENT
            </span>

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
                Programmes.
            </h1>

            <p>
                Manage AIraut programmes, delivery structures and
                programme access across institutions.
            </p>

        </div>


        <button
            type="button"
            class="primary-button"
            onclick="document.getElementById('addProgrammePanel').scrollIntoView({behavior:'smooth'})"
        >
            + Add Programme
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
                <?= str_pad(
                    (string) $activeCount,
                    2,
                    '0',
                    STR_PAD_LEFT
                ) ?>
            </strong>

        </div>


        <div class="admin-stat">

            <span class="stat-label">
                DRAFT
            </span>

            <strong>
                <?= str_pad(
                    (string) $draftCount,
                    2,
                    '0',
                    STR_PAD_LEFT
                ) ?>
            </strong>

        </div>


        <div class="admin-stat">

            <span class="stat-label">
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



    <!-- PROGRAMME DIRECTORY -->

    <section class="admin-directory">


        <div class="directory-heading">


            <div>

                <div class="eyebrow">
                    PROGRAMME DIRECTORY
                </div>

                <h2>
                    All programmes.
                </h2>

            </div>


            <input
                type="search"
                id="programmeSearch"
                class="directory-search"
                placeholder="Search programmes..."
                aria-label="Search programmes"
            >


        </div>



        <!-- TABLE -->

        <div class="admin-table-wrap">


            <div
                class="admin-table admin-programmes-table"
                id="programmeTable"
            >


                <!-- HEADER -->

                <div
                    class="admin-table-row admin-table-header"
                >

                    <div>
                        PROGRAMME
                    </div>

                    <div>
                        INSTITUTIONS
                    </div>

                    <div>
                        COHORTS
                    </div>

                    <div>
                        STUDENTS
                    </div>

                    <div>
                        STATUS
                    </div>

                    <div>
                    </div>

                </div>



                <?php if (count($programmes) > 0): ?>


                    <?php foreach (
                        $programmes as $programme
                    ): ?>


                        <?php

                        $status =
                            $programme['status'];

                        $statusLabel =
                            ucfirst($status);

                        $searchText =
                            strtolower(
                                $programme['name'] . ' ' .
                                $programme['code'] . ' ' .
                                $status
                            );

                        ?>


                        <div
                            class="admin-table-row programme-row"
                            data-search="<?= htmlspecialchars(
                                $searchText
                            ) ?>"
                        >


                            <div class="table-primary">

                                <strong>
                                    <?= htmlspecialchars(
                                        $programme['name']
                                    ) ?>
                                </strong>

                                <span>
                                    Programme ID · PRG-<?= str_pad(
                                        (string) $programme['id'],
                                        3,
                                        '0',
                                        STR_PAD_LEFT
                                    ) ?>
                                </span>

                                <span class="programme-secondary">

                                    Code ·
                                    <?= htmlspecialchars(
                                        $programme['code']
                                    ) ?>

                                </span>

                            </div>


                            <div>

                                <?= str_pad(
                                    (string) (
                                        (int) $programme[
                                            'institution_count'
                                        ]
                                    ),
                                    2,
                                    '0',
                                    STR_PAD_LEFT
                                ) ?>

                            </div>


                            <div>

                                <?= str_pad(
                                    (string) (
                                        (int) $programme[
                                            'cohort_count'
                                        ]
                                    ),
                                    2,
                                    '0',
                                    STR_PAD_LEFT
                                ) ?>

                            </div>


                            <div>

                                <?= number_format(
                                    (int) $programme[
                                        'student_count'
                                    ]
                                ) ?>

                            </div>


                            <div>

                                <span
                                    class="status-badge <?= $status === 'active'
                                        ? 'status-active'
                                        : (
                                            $status === 'draft'
                                                ? 'status-draft'
                                                : 'status-inactive'
                                        ) ?>"
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

                        No programmes match your search.

                    </div>


                <?php else: ?>


                    <div class="admin-empty-state">

                        No programmes have been added yet.

                    </div>


                <?php endif; ?>


            </div>

        </div>


    </section>



    <!-- ADD PROGRAMME -->

    <section
        class="admin-form-panel"
        id="addProgrammePanel"
    >


        <div>

            <div class="eyebrow">
                PROGRAMME STRUCTURE
            </div>

            <h2>
                Add a programme.
            </h2>

            <p>
                Create a programme that can later be connected
                with institutions, cohorts, modules and activities.
            </p>

        </div>



        <form
            method="POST"
            action="admin-programmes.php"
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


                <!-- PROGRAMME NAME -->

                <div class="admin-form-field">

                    <label for="name">
                        Programme Name
                    </label>

                    <input
                        type="text"
                        id="name"
                        name="name"
                        placeholder="e.g. Future Readiness"
                        maxlength="200"
                        required
                    >

                </div>



                <!-- PROGRAMME CODE -->

                <div class="admin-form-field">

                    <label for="code">
                        Programme Code
                    </label>

                    <input
                        type="text"
                        id="code"
                        name="code"
                        placeholder="e.g. FR-001"
                        maxlength="50"
                        required
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

                        <option value="draft">
                            Draft
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
                    class="primary-button"
                >
                    Add Programme →
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
        ADMIN · PROGRAMMES
    </div>


</footer>



<script>

/*
|--------------------------------------------------------------------------
| PROGRAMME SEARCH
|--------------------------------------------------------------------------
*/

const searchInput =
    document.getElementById(
        'programmeSearch'
    );

const programmeRows =
    document.querySelectorAll(
        '.programme-row'
    );

const noSearchResults =
    document.getElementById(
        'noSearchResults'
    );


if (searchInput) {

    searchInput.addEventListener(
        'input',
        function () {

            const query =
                this.value
                    .toLowerCase()
                    .trim();

            let visibleRows = 0;


            programmeRows.forEach(
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

</script>


</body>

</html>
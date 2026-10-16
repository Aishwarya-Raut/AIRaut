<?php

require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/config/database.php';

require_role('admin');

$reportType = $_POST['report_type'] ?? '';
$reportGenerated = false;


/*
|--------------------------------------------------------------------------
| SYSTEM SUMMARY
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM institutions
    WHERE status = 'active'
");
$stmt->execute();
$stmt->bind_result($activeInstitutions);
$stmt->fetch();
$stmt->close();

$activeInstitutions = (int) $activeInstitutions;


$stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM cohorts
    WHERE status = 'active'
");
$stmt->execute();
$stmt->bind_result($activeCohorts);
$stmt->fetch();
$stmt->close();

$activeCohorts = (int) $activeCohorts;


$stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM students
    WHERE status = 'active'
");
$stmt->execute();
$stmt->bind_result($activeStudents);
$stmt->fetch();
$stmt->close();


$stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM programmes
    WHERE status = 'active'
");
$stmt->execute();
$stmt->bind_result($activeProgrammes);
$stmt->fetch();
$stmt->close();


/*
|--------------------------------------------------------------------------
| REPORT GENERATION
|--------------------------------------------------------------------------
*/

$reportData = [];

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array(
        $reportType,
        [
            'institution',
            'cohort',
            'programme',
            'student'
        ],
        true
    )
) {

    require_csrf_token();

    $reportGenerated = true;


    /*
    |--------------------------------------------------------------------------
    | INSTITUTION REPORT
    |--------------------------------------------------------------------------
    */

    if ($reportType === 'institution') {

        $sql = "
            SELECT
                i.name,
                i.code,
                i.status,

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
                i.status

            ORDER BY
                i.name ASC
        ";

        $result = $conn->query($sql);

        if ($result) {

            while ($row = $result->fetch_assoc()) {
                $reportData[] = $row;
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | COHORT REPORT
    |--------------------------------------------------------------------------
    */

    elseif ($reportType === 'cohort') {

        $sql = "
            SELECT
                c.name AS cohort_name,
                i.name AS institution_name,
                p.name AS programme_name,
                c.status,

                COUNT(DISTINCT CASE
                    WHEN s.status = 'active'
                    THEN s.id
                END) AS student_count,

                COUNT(DISTINCT CASE
                    WHEN a.status = 'active'
                    THEN a.id
                END) AS activity_count,

                COUNT(DISTINCT CASE
                    WHEN sub.status IN ('submitted', 'reviewed')
                    THEN sub.id
                END) AS submission_count

            FROM cohorts c

            INNER JOIN institutions i
                ON i.id = c.institution_id

            LEFT JOIN programmes p
                ON p.id = c.programme_id

            LEFT JOIN students s
                ON s.cohort_id = c.id

            LEFT JOIN activities a
                ON a.cohort_id = c.id

            LEFT JOIN submissions sub
                ON sub.activity_id = a.id
                AND sub.student_id = s.id

            GROUP BY
                c.id,
                c.name,
                i.name,
                p.name,
                c.status

            ORDER BY
                c.id DESC
        ";

        $result = $conn->query($sql);

        if ($result) {

            while ($row = $result->fetch_assoc()) {
                $reportData[] = $row;
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | PROGRAMME REPORT
    |--------------------------------------------------------------------------
    */

    elseif ($reportType === 'programme') {

        $sql = "
            SELECT
                p.name,
                p.code,
                p.status,

                COUNT(DISTINCT CASE
                    WHEN c.status = 'active'
                    THEN c.id
                END) AS cohort_count,

                COUNT(DISTINCT CASE
                    WHEN s.status = 'active'
                    THEN s.id
                END) AS student_count,

                COUNT(DISTINCT CASE
                    WHEN a.status = 'active'
                    THEN a.id
                END) AS activity_count,

                COUNT(DISTINCT CASE
                    WHEN sub.status IN ('submitted', 'reviewed')
                    THEN sub.id
                END) AS submission_count

            FROM programmes p

            LEFT JOIN cohorts c
                ON c.programme_id = p.id

            LEFT JOIN students s
                ON s.cohort_id = c.id

            LEFT JOIN activities a
                ON a.cohort_id = c.id

            LEFT JOIN submissions sub
                ON sub.activity_id = a.id
                AND sub.student_id = s.id

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
                $reportData[] = $row;
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | STUDENT REPORT
    |--------------------------------------------------------------------------
    */

    elseif ($reportType === 'student') {

        $sql = "
            SELECT
                u.name AS student_name,
                u.email,
                s.student_code,
                s.course,
                s.academic_year,
                s.status,

                i.name AS institution_name,
                c.name AS cohort_name,

                COUNT(DISTINCT sub.id)
                    AS submission_count,

                COUNT(DISTINCT CASE
                    WHEN sub.status = 'reviewed'
                    THEN sub.id
                END) AS reviewed_count,

                ROUND(
                    AVG(
                        CASE
                            WHEN r.score IS NOT NULL
                            THEN r.score
                        END
                    ),
                    2
                ) AS average_score

            FROM students s

            INNER JOIN users u
                ON u.id = s.user_id

            INNER JOIN institutions i
                ON i.id = s.institution_id

            INNER JOIN cohorts c
                ON c.id = s.cohort_id

            LEFT JOIN submissions sub
                ON sub.student_id = s.id

            LEFT JOIN reviews r
                ON r.submission_id = sub.id

            GROUP BY
                s.id,
                u.name,
                u.email,
                s.student_code,
                s.course,
                s.academic_year,
                s.status,
                i.name,
                c.name

            ORDER BY
                u.name ASC
        ";

        $result = $conn->query($sql);

        if ($result) {

            while ($row = $result->fetch_assoc()) {
                $reportData[] = $row;
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function report_status_class(string $status): string
{
    if ($status === 'active') {
        return 'status-active';
    }

    if ($status === 'onboarding') {
        return 'status-pending';
    }

    return 'status-inactive';
}

function report_status_label(string $status): string
{
    return ucfirst($status);
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

    <title>Reports · Admin · AIraut</title>

    <link
        rel="stylesheet"
        href="style.css"
    >

    <style>

        .report-generator {
            margin-top: 42px;
            padding: 32px;
            border: 1px solid #d9dfe2;
            background: #fafaf8;
        }

        .report-generator-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 24px;
        }

        .report-generator-field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .report-generator-field label {
            font-size: 12px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: #486581;
            font-weight: 700;
        }

        .report-generator-field select {
            width: 100%;
            box-sizing: border-box;
            padding: 13px 14px;
            border: 1px solid #cfd8dc;
            background: #ffffff;
            color: #163654;
            font: inherit;
        }

        .report-generator-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 24px;
        }

        .report-output {
            margin-top: 42px;
        }

        .report-output-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 24px;
            margin-bottom: 24px;
        }

        .report-output-header h2 {
            margin-bottom: 8px;
        }

        .report-output-note {
            color: #66809a;
            font-size: 14px;
        }

        .report-table {
            min-width: 760px;
        }

        .report-table .admin-table-row {
            grid-template-columns: repeat(4, 1fr);
        }

        .report-table.student-report .admin-table-row {
            grid-template-columns:
                1.5fr
                1.2fr
                .8fr
                .8fr
                .8fr;
        }

        .report-table.cohort-report .admin-table-row {
            grid-template-columns:
                1.4fr
                1.3fr
                1.2fr
                .7fr
                .8fr;
        }

        .report-table.programme-report .admin-table-row {
            grid-template-columns:
                1.5fr
                .8fr
                .8fr
                .8fr
                .8fr;
        }

        .report-table.institution-report .admin-table-row {
            grid-template-columns:
                1.5fr
                .8fr
                .8fr
                .8fr;
        }

        .report-empty {
            padding: 50px 24px;
            text-align: center;
            color: #66809a;
        }

        @media (max-width: 900px) {

            .report-generator-grid {
                grid-template-columns: 1fr;
            }

            .report-output-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .admin-table-wrap {
                overflow-x: auto;
            }

        }

    </style>

</head>


<body>


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



<main class="page-shell">


    <!-- HERO -->

    <section class="admin-page-hero">


        <div class="admin-page-hero-copy">

            <div class="eyebrow">
                SYSTEM MANAGEMENT
            </div>

            <h1>
                Reports.
            </h1>

            <p>
                Generate and review reports across institutions,
                cohorts, programmes and student development.
            </p>

        </div>


        <a
            href="#reportGenerator"
            class="primary-button"
        >
            + Generate Report
        </a>


    </section>



    <!-- REPORT OVERVIEW -->

    <section class="admin-stat-strip">


        <div class="admin-stat">

            <span class="stat-label">
                ACTIVE INSTITUTIONS
            </span>

            <strong>
                <?= str_pad(
                    (string) $activeInstitutions,
                    2,
                    '0',
                    STR_PAD_LEFT
                ) ?>
            </strong>

        </div>


        <div class="admin-stat">

            <span class="stat-label">
                ACTIVE COHORTS
            </span>

            <strong>
                <?= str_pad(
                    (string) $activeCohorts,
                    2,
                    '0',
                    STR_PAD_LEFT
                ) ?>
            </strong>

        </div>


        <div class="admin-stat">

            <span class="stat-label">
                ACTIVE STUDENTS
            </span>

            <strong>
                <?= number_format($activeStudents) ?>
            </strong>

        </div>


    </section>



    <!-- REPORT DIRECTORY -->

    <section class="admin-directory">


        <div class="directory-heading">


            <div>

                <div class="eyebrow">
                    REPORT CENTRE
                </div>

                <h2>
                    Available reports.
                </h2>

            </div>


            <input
                type="search"
                id="reportSearch"
                class="directory-search"
                placeholder="Search reports..."
                aria-label="Search reports"
            >


        </div>



        <div
            class="admin-report-list"
            id="reportList"
        >


            <!-- REPORT 01 -->

            <div
                class="admin-report-row"
                data-search="institution progress report institution participation cohort progress programme activity"
            >

                <div class="report-number">
                    01
                </div>

                <div class="report-main">

                    <h3>
                        Institution Progress Report
                    </h3>

                    <p>
                        Participation, cohort progress and programme
                        activity across institutions.
                    </p>

                </div>

                <div class="report-meta">

                    <span class="stat-label">
                        TYPE
                    </span>

                    <strong>
                        INSTITUTION
                    </strong>

                </div>

                <a
                    href="#reportGenerator"
                    class="table-action report-select-link"
                    data-type="institution"
                >
                    Generate →
                </a>

            </div>



            <!-- REPORT 02 -->

            <div
                class="admin-report-row"
                data-search="cohort progress report student participation completion development cohort"
            >

                <div class="report-number">
                    02
                </div>

                <div class="report-main">

                    <h3>
                        Cohort Progress Report
                    </h3>

                    <p>
                        Student participation, completion and activity
                        progress for cohorts.
                    </p>

                </div>

                <div class="report-meta">

                    <span class="stat-label">
                        TYPE
                    </span>

                    <strong>
                        COHORT
                    </strong>

                </div>

                <a
                    href="#reportGenerator"
                    class="table-action report-select-link"
                    data-type="cohort"
                >
                    Generate →
                </a>

            </div>



            <!-- REPORT 03 -->

            <div
                class="admin-report-row"
                data-search="programme report participation activity completion progression programme"
            >

                <div class="report-number">
                    03
                </div>

                <div class="report-main">

                    <h3>
                        Programme Report
                    </h3>

                    <p>
                        Programme-level participation, activity
                        completion and progression.
                    </p>

                </div>

                <div class="report-meta">

                    <span class="stat-label">
                        TYPE
                    </span>

                    <strong>
                        PROGRAMME
                    </strong>

                </div>

                <a
                    href="#reportGenerator"
                    class="table-action report-select-link"
                    data-type="programme"
                >
                    Generate →
                </a>

            </div>



            <!-- REPORT 04 -->

            <div
                class="admin-report-row"
                data-search="student development report individual student participation submissions feedback progress"
            >

                <div class="report-number">
                    04
                </div>

                <div class="report-main">

                    <h3>
                        Student Development Report
                    </h3>

                    <p>
                        Individual student participation, submissions,
                        feedback and progress.
                    </p>

                </div>

                <div class="report-meta">

                    <span class="stat-label">
                        TYPE
                    </span>

                    <strong>
                        STUDENT
                    </strong>

                </div>

                <a
                    href="#reportGenerator"
                    class="table-action report-select-link"
                    data-type="student"
                >
                    Generate →
                </a>

            </div>


        </div>


        <div
            id="noReportResults"
            class="report-empty"
            style="display:none;"
        >
            No reports match your search.
        </div>


    </section>



    <!-- REPORT GENERATOR -->

    <section
        class="report-generator"
        id="reportGenerator"
    >


        <div>

            <div class="eyebrow">
                REPORTING
            </div>

            <h2>
                Generate a live report.
            </h2>

            <p>
                Select a report type to generate a view from the
                current AIraut database.
            </p>

        </div>



        <form
            method="POST"
            action="admin-reports.php"
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


            <div class="report-generator-grid">


                <div class="report-generator-field">

                    <label for="report_type">
                        Report Type
                    </label>

                    <select
                        id="report_type"
                        name="report_type"
                        required
                    >

                        <option value="">
                            Select report type
                        </option>

                        <option
                            value="institution"
                            <?= $reportType === 'institution'
                                ? 'selected'
                                : '' ?>
                        >
                            Institution Progress Report
                        </option>

                        <option
                            value="cohort"
                            <?= $reportType === 'cohort'
                                ? 'selected'
                                : '' ?>
                        >
                            Cohort Progress Report
                        </option>

                        <option
                            value="programme"
                            <?= $reportType === 'programme'
                                ? 'selected'
                                : '' ?>
                        >
                            Programme Report
                        </option>

                        <option
                            value="student"
                            <?= $reportType === 'student'
                                ? 'selected'
                                : '' ?>
                        >
                            Student Development Report
                        </option>

                    </select>

                </div>


            </div>


            <div class="report-generator-actions">

                <button
                    type="submit"
                    class="primary-button"
                >
                    Generate Report →
                </button>

            </div>


        </form>


    </section>



    <!-- GENERATED REPORT -->

    <?php if ($reportGenerated): ?>


        <section class="report-output">


            <div class="report-output-header">


                <div>

                    <div class="eyebrow">
                        GENERATED REPORT
                    </div>

                    <h2>

                        <?php

                        if ($reportType === 'institution') {
                            echo 'Institution Progress Report';
                        } elseif ($reportType === 'cohort') {
                            echo 'Cohort Progress Report';
                        } elseif ($reportType === 'programme') {
                            echo 'Programme Report';
                        } else {
                            echo 'Student Development Report';
                        }

                        ?>

                    </h2>

                    <span class="report-output-note">
                        Live data from the current AIraut system
                    </span>

                </div>


                <span class="status-badge status-active">
                    GENERATED
                </span>


            </div>



            <div class="admin-table-wrap">


                <?php if ($reportType === 'institution'): ?>


                    <div class="admin-table report-table institution-report">


                        <div class="admin-table-row admin-table-header">

                            <div>
                                INSTITUTION
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

                        </div>


                        <?php foreach ($reportData as $row): ?>

                            <div class="admin-table-row">

                                <div class="table-primary">

                                    <strong>
                                        <?= htmlspecialchars(
                                            $row['name']
                                        ) ?>
                                    </strong>

                                    <span>
                                        <?= htmlspecialchars(
                                            $row['code']
                                        ) ?>
                                    </span>

                                </div>

                                <div>
                                    <?= (int) $row['cohort_count'] ?>
                                </div>

                                <div>
                                    <?= number_format(
                                        (int) $row['student_count']
                                    ) ?>
                                </div>

                                <div>

                                    <span
                                        class="status-badge <?= report_status_class(
                                            $row['status']
                                        ) ?>"
                                    >
                                        <?= htmlspecialchars(
                                            report_status_label(
                                                $row['status']
                                            )
                                        ) ?>
                                    </span>

                                </div>

                            </div>

                        <?php endforeach; ?>


                    </div>



                <?php elseif ($reportType === 'cohort'): ?>


                    <div class="admin-table report-table cohort-report">


                        <div class="admin-table-row admin-table-header">

                            <div>
                                COHORT
                            </div>

                            <div>
                                INSTITUTION
                            </div>

                            <div>
                                PROGRAMME
                            </div>

                            <div>
                                STUDENTS
                            </div>

                            <div>
                                SUBMISSIONS
                            </div>

                        </div>


                        <?php foreach ($reportData as $row): ?>

                            <div class="admin-table-row">

                                <div class="table-primary">

                                    <strong>
                                        <?= htmlspecialchars(
                                            $row['cohort_name']
                                        ) ?>
                                    </strong>

                                    <span>
                                        <?= htmlspecialchars(
                                            $row['status']
                                        ) ?>
                                    </span>

                                </div>

                                <div>
                                    <?= htmlspecialchars(
                                        $row['institution_name']
                                    ) ?>
                                </div>

                                <div>
                                    <?= htmlspecialchars(
                                        $row['programme_name']
                                            ?? '—'
                                    ) ?>
                                </div>

                                <div>
                                    <?= (int) $row['student_count'] ?>
                                </div>

                                <div>
                                    <?= (int) $row['submission_count'] ?>
                                </div>

                            </div>

                        <?php endforeach; ?>


                    </div>



                <?php elseif ($reportType === 'programme'): ?>


                    <div class="admin-table report-table programme-report">


                        <div class="admin-table-row admin-table-header">

                            <div>
                                PROGRAMME
                            </div>

                            <div>
                                COHORTS
                            </div>

                            <div>
                                STUDENTS
                            </div>

                            <div>
                                ACTIVITIES
                            </div>

                            <div>
                                SUBMISSIONS
                            </div>

                        </div>


                        <?php foreach ($reportData as $row): ?>

                            <div class="admin-table-row">

                                <div class="table-primary">

                                    <strong>
                                        <?= htmlspecialchars(
                                            $row['name']
                                        ) ?>
                                    </strong>

                                    <span>
                                        <?= htmlspecialchars(
                                            $row['code']
                                        ) ?>
                                    </span>

                                </div>

                                <div>
                                    <?= (int) $row['cohort_count'] ?>
                                </div>

                                <div>
                                    <?= number_format(
                                        (int) $row['student_count']
                                    ) ?>
                                </div>

                                <div>
                                    <?= (int) $row['activity_count'] ?>
                                </div>

                                <div>
                                    <?= (int) $row['submission_count'] ?>
                                </div>

                            </div>

                        <?php endforeach; ?>


                    </div>



                <?php elseif ($reportType === 'student'): ?>


                    <div class="admin-table report-table student-report">


                        <div class="admin-table-row admin-table-header">

                            <div>
                                STUDENT
                            </div>

                            <div>
                                COHORT
                            </div>

                            <div>
                                SUBMISSIONS
                            </div>

                            <div>
                                REVIEWED
                            </div>

                            <div>
                                AVG. SCORE
                            </div>

                        </div>


                        <?php foreach ($reportData as $row): ?>

                            <div class="admin-table-row">

                                <div class="table-primary">

                                    <strong>
                                        <?= htmlspecialchars(
                                            $row['student_name']
                                        ) ?>
                                    </strong>

                                    <span>
                                        <?= htmlspecialchars(
                                            $row['student_code']
                                        ) ?>
                                    </span>

                                </div>

                                <div>
                                    <?= htmlspecialchars(
                                        $row['cohort_name']
                                    ) ?>
                                </div>

                                <div>
                                    <?= (int) $row['submission_count'] ?>
                                </div>

                                <div>
                                    <?= (int) $row['reviewed_count'] ?>
                                </div>

                                <div>

                                    <?= $row['average_score'] !== null
                                        ? number_format(
                                            (float) $row['average_score'],
                                            2
                                        ) . '/10'
                                        : '—' ?>

                                </div>

                            </div>

                        <?php endforeach; ?>


                    </div>


                <?php endif; ?>


                <?php if (count($reportData) === 0): ?>

                    <div class="report-empty">
                        No data is currently available for this report.
                    </div>

                <?php endif; ?>


            </div>


        </section>


    <?php endif; ?>



    <!-- NEXT STEP -->

    <section class="admin-next-step">


        <div>

            <div class="eyebrow">
                REPORTING
            </div>

            <h2>
                Turn system data into insight.
            </h2>

            <p>
                Review live progress across the AIraut system
                and prepare structured reports for institutional
                conversations.
            </p>

        </div>


        <a
            href="admin-dashboard.php"
            class="secondary-button"
        >
            Back to Control Centre →
        </a>


    </section>


</main>



<footer class="site-footer">


    <div class="footer-inner">


        <div>

            <strong>
                AIraut
            </strong>

            <span>
                Future Readiness Development System
            </span>

        </div>


        <div>
            ADMIN · REPORTS
        </div>


    </div>

</footer>



<script>

/*
|--------------------------------------------------------------------------
| REPORT SEARCH
|--------------------------------------------------------------------------
*/

const reportSearch =
    document.getElementById('reportSearch');

const reportRows =
    document.querySelectorAll('.admin-report-row');

const noReportResults =
    document.getElementById('noReportResults');


if (reportSearch) {

    reportSearch.addEventListener(
        'input',
        function () {

            const query =
                this.value
                    .toLowerCase()
                    .trim();

            let visible = 0;


            reportRows.forEach(
                function (row) {

                    const text =
                        row.getAttribute(
                            'data-search'
                        ) || '';

                    const matches =
                        text.includes(query);

                    row.style.display =
                        matches
                            ? ''
                            : 'grid';

                    if (!matches) {
                        row.style.display = 'none';
                    }

                    if (matches) {
                        visible++;
                    }

                }
            );


            if (noReportResults) {

                noReportResults.style.display =
                    visible === 0 && query !== ''
                        ? 'block'
                        : 'none';

            }

        }
    );

}


/*
|--------------------------------------------------------------------------
| REPORT TYPE QUICK SELECT
|--------------------------------------------------------------------------
*/

const reportSelect =
    document.getElementById('report_type');

const reportLinks =
    document.querySelectorAll(
        '.report-select-link'
    );


reportLinks.forEach(
    function (link) {

        link.addEventListener(
            'click',
            function () {

                const type =
                    this.getAttribute(
                        'data-type'
                    );

                if (reportSelect && type) {
                    reportSelect.value = type;
                }

            }
        );

    }
);

</script>


</body>

</html>
<?php

require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/config/database.php';

require_role('admin');

/*
|--------------------------------------------------------------------------
| ADMIN DASHBOARD — LIVE DATABASE COUNTS
|--------------------------------------------------------------------------
*/

$activeInstitutions = 0;
$activeCohorts = 0;
$activeStudents = 0;
$activeFaculty = 0;


/* Active institutions */
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM institutions
    WHERE status = 'active'
");

$stmt->execute();

$result = $stmt->get_result();
$activeInstitutions = (int) ($result->fetch_assoc()['total'] ?? 0);

$stmt->close();


/* Active cohorts */
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM cohorts
    WHERE status = 'active'
");

$stmt->execute();

$result = $stmt->get_result();
$activeCohorts = (int) ($result->fetch_assoc()['total'] ?? 0);

$stmt->close();


/* Active students */
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM users
    WHERE role = 'student'
      AND status = 'active'
");

$stmt->execute();

$result = $stmt->get_result();
$activeStudents = (int) ($result->fetch_assoc()['total'] ?? 0);

$stmt->close();


/* Active faculty */
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM users
    WHERE role = 'faculty'
      AND status = 'active'
");

$stmt->execute();

$result = $stmt->get_result();
$activeFaculty = (int) ($result->fetch_assoc()['total'] ?? 0);

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

  <title>Admin Dashboard | AIraut</title>

  <link
    rel="stylesheet"
    href="style.css"
  >

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
        href="logout.php"
        class="platform-back"
      >
        Exit
      </a>

    </div>

  </header>



  <!-- MAIN -->

  <main class="platform-page admin-dashboard-page">


    <!-- INTRO -->

    <section class="platform-intro">

      <div>

        <p class="platform-eyebrow">
          AIraut Control Centre
        </p>

        <h1>
          Good to see you.
        </h1>

        <p class="platform-intro-text">
          Manage institutions, programmes, people and progress
          across the AIraut system.
        </p>

      </div>


      <div class="admin-status">

        <span class="status-dot"></span>

        System active

      </div>

    </section>



    <!-- OVERVIEW -->

    <section class="admin-overview">


      <!-- INSTITUTIONS -->

      <div class="admin-overview-item">

        <span class="admin-overview-label">
          Institutions
        </span>

        <strong>
          <?= str_pad(
              (string) $activeInstitutions,
              2,
              '0',
              STR_PAD_LEFT
          ) ?>
        </strong>

        <small>
          Active institutions
        </small>

      </div>



      <!-- COHORTS -->

      <div class="admin-overview-item">

        <span class="admin-overview-label">
          Cohorts
        </span>

        <strong>
          <?= str_pad(
              (string) $activeCohorts,
              2,
              '0',
              STR_PAD_LEFT
          ) ?>
        </strong>

        <small>
          Active cohorts
        </small>

      </div>



      <!-- STUDENTS -->

      <div class="admin-overview-item">

        <span class="admin-overview-label">
          Students
        </span>

        <strong>
          <?= number_format($activeStudents) ?>
        </strong>

        <small>
          Students enrolled
        </small>

      </div>



      <!-- FACULTY -->

      <div class="admin-overview-item">

        <span class="admin-overview-label">
          Faculty
        </span>

        <strong>
          <?= str_pad(
              (string) $activeFaculty,
              2,
              '0',
              STR_PAD_LEFT
          ) ?>
        </strong>

        <small>
          Faculty members
        </small>

      </div>


    </section>



    <!-- MANAGEMENT -->

    <section class="admin-section">


      <div class="admin-section-heading">

        <p class="platform-eyebrow">
          System Management
        </p>

        <h2>
          Manage AIraut.
        </h2>

      </div>



      <div class="admin-management-grid">


        <!-- INSTITUTIONS -->

        <a
          href="admin-institutions.php"
          class="admin-management-card"
        >

          <div class="admin-card-number">
            01
          </div>

          <div class="admin-card-content">

            <h3>
              Institutions
            </h3>

            <p>
              Add and manage institutions using AIraut.
            </p>

            <span class="admin-card-link">
              Manage institutions →
            </span>

          </div>

        </a>



        <!-- COHORTS -->

        <a
          href="admin-cohorts.php"
          class="admin-management-card"
        >

          <div class="admin-card-number">
            02
          </div>

          <div class="admin-card-content">

            <h3>
              Cohorts
            </h3>

            <p>
              Create, organise and manage student cohorts.
            </p>

            <span class="admin-card-link">
              Manage cohorts →
            </span>

          </div>

        </a>



        <!-- FACULTY -->

        <a
          href="admin-faculty.php"
          class="admin-management-card"
        >

          <div class="admin-card-number">
            03
          </div>

          <div class="admin-card-content">

            <h3>
              Faculty
            </h3>

            <p>
              Manage faculty access, assignments and participation.
            </p>

            <span class="admin-card-link">
              Manage faculty →
            </span>

          </div>

        </a>



        <!-- STUDENTS -->

        <a
          href="admin-students.php"
          class="admin-management-card"
        >

          <div class="admin-card-number">
            04
          </div>

          <div class="admin-card-content">

            <h3>
              Students
            </h3>

            <p>
              View and manage students across cohorts.
            </p>

            <span class="admin-card-link">
              Manage students →
            </span>

          </div>

        </a>



        <!-- PROGRAMMES -->

        <a
          href="admin-programmes.php"
          class="admin-management-card"
        >

          <div class="admin-card-number">
            05
          </div>

          <div class="admin-card-content">

            <h3>
              Programmes
            </h3>

            <p>
              Configure AIraut programmes and delivery structures.
            </p>

            <span class="admin-card-link">
              Manage programmes →
            </span>

          </div>

        </a>



        <!-- MONITORING -->

        <a
          href="admin-monitoring.php"
          class="admin-management-card"
        >

          <div class="admin-card-number">
            06
          </div>

          <div class="admin-card-content">

            <h3>
              Monitoring
            </h3>

            <p>
              Monitor participation, activity and system progress.
            </p>

            <span class="admin-card-link">
              Open monitoring →
            </span>

          </div>

        </a>



        <!-- REPORTS -->

        <a
          href="admin-reports.php"
          class="admin-management-card admin-card-wide"
        >

          <div class="admin-card-number">
            07
          </div>

          <div class="admin-card-content">

            <h3>
              Reports
            </h3>

            <p>
              Access system-level information and generate reports
              for AIraut operations.
            </p>

            <span class="admin-card-link">
              View reports →
            </span>

          </div>

        </a>


      </div>

    </section>



    <!-- RECENT ACTIVITY -->

    <section class="admin-section admin-activity-section">


      <div class="admin-section-heading">

        <p class="platform-eyebrow">
          System Activity
        </p>

        <h2>
          Recently updated.
        </h2>

      </div>



      <div class="admin-activity-list">


        <div class="admin-activity-item">

          <span class="admin-activity-marker"></span>

          <div>

            <strong>
              New institution added
            </strong>

            <p>
              Institution onboarding activity recorded.
            </p>

          </div>

          <time>
            Today
          </time>

        </div>



        <div class="admin-activity-item">

          <span class="admin-activity-marker"></span>

          <div>

            <strong>
              Cohort updated
            </strong>

            <p>
              Student cohort information was updated.
            </p>

          </div>

          <time>
            Yesterday
          </time>

        </div>



        <div class="admin-activity-item">

          <span class="admin-activity-marker"></span>

          <div>

            <strong>
              Programme activity
            </strong>

            <p>
              Programme configuration was recently reviewed.
            </p>

          </div>

          <time>
            2 days ago
          </time>

        </div>


      </div>

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
      ADMIN CONTROL CENTRE
    </span>

  </footer>


</body>

</html>
<?php

require_once __DIR__ . '/auth/session.php';

require_role('student');

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard — AIraut</title>

    <!--
    ============================================================
    AIraut — STUDENT DASHBOARD
    ============================================================
    Purpose:
    The student's main platform home after login.

    Next screens:
    01 My Modules
    02 My Activities
    03 Submit Work
    04 Feedback
    05 My Progress
    ============================================================
    -->

    <link rel="stylesheet" href="style.css">
</head>

<body class="platform-page">

    <!-- ========================================================
         PLATFORM HEADER
    ========================================================= -->

    <header class="platform-header">

        <a href="index.html" class="platform-logo">
            <img src="assets/airaut-logo.png" alt="AIraut">
        </a>

        <div class="platform-header-right">
            <span class="student-label">STUDENT</span>

            <a href="logout.php" class="back-link">
                Logout
            </a>

            <a href="index.html" class="back-link">
                ← Back to AIraut
            </a>
        </div>

    </header>


    <!-- ========================================================
         STUDENT DASHBOARD — INTRO
    ========================================================= -->

    <main>

        <section class="student-dashboard">

            <div class="dashboard-intro">

                <p class="eyebrow">STUDENT PATHWAY</p>

                <h1>
                    Welcome to your
                    <span>readiness journey.</span>
                </h1>

                <p class="dashboard-lead">
                    Learn. Explore. Build.
                </p>

            </div>


            <!-- =================================================
                 READINESS JOURNEY
            ================================================== -->

            <div class="journey-panel">

                <div class="journey-heading">
                    <p class="eyebrow">YOUR JOURNEY</p>

                    <h2>
                        Three steps.
                        <span>One direction.</span>
                    </h2>
                </div>


                <div class="journey-steps">

                    <div class="journey-step active">

                        <div class="journey-number">01</div>

                        <div>
                            <h3>Learn</h3>
                            <p>
                                Build knowledge, awareness and
                                understanding.
                            </p>
                        </div>

                        <div class="journey-status">
                            CURRENT
                        </div>

                    </div>


                    <div class="journey-connector"></div>


                    <div class="journey-step">

                        <div class="journey-number">02</div>

                        <div>
                            <h3>Explore</h3>
                            <p>
                                Question, experiment and
                                experience.
                            </p>
                        </div>

                    </div>


                    <div class="journey-connector"></div>


                    <div class="journey-step">

                        <div class="journey-number">03</div>

                        <div>
                            <h3>Build</h3>
                            <p>
                                Apply what you learn and
                                demonstrate your readiness.
                            </p>
                        </div>

                    </div>

                </div>

            </div>


            <!-- =================================================
                 DASHBOARD ACTIONS
            ================================================== -->

            <section class="dashboard-actions">

                <div class="dashboard-section-heading">

                    <p class="eyebrow">YOUR SPACE</p>

                    <h2>
                        What do you want
                        <span>to do?</span>
                    </h2>

                </div>


                <div class="action-list">

                    <!-- MY MODULES -->

                    <a href="#" class="dashboard-action">

                        <span class="action-number">01</span>

                        <div class="action-content">
                            <h3>My Modules</h3>
                            <p>
                                Explore the learning modules
                                available to you.
                            </p>
                        </div>

                        <span class="action-arrow">→</span>

                    </a>


                    <!-- MY ACTIVITIES -->

                    <a href="#" class="dashboard-action">

                        <span class="action-number">02</span>

                        <div class="action-content">
                            <h3>My Activities</h3>
                            <p>
                                Discover experiences designed
                                for your journey.
                            </p>
                        </div>

                        <span class="action-arrow">→</span>

                    </a>


                    <!-- SUBMIT WORK -->

                    <a href="#" class="dashboard-action">

                        <span class="action-number">03</span>

                        <div class="action-content">
                            <h3>Submit Work</h3>
                            <p>
                                Share your work and demonstrate
                                what you have developed.
                            </p>
                        </div>

                        <span class="action-arrow">→</span>

                    </a>


                    <!-- FEEDBACK -->

                    <a href="#" class="dashboard-action">

                        <span class="action-number">04</span>

                        <div class="action-content">
                            <h3>Feedback</h3>
                            <p>
                                See feedback that helps you
                                move forward.
                            </p>
                        </div>

                        <span class="action-arrow">→</span>

                    </a>


                    <!-- MY PROGRESS -->

                    <a href="#" class="dashboard-action">

                        <span class="action-number">05</span>

                        <div class="action-content">
                            <h3>My Progress</h3>
                            <p>
                                See how your readiness journey
                                is progressing.
                            </p>
                        </div>

                        <span class="action-arrow">→</span>

                    </a>

                </div>

            </section>


            <!-- =================================================
                 PROGRESS SNAPSHOT
            ================================================== -->

            <section class="progress-snapshot">

                <div class="progress-copy">

                    <p class="eyebrow">YOUR PROGRESS</p>

                    <h2>
                        Keep moving
                        <span>forward.</span>
                    </h2>

                    <p>
                        Your journey is built through
                        participation, reflection and application.
                    </p>

                    <a href="#" class="outline-button">
                        View My Progress →
                    </a>

                </div>


                <div class="progress-visual">

                    <div class="progress-ring">

                        <div class="progress-ring-inner">

                            <strong>00%</strong>

                            <span>STARTED</span>

                        </div>

                    </div>

                    <div class="progress-caption">
                        Your readiness journey begins here.
                    </div>

                </div>

            </section>


        </section>

    </main>


    <!-- ========================================================
         PLATFORM FOOTER
    ========================================================= -->

    <footer class="platform-footer">

        <div>
            <strong>AIraut</strong>
            <span> — Future Readiness Development System</span>
        </div>

        <a href="index.html">
            Exit Platform →
        </a>

    </footer>


    <!-- ========================================================
         JAVASCRIPT
    ======================================================== -->
</body>

</html>
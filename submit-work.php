<?php

ob_start();

require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/config/database.php';

require_role('student');


/*
|--------------------------------------------------------------------------
| ONLY ACCEPT POST SUBMISSIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    header('Allow: POST');

    exit(
        'Method not allowed.'
    );
}


/*
|--------------------------------------------------------------------------
| CSRF PROTECTION
|--------------------------------------------------------------------------
*/

require_csrf_token();


$userId = current_user_id();


/*
|--------------------------------------------------------------------------
| READ FORM DATA
|--------------------------------------------------------------------------
*/

$activityId =
    isset($_POST['activity_id'])
        ? (int) $_POST['activity_id']
        : 0;

$response =
    trim(
        $_POST['response'] ?? ''
    );


/*
|--------------------------------------------------------------------------
| BASIC VALIDATION
|--------------------------------------------------------------------------
*/

if (
    $activityId <= 0
    || $response === ''
) {

    http_response_code(400);

    exit(
        'Activity and response are required.'
    );
}


/*
|--------------------------------------------------------------------------
| FILE UPLOAD SETTINGS
|--------------------------------------------------------------------------
|
| Maximum file size: 10 MB
|
*/

$maxFileSize =
    10 * 1024 * 1024;


$allowedMimeTypes = [

    'application/pdf'
        => 'pdf',

    'application/msword'
        => 'doc',

    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        => 'docx',

    'application/vnd.ms-powerpoint'
        => 'ppt',

    'application/vnd.openxmlformats-officedocument.presentationml.presentation'
        => 'pptx',

    'application/vnd.ms-excel'
        => 'xls',

    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        => 'xlsx',

    'image/jpeg'
        => 'jpg',

    'image/png'
        => 'png',

    'application/zip'
        => 'zip',
];


/*
|--------------------------------------------------------------------------
| FIND LOGGED-IN STUDENT
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "SELECT
        id,
        cohort_id
     FROM students
     WHERE user_id = ?
     LIMIT 1"
);

if (!$stmt) {

    ob_end_clean();

    http_response_code(500);

    exit(
        'Unable to load student profile.'
    );
}


$stmt->bind_param(
    "i",
    $userId
);


$stmt->execute();


$student =
    $stmt->get_result()->fetch_assoc();


$stmt->close();


if (!$student) {

    http_response_code(404);

    exit(
        'Student profile not found.'
    );
}


$studentId =
    (int) $student['id'];

$cohortId =
    (int) $student['cohort_id'];


/*
|--------------------------------------------------------------------------
| VERIFY ACTIVITY
|--------------------------------------------------------------------------
|
| Activity must belong to the student's cohort
| and must currently be active.
|
*/

$stmt = $conn->prepare(
    "SELECT
        id
     FROM activities
     WHERE id = ?
       AND cohort_id = ?
       AND status = 'active'
     LIMIT 1"
);

if (!$stmt) {

    http_response_code(500);

    exit(
        'Unable to verify activity.'
    );
}


$stmt->bind_param(
    "ii",
    $activityId,
    $cohortId
);


$stmt->execute();


$activity =
    $stmt->get_result()->fetch_assoc();


$stmt->close();


if (!$activity) {

    http_response_code(403);

    exit(
        'This activity is not available to you.'
    );
}


/*
|--------------------------------------------------------------------------
| PREVENT DUPLICATE SUBMISSIONS
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "SELECT
        id
     FROM submissions
     WHERE activity_id = ?
       AND student_id = ?
     LIMIT 1"
);

if (!$stmt) {

    http_response_code(500);

    exit(
        'Unable to verify existing submission.'
    );
}


$stmt->bind_param(
    "ii",
    $activityId,
    $studentId
);


$stmt->execute();


$existingSubmission =
    $stmt->get_result()->fetch_assoc();


$stmt->close();


if ($existingSubmission) {

    header(
        'Location: student-submit.php?activity_id='
        . $activityId
        . '&error=already_submitted'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| FILE STATE
|--------------------------------------------------------------------------
*/

$hasFile =
    isset($_FILES['work_file'])
    && is_array($_FILES['work_file'])
    && (
        $_FILES['work_file']['error']
        !== UPLOAD_ERR_NO_FILE
    );


$uploadedFilePath =
    null;

$storedFileName =
    null;

$originalFileName =
    null;

$fileMimeType =
    null;

$fileSize =
    null;


/*
|--------------------------------------------------------------------------
| PROCESS FILE
|--------------------------------------------------------------------------
*/

if ($hasFile) {

    $file =
        $_FILES['work_file'];


    /*
    |--------------------------------------------------------------------------
    | VERIFY PHP UPLOAD
    |--------------------------------------------------------------------------
    */

    if (
        !isset($file['tmp_name'])
        || !is_uploaded_file(
            $file['tmp_name']
        )
    ) {

        http_response_code(400);

        exit(
            'Invalid file upload.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CHECK UPLOAD ERROR
    |--------------------------------------------------------------------------
    */

    if (
        $file['error']
        !== UPLOAD_ERR_OK
    ) {

        http_response_code(400);


        switch (
            $file['error']
        ) {

            case UPLOAD_ERR_INI_SIZE:

            case UPLOAD_ERR_FORM_SIZE:

                exit(
                    'The selected file is too large.'
                );


            case UPLOAD_ERR_PARTIAL:

                exit(
                    'The file upload was incomplete.'
                );


            case UPLOAD_ERR_NO_TMP_DIR:

                exit(
                    'The server could not process the uploaded file.'
                );


            case UPLOAD_ERR_CANT_WRITE:

                exit(
                    'The server could not save the uploaded file.'
                );


            default:

                exit(
                    'Unable to upload the selected file.'
                );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | CHECK FILE SIZE
    |--------------------------------------------------------------------------
    */

    $fileSize =
        (int) $file['size'];


    if (
        $fileSize <= 0
    ) {

        http_response_code(400);

        exit(
            'The selected file is empty.'
        );
    }


    if (
        $fileSize > $maxFileSize
    ) {

        http_response_code(400);

        exit(
            'File size must be 10 MB or less.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | DETECT ACTUAL MIME TYPE
    |--------------------------------------------------------------------------
    */

    $finfo =
        new finfo(
            FILEINFO_MIME_TYPE
        );


    $detectedMimeType =
        $finfo->file(
            $file['tmp_name']
        );


    if (
        $detectedMimeType === false
        || !isset(
            $allowedMimeTypes[
                $detectedMimeType
            ]
        )
    ) {

        http_response_code(400);

        exit(
            'This file type is not supported. '
            . 'Please upload PDF, Word, PowerPoint, Excel, '
            . 'JPG, PNG or ZIP files.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | VERIFY ORIGINAL EXTENSION
    |--------------------------------------------------------------------------
    */

    $originalFileName =
        basename(
            (string) $file['name']
        );


    /*
     * Keep the original name within the database
     * column limit.
     */

    if (
        strlen($originalFileName) > 255
    ) {

        $originalFileName =
            substr(
                $originalFileName,
                -255
            );
    }


    $originalExtension =
        strtolower(
            pathinfo(
                $originalFileName,
                PATHINFO_EXTENSION
            )
        );


    $expectedExtension =
        $allowedMimeTypes[
            $detectedMimeType
        ];


    if (
        $originalExtension
        !== $expectedExtension
    ) {

        http_response_code(400);

        exit(
            'The file extension does not match '
            . 'the detected file type.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CREATE UPLOAD DIRECTORY
    |--------------------------------------------------------------------------
    */

    $uploadDirectory =
        __DIR__
        . DIRECTORY_SEPARATOR
        . 'uploads'
        . DIRECTORY_SEPARATOR
        . 'submissions';


    if (
        !is_dir(
            $uploadDirectory
        )
    ) {

        if (
            !mkdir(
                $uploadDirectory,
                0755,
                true
            )
        ) {

            http_response_code(500);

            exit(
                'Unable to create the upload directory.'
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | GENERATE RANDOM STORED FILENAME
    |--------------------------------------------------------------------------
    */

    try {

        $randomName =
            bin2hex(
                random_bytes(16)
            );

    } catch (
        Throwable $e
    ) {

        http_response_code(500);

        exit(
            'Unable to generate a secure file name.'
        );
    }


    $storedFileName =
        $randomName
        . '.'
        . $expectedExtension;


    $uploadedFilePath =
        $uploadDirectory
        . DIRECTORY_SEPARATOR
        . $storedFileName;


    /*
    |--------------------------------------------------------------------------
    | MOVE UPLOAD
    |--------------------------------------------------------------------------
    */

    if (
        !move_uploaded_file(
            $file['tmp_name'],
            $uploadedFilePath
        )
    ) {

        http_response_code(500);

        exit(
            'Unable to store the uploaded file.'
        );
    }


    $fileMimeType =
        $detectedMimeType;
}


/*
|--------------------------------------------------------------------------
| DATABASE TRANSACTION
|--------------------------------------------------------------------------
*/

$conn->begin_transaction();


try {

    /*
    |--------------------------------------------------------------------------
    | SAVE SUBMISSION
    |--------------------------------------------------------------------------
    */

    $status =
        'submitted';


    $stmt = $conn->prepare(
        "INSERT INTO submissions
        (
            activity_id,
            student_id,
            response,
            status,
            submitted_at
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            NOW()
        )"
    );


    if (!$stmt) {

        throw new Exception(
            'Unable to prepare submission.'
        );
    }


    $stmt->bind_param(
        "iiss",
        $activityId,
        $studentId,
        $response,
        $status
    );


    if (
        !$stmt->execute()
    ) {

        $stmt->close();

        throw new Exception(
            'Unable to save your submission.'
        );
    }


    $submissionId =
        (int) $stmt->insert_id;


    $stmt->close();


    /*
    |--------------------------------------------------------------------------
    | SAVE FILE RECORD
    |--------------------------------------------------------------------------
    */

    if ($hasFile) {

        /*
         * Store only a relative path in the database.
         */

        $databaseFilePath =
            'uploads/submissions/'
            . $storedFileName;


        $stmt = $conn->prepare(
            "INSERT INTO submission_files
            (
                submission_id,
                original_name,
                stored_name,
                file_path,
                mime_type,
                file_size
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?
            )"
        );


        if (!$stmt) {

            throw new Exception(
                'Unable to prepare file record.'
            );
        }


        $stmt->bind_param(
            "issssi",
            $submissionId,
            $originalFileName,
            $storedFileName,
            $databaseFilePath,
            $fileMimeType,
            $fileSize
        );


        if (
            !$stmt->execute()
        ) {

            $stmt->close();

            throw new Exception(
                'Unable to save the uploaded file record.'
            );
        }


        $stmt->close();
    }


    /*
    |--------------------------------------------------------------------------
    | COMMIT DATABASE CHANGES
    |--------------------------------------------------------------------------
    */

    $conn->commit();


} catch (
    Throwable $e
) {

    /*
    |--------------------------------------------------------------------------
    | ROLLBACK DATABASE
    |--------------------------------------------------------------------------
    */

    $conn->rollback();


    /*
    |--------------------------------------------------------------------------
    | REMOVE PHYSICAL FILE
    |--------------------------------------------------------------------------
    |
    | This prevents orphaned files when the database
    | operation fails after the file was uploaded.
    |
    */

    if (
        $hasFile
        && $uploadedFilePath !== null
        && is_file(
            $uploadedFilePath
        )
    ) {

        @unlink(
            $uploadedFilePath
        );
    }


    http_response_code(500);

    exit(
        'Unable to save your submission. Please try again.'
    );
}


/*
|--------------------------------------------------------------------------
| SUCCESS
|--------------------------------------------------------------------------
*/

header(
    'Location: student-submit.php?activity_id='
    . $activityId
    . '&submitted=1'
);

exit;
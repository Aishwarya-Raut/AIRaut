AIraut — HOSTINGER DEPLOYMENT CHECKLIST
========================================

PACKAGE LAYOUT
- This ZIP contains the website files at its root. Extract into public_html so index.html, platform.html, auth/, config/, assets/ and PHP files sit directly inside public_html.
- Back up the current public_html before replacing anything. Do not delete your existing backup until the new deployment has been tested.

DATABASE CONFIGURATION (REQUIRED BEFORE LOGIN WILL WORK)
1. Open config/database.php in Hostinger File Manager.
2. Replace all four REPLACE_WITH_* placeholders with the exact Hostinger database host, database name, database username, and the NEW database password.
3. Never share the database password in chat or commit it to a public repository. If a real password has been shared or included in any ZIP, rotate it in hPanel and update this file.
4. The database schema must be imported and usable. A schema-only import with zero user records means nobody can log in until legitimate accounts are provisioned.

POST-DEPLOYMENT TESTS
- Open https://YOUR-DOMAIN/ and click Why AIraut, What is AIraut?, Contact, and Enter Platform.
- On platform.html, test each role card and confirm the selected role appears on the login screen.
- Confirm /auth/csrf-token.php returns JSON and does not show PHP warnings.
- Test login/logout for one provisioned test account per role. Confirm cross-role dashboard access is denied.
- Test the student submission flow and verify uploaded files cannot be executed as PHP.
- Confirm HTTPS is active before using real accounts.

IMPORTANT LIMITATION
This package has been statically reviewed and PHP syntax checked. It has not been connected to your Hostinger database or tested against live user accounts, so it cannot honestly be described as fully runtime-verified or “perfect” until the tests above pass.

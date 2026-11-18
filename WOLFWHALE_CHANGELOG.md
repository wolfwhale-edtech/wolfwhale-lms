# WolfWhale LMS changelog

WolfWhale LMS starts from an unmodified Moodle release. Every change after that baseline is logged here.

## Upstream base

- Tag: `v5.3.1`
- Commit: `cfe123f26fc7071981fe949cb16cd5a08a8234f1`
- Commit date: 2026-11-12 (2026-11-12T09:48:42+07:00)
- Branch at import: `MOODLE_503_STABLE` (that branch pointed at this same commit)
- URL: https://github.com/moodle/moodle/releases/tag/v5.3.1

The files in the import commit are a snapshot of that tag. Upstream git history was not copied.

## How later changes are logged

Each later change gets its own entry with:

- ID, in the form `WW-LMS-NNN`
- Date
- Plain-English summary
- Folders touched
- Pull request link

## WW-LMS-001 — Import the untouched upstream baseline

- Date: 2026-11-17
- Summary: Brought in Moodle v5.3.1 exactly as released, with no edits to any upstream file. Added this changelog, `NOTICE.md`, and `WOLFWHALE.md` (the short WolfWhale readme, moved here because Moodle already has `README.md`).
- Folders touched: repository root only (`WOLFWHALE.md`, `WOLFWHALE_CHANGELOG.md`, `NOTICE.md`). No upstream folder was changed.
- Pull request: https://github.com/wolfwhale-edtech/wolfwhale-lms/pull/1

## WW-LMS-002 — Cut the plugins schools will not use

- Date: 2026-11-16 to 2026-11-17
- Summary: Removed the sign-in methods, activities, payment tools, and extra site features that WolfWhale schools do not need. A fresh site can be installed and upgraded with no missing-plugin warning. A few core pieces stay in the code because deleting them would break courses, the user list, or the question bank. Those pieces are switched off where Moodle already has a switch.
- Folders removed, all under `public/` unless noted:
  - Sign-in: `auth/ldap`, `auth/shibboleth`, `auth/lti`, `auth/none`
  - Activities: `mod/choice`, `mod/data`, `mod/feedback`, `mod/glossary`, `mod/imscp`, `mod/lesson`, `mod/scorm`, `mod/wiki`, `mod/workshop`, `mod/bigbluebuttonbn`
  - Payments: `payment/`, `enrol/fee`, `enrol/paypal`, and `enrol/lti` because it cannot run without `auth/lti`
  - AI and predictions: `ai/`, `lib/mlbackend`, `report/insights`, `report/aiusage`, the shipped prediction models in `lib/db/analytics.php`, and the prediction target classes under `course/classes/analytics/target` and `user/classes/analytics/target`
  - Extras: `blog/`, `notes/`, `rss/`, `portfolio/`, `plagiarism/`, `mnet/`, `communication/`, `admin/tool/brickfield`, `search/engine/solr`, `admin/mnet`, `admin/ai.php`, `admin/communication.php`, `admin/portfolio.php`, `admin/plagiarism.php`
  - Pieces that only existed to support a removed folder: `blocks/blog_menu`, `blocks/blog_recent`, `blocks/blog_tags`, `blocks/feedback`, `blocks/glossary_random`, `blocks/rss_client`, `blocks/accessreview`, `filter/data`, `filter/glossary`, `lib/editor/tiny/plugins/aiplacement`
- Switched off instead of deleted:
  - The prediction engine in `analytics/` stays. Turning it off is already the Moodle default (`enableanalytics` is 0), and this install writes that same off setting. Deleting the engine would mean rewriting course and user code that still calls it. The shipped models and the machine-learning backend are gone, so the engine has nothing to train.
  - Tags stay, and new sites have them off (`usetags` is 0). Courses, forums, and the question bank still call the tag code.
  - Comments stay, and new sites have them off (`usecomments` is 0). Forums and other kept activities still call the comment code.
  - Ratings stay on. There is no site-wide off switch, and forums use ratings. Deleting them would mean editing the forum.
  - Guest enrolment (`enrol/guest`) stays. Course guest access is built on that plugin, and a new site turns it on with manual, self, and cohort enrolment.
  - The `mnet_host` database table and the local-host row stay. Every user record points at that row. The networking screens and the `mnet/` code are gone.
  - Blog and notes language names stay registered with no code folder, so the mobile app menu can still show those words. The mobile tool itself was not changed.
- What was checked: PHP syntax on all 53,192 PHP files under `public/` (no syntax errors). A fresh install on PHP 8.3.6 and PostgreSQL 17.12 completed with no missing-plugin warning. `admin/cli/upgrade.php --non-interactive` still reports nothing pending. The plugins screen lists 336 plugins and 0 additional plugins, and it does not say "Missing from disk". The activity chooser shows Assignment, Book, File, Folder, Forum, H5P, Page, Quiz, Text and media area, and URL. Login, the site home page, and a test course were opened in a browser. Screenshots from that check were not retaken.
- Tests after the cleanup: A fresh PHPUnit database on PostgreSQL 17.12 ran the whole suite: 26,691 tests, 413,383 assertions, 0 errors, 10 failures, 251 skipped. The 10 failures are the file-download tests in `lib/tests/filelib_test.php`. They ask `download.moodle.org` for a sample file. This machine got a Cloudflare block page back, so the file hash did not match. Moodle already skips them when `TEST_EXTERNAL_FILES_HTTP_URL` is set to false. That switch was left unset. They were not given a new skip. MySQL was not installed on this machine. GitHub Actions then ran the same suite on both databases and both passed with 0 errors and 0 failures: PostgreSQL on PHP 8.4 was 26,703 tests and 413,915 assertions (192 skipped), and MySQL on PHP 8.3 was 26,703 tests and 151,734 assertions (194 skipped). Behat was not run. No data was copied from a real school, and no paid service was called.
- What this cleanup changed: Web service entries for blog, notes, AI, and payment are gone, so a fresh install does not list them. The prediction engine stays off and, with no machine-learning backend installed, it no longer crashes when a test asks for one. Admin presets no longer store lesson, glossary, portfolio, or blog settings. Tests that still need tags or comments turn those on themselves. Forum no longer asks the missing portfolio code for an export link. The old blog, portfolio, payment, and AI tables are not created on a new install, and an upgrade drops them if they are still there.
- Pull request: https://github.com/wolfwhale-edtech/wolfwhale-lms/pull/2

## WW-LMS-003 — Rebrand the site as WolfWhale

- Date: 2026-11-18
- Summary: A person using the site no longer sees the word Moodle in the name, the login page, the footer, or ordinary language strings. The new theme `theme_wolfwhale` is a child of Boost (Boost's files are not copied). It is the theme on a fresh install and on an upgrade from a site that did not have it yet. The look is light sky blue and aqua, with soft glass panels and rounded corners. The wordmark is the text WOLFWHALE in the self-hosted Fjalla One font, colour #2f5d8a. Four accent colours are available as theme variables and are not painted across the page. Sign-in instructions an administrator has written still appear under the wordmark. Capability names such as moodle/role:assign, component names, and other identifiers are not rewritten.
- Classic theme: Moodle 5.3.1 does not include Classic. Nothing was removed.
- Settings written on install and on the upgrade that first adds the theme:
  - `theme` = `wolfwhale` (the automated test suites leave this as Boost)
  - Site full name becomes `WolfWhale LMS` when it is empty or exactly `Moodle`
  - Site short name becomes `WOLFWHALE` when it is empty or exactly `Moodle`
  - A command-line install uses those two names when the installer is not given others
- Files and folders changed, against upstream v5.3.1:
  - `admin/cli/install.php` — default site full name and short name
  - `public/lib/setup.php` — language strings are read through the WolfWhale string manager
  - `public/lib/pagelib.php` — a missing site name in the page title falls back to WolfWhale
  - `public/lib/tests/moodle_page_test.php` — the test expectation for that fallback
  - `public/lib/classes/string_manager_install.php` — same product-name rewrite during install
  - `public/lib/classes/output/core_renderer.php` — the keywords meta tag no longer says moodle
  - `public/theme/wolfwhale/` — the new theme:
    - `version.php`, `config.php`, `lib.php`, `db/install.php`
    - `lang/en/theme_wolfwhale.php`
    - `classes/string_manager.php` — rewrites the product name in language strings
    - `classes/privacy/provider.php`
    - `classes/output/core_renderer.php` — no upstream logo, no moodle.com services link
    - `templates/core_admin/notification_ctas.mustache` — no marketplace or hosting cards
    - `scss/pre.scss`, `scss/post.scss`
    - `templates/core/login_panel.mustache`, `templates/core/loginform.mustache`
    - `templates/theme_boost/footer.mustache` — no "Powered by Moodle" line
    - `tests/string_manager_test.php`
    - `thirdpartylibs.xml`
    - `fonts/fjallaone/fjallaone-regular.woff2` and `fonts/fjallaone/OFL.txt` (SIL Open Font License)
    - `pix/favicon.ico`, `pix/screenshot.png`
- What still shows the word Moodle, on purpose or because changing it would break something:
  - The copyright line on Site administration > Notifications. It reads "Moodle 5.3.1" and links to moodle.org. The GPL notice in that file says the line must stay. `NOTICE.md` and the copyright headers in the code stay as well.
  - The footer link "Documentation for this page" does not say Moodle, but the address is still docs.moodle.org.
  - Page source that a person does not read as a sentence: YUI file names, `@moodle` script paths, and the session cookie name MoodleSession.
  - PHPUnit and Behat keep the upstream English and the Boost theme, because those tests look for the original wording.
  - The old networking row stored in the database still has the display name Moodle. The networking screens are already gone.
  - A server running PHP older than 8.1 prints "Moodle 4.4" and stops. The site cannot run on that PHP.
- Pull request: https://github.com/wolfwhale-edtech/wolfwhale-lms/pull/3

## WW-LMS-004 — Student home

- Date: 2026-11-18
- Summary: A person who is only a student now lands on a home of large picture tiles instead of the usual dashboard. The tiles are Today, Due, Badges, Messages, and Courses. Each tile uses that person's own courses, calendar, badges, and messages. The bar link that used to say Dashboard says Home for a student and opens this page. A teacher, a manager, a course creator, or an administrator still gets the usual dashboard. Points, levels, avatars, a library, and maps are not on the page, because those parts are not built yet.
- How a student is recognised: they are signed in, they are not a guest, they are not a site administrator, and they do not hold a teacher, editing-teacher, manager, or course-creator role anywhere on the site. A student who also teaches another class keeps the dashboard.
- What each tile shows, and where one tap goes:
  - Today: the next activity on their calendar. The tap opens that activity. If nothing is coming up, the tile says "Nothing today" and opens the calendar.
  - Due: how many assignments and quizzes are still ahead. One item opens that activity. More than one opens the upcoming calendar. If there are none, the tile says "Nothing due".
  - Badges: badges they have earned. One badge shows its name. More than one shows the count. The tap opens their badges. If badges are switched off, the tile is not there.
  - Messages: the number of unread conversations, the same number Moodle already uses. The tap opens messages. If messaging is switched off, the tile is not there.
  - Courses: one course shows its name and opens it. More than one shows the count and opens the course list. No courses says "None".
- Read aloud: each tile has a speaker button, and there is one button that reads the whole screen. Both use the browser's own speech, which does not need the internet. If the browser cannot speak, the buttons are not shown.
- Look: glossy sky-blue glass tiles with rounded corners, matching the WolfWhale theme. Messages uses the indigo accent, Badges the teal accent, Due the yellow accent, and the speaker buttons the red accent. The icons are original drawings stored in the page. Nothing is loaded from another site.
- The page does not store any personal data. It only reads what Moodle already stores for the signed-in person.
- Folders touched: `public/local/wolfwhale/` (new), `NOTICE.md`, `WOLFWHALE_CHANGELOG.md`.
- Pull request: https://github.com/wolfwhale-edtech/wolfwhale-lms/pull/4

## WW-LMS-005 — Teacher home

- Date: 2026-11-18
- Summary: A person who teaches a course now lands on a home of large picture tiles instead of the usual dashboard. The tiles are Post, Marking, Attendance, Messages, Stuck, and Marks. Each tile uses that teacher's own courses. A person who is only a student still lands on the student home. A site administrator still gets the usual dashboard, even if they also teach. A manager or a course creator who does not teach a course still gets the usual dashboard. A person who is a student in one course and a teacher in another lands on the teacher home.
- How a teacher is recognised: they are signed in, they are not a guest, they are not a site administrator, and they hold a teacher or editing-teacher role on a course, or on a category that contains that course. A teacher or editing-teacher role on the whole site does not by itself open this home, and it does not list every course.
- What each tile shows, and where one tap goes:
  - Post: the course name when there is one course they can change, or the count when there are several. The tap opens those courses. Each course has Lesson, File, and Assignment. Lesson opens the real add screen for a page. File opens the real add screen for a file. Assignment opens the real add screen for an assignment. Nothing is created until that screen is saved. A non-editing teacher cannot change activities, so Post says "None". The separate lesson activity is not in this fork, so a lesson here is a page.
  - Marking: how many submitted assignments still need a grade. The tap opens the list. Each row opens that submission in the grader. A grade already saved is not in the list. A team assignment that still needs grading is one row for the assignment. Another teacher's course is not listed. If the grade capability is prohibited, the row is not listed.
  - Attendance: the course name when there is one course, or the count when there are several. One course opens the take-attendance page. Several courses open the list, and each course opens its own page. The page lists enrolled students and stores present, absent, or late for that day. A second save on the same day updates the same day. A student, a manager, and a teacher of another course cannot open it.
  - Messages: the number of unread conversations, the same number the site already uses. The tap opens the students in this teacher's courses. Each name opens a conversation with that student. If messaging is switched off, or this person cannot send messages, the tile is not there.
  - Stuck: how many students, per course, have overdue work or have not opened the course in 7 days. Overdue work is an assignment whose due date has passed with no submission, or a quiz that has closed with no finished attempt. An extension that moves the date into the future is not overdue. A visit 6 days ago is not listed for absence. A visit of 7 days, or no visit at all, is listed. The tap opens the list. An overdue assignment opens that student's grader row. A closed quiz opens the quiz. A long absence opens that person's course profile.
  - Marks: one course opens the grader report. Several courses open a list, and each course opens its grader report. If the grader report capability is prohibited, the course is not listed.
- Read aloud: the teacher home uses the same speaker buttons as the student home. They use the browser's own speech. If the browser cannot speak, the buttons are not shown.
- Look: the same glossy sky-blue glass tiles. Post and Messages use the indigo accent, Attendance the teal accent, Marking and Marks the yellow accent, Stuck and the speaker buttons the red accent. The icons are original drawings stored in the page. Nothing is loaded from another site.
- Attendance stores personal data: the day, the course, who was present, absent, or late, and who took the register. Deleting a student removes that student's marks and leaves everyone else. Deleting the person who took the register clears that name and leaves the marks. The teacher home itself still stores nothing.
- Treaty Education outcomes: no outcome text was added, and no hook was added. There is no outcome source in this fork to attach a hook to, and inventing the wording was not allowed.
- What was checked: PHPUnit for the new data and capability checks, 35 tests and 232 assertions, including the existing student-home tests. A local site was installed and opened in a browser with made-up names. A teacher landed on the teacher home, the empty teacher home showed None where there was nothing to do, the attendance page showed the saved present, absent, and late marks, a student still landed on the student home, and an administrator still landed on the usual dashboard.
- Files and folders changed, against upstream v5.3.1:
  - `NOTICE.md` — the teacher-home icons
  - `WOLFWHALE_CHANGELOG.md` — this entry
  - `public/local/wolfwhale/version.php` — version raised to 2026111802
  - `public/local/wolfwhale/index.php` — a teacher who opens the student home is sent to the teacher home
  - `public/local/wolfwhale/classes/hook_callbacks.php` — login, dashboard, and the bar link for a teacher
  - `public/local/wolfwhale/classes/teacher.php` — new, the teacher-home data
  - `public/local/wolfwhale/teacher.php` — the teacher home
  - `public/local/wolfwhale/post.php` — Lesson, File, and Assignment
  - `public/local/wolfwhale/marking.php` — submissions that need a grade
  - `public/local/wolfwhale/attendance.php` — courses where attendance can be taken
  - `public/local/wolfwhale/stuck.php` — students who are overdue or away
  - `public/local/wolfwhale/marks.php` — grader report links
  - `public/local/wolfwhale/students.php` — students this teacher can message
  - `public/local/wolfwhale/lang/en/local_wolfwhale.php` — the new words
  - `public/local/wolfwhale/styles.css` — tile and list styles
  - `public/local/wolfwhale/templates/teacher.mustache` — the teacher home
  - `public/local/wolfwhale/templates/list.mustache` — the lists
  - `public/local/wolfwhale/templates/post.mustache` — the add screen
  - `public/local/wolfwhale/tests/teacher_test.php` — data and capability tests
  - `public/local/wwattendance/` — new attendance plugin:
    - `version.php`
    - `db/install.xml` — one table for the day, one table for each person's mark
    - `db/access.php` — take attendance, for teacher and editing teacher
    - `lang/en/local_wwattendance.php`
    - `classes/attendance.php`
    - `classes/privacy/provider.php`
    - `take.php` — one course, one day
    - `templates/take.mustache`
    - `styles.css`
    - `tests/attendance_test.php`
- Pull request: https://github.com/wolfwhale-edtech/wolfwhale-lms/pull/5

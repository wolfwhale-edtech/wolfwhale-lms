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
- Tests after the follow-up: The quiz suite, run on its own, is 638 tests, 3,740 assertions, 0 failures, 0 errors, 23 skipped, and 1 notice. The first quiz run had 107 errors and 3 failures. Those errors were one tag-filter backup test failing, then every later backup test dying because PostgreSQL had already dropped a temporary table. Tags are turned on inside the tests that still need them, and dropping that temporary table is now safe when it is already gone. The whole PHPUnit run is 26,743 tests and 412,167 assertions, with 93 errors and 175 failures. Before this follow-up the reported suites were: badges 3 errors in 300, competency 2 errors in 299, grades 3 errors in 247, assignment 2 errors and 4 failures in 559, forum 1 error and 9 failures in 638, quiz 107 errors and 3 failures in 638, course 67 errors and 8 failures in 822, and the core library 29 errors and 54 failures in 8,116. Behat was not run. No data was copied from a real school, and no paid service was called.
- What is still failing, and why: About 20 errors are web-service checks for blog, notes, AI, and payment. Those definitions are gone from the code, but the existing test database still lists them because the test-site refresh did not rebuild that list. About 18 errors are the prediction engine asking for the deleted Python backend. File download tests (10) compare a file fetched from the internet and do not match in this environment. The rest are smaller leftovers: admin presets still store lesson and portfolio settings, a few tests still build Choice or look up the feedback table, and some tag tests still expect tags to be on. None of these were hidden with a skip.
- Pull request: https://github.com/wolfwhale-edtech/wolfwhale-lms/pull/2

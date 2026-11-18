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
- Summary: A person using the site no longer sees the word Moodle in the name, the login page, the footer, or ordinary language strings. The new theme `theme_wolfwhale` is a child of Boost (Boost's files are not copied). It is the theme on a fresh install and on an upgrade from a site that did not have it yet. The look is light sky blue and aqua, with soft glass panels and rounded corners. The wordmark is the text WOLFWHALE in the self-hosted Fjalla One font, colour #2f5d8a. Four accent colours are available as theme variables and are not painted across the page. Sign-in instructions an administrator has written still appear under the wordmark.
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
    - `classes/output/core/admin_renderer.php` — no marketplace or hosting cards
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

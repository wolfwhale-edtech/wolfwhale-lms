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

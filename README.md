# Completion Doctor (local_completiondoctor)

Completion Doctor audits Moodle course completion setup and SCORM activities.

- Finds courses where learners can never complete, or complete when they should not.
- Analyzes SCORM packages (version, SCOs, mastery score, authoring tool, reported statuses, size)
  and recommends matching Moodle completion settings.
- Explains why a single learner has not completed a course.

Status: **alpha (0.1.0), under development.**

## What it checks

32 checks in three groups: course completion (17), site (3) and SCORM (12). Each check, what it means
and how to fix it is described in [docs/checks.md](docs/checks.md).

## Where to find it

- **Course report:** *Completion health* in the course menu (teachers with `viewcoursereport`). Shows
  problems by severity, with *Preview fix*, *Ignore* and *Undo fix*.
- **SCORM panel:** *SCORM analysis* link on a SCORM finding: package details, what learners reported,
  recommended settings with confidence, connection speed and display size.
- **Learner diagnostic:** from the course report; pick an enrolled learner to see which conditions block them.
- **Link check (extra):** *Check links* on the course report checks every link of that one course in the
  background: texts (course and section summaries, activity descriptions, pages, book chapters, lesson pages,
  glossary entries, questions, URL activities) and links inside PDF, Word, PowerPoint and Excel files. Broken
  links (page not found, domain that does not exist, deleted activity or course, missing file, draft file link)
  and links that could not be verified are listed with where they are and an *Edit* link. Forum posts are not read.
  Optionally (off by default) new courses are checked automatically: a task every 10 minutes checks courses
  created in the last 30 days whose content changed since their last check. Existing courses are only checked
  on request.
- **Site dashboard:** *Site administration > Reports > Completion Doctor*: health of all courses, top problems,
  site health, filters, export, *Scan all courses* and *Cancel scan*.
- **Notifications (optional, off by default):** a popup notification (never e-mail) to the people who can see
  the course report when a course that was checked before gets a new Critical problem.
- **Logs:** report views, learner diagnostics, fixes, undos, ignores, scans and link checks are recorded in
  Moodle's standard log.

Screenshots for the plugins directory (to be added): course report with a fix preview, SCORM panel,
learner diagnostic, site dashboard, mobile view of the course report.

## Requirements

- Moodle 4.5 LTS to 5.3 LTS (both the classic and the `/public` directory layout).
- PHP 8.1 or later, as required by your Moodle version.
- MySQL, MariaDB or PostgreSQL.

## Installation

### From ZIP

1. Download `local_completiondoctor-<version>.zip`.
2. Go to *Site administration > Plugins > Install plugins* and upload the ZIP,
   or unzip it into `local/completiondoctor` (`public/local/completiondoctor` on Moodle 5.1+).
3. Visit *Site administration > Notifications* to finish the installation.

### From source

```bash
git clone <repository-url> local/completiondoctor
php admin/cli/upgrade.php
```

### Building the ZIP

The release ZIP is built with the build script of the plugin's development repository, which also
holds the CI configuration. The ZIP leaves out development files (fixture sources, local PHPUnit
configuration).

## Capabilities

| Capability | Default roles | Context |
|---|---|---|
| `local/completiondoctor:viewcoursereport` | Teacher, Manager | Course |
| `local/completiondoctor:viewlearnerdiagnostic` | Teacher, Non-editing teacher, Manager | Course |
| `local/completiondoctor:ignorefinding` | Teacher, Manager | Course |
| `local/completiondoctor:applyfix` | Manager | Course |
| `local/completiondoctor:checklinks` | Teacher, Manager | Course |
| `local/completiondoctor:viewsitereport` | Manager | System |
| `local/completiondoctor:runscan` | Manager | System |

## CLI

```bash
php local/completiondoctor/cli/scan.php --help        # audit courses (dry run unless --save)
php local/completiondoctor/cli/scan.php --all --queue # queue background audits of all courses
php local/completiondoctor/cli/fix.php --help         # preview, apply (--confirm) or undo a fix
php local/completiondoctor/cli/analyze.php --file=package.zip   # analyze a SCORM package without a course
php local/completiondoctor/cli/analyze.php --all --queue        # analyze every SCORM package in the background
```

On Moodle 5.1 and later the path is `public/local/completiondoctor/cli/...`.

For development, `tests/cli/seed_demo.php` creates demo courses that trigger each check (test sites with at most 100 users only).

## Extending

Other plugins can add checks by listening to the `\local_completiondoctor\hook\register_checks` hook
in their `db/hooks.php` and calling `$hook->add_check(new my_check())`, where `my_check` extends
`\local_completiondoctor\check\base_check`.

## Large sites

Completion Doctor is built to run on sites with many thousands of courses and tens of millions of log and SCORM tracking rows. Installing it starts no checks: the scheduled scan, the automatic re-check after course changes and the automatic link checks are all off. Only an hourly clean-up task runs, and it removes old data of the plugin itself.

- **Scans are paced.** "Scan all courses" queues batches of courses (default 20) that start 30 seconds apart, and at most one batch runs at a time. Raise the limit with `$CFG->task_concurrency_limit['local_completiondoctor\task\audit_course']` if you want faster scans.
- **SCORM packages are analyzed a few at a time.** Packages up to 10 MB are analyzed during checks; bigger ones are queued and analyzed by a scheduled task, 10 per run by default, optionally only during set hours (for example `0-6`).
- **Logs are not scanned.** The plugin records when completion settings are saved, from the moment it is installed.
- **SCORM tracking is sampled.** The latest 200 attempts per activity are read in one query and cached for a day.
- **Checks run in the background.** *Check again now*, *Analyze now* and the first visit of a course report queue a task; the page shows "Checking…" and updates itself when it is done. Clicks never run a check inside the request.
- **Old data is cleaned up hourly:** finished scans and link checks, resolved findings older than 90 days, and analyses of package files that no longer exist. Scans stuck for 6 hours are cancelled and link checks without progress for 2 hours are marked as failed.
- **Pause all scans** (Load control settings) stops scans and package analysis immediately; queued work continues when you turn it off.

- **Link checks are per course and on request.** One check runs at a time, at most 500 outside addresses
  per check, one request per second to the same site, 8 seconds timeout, results reused for 24 hours. Requests go
  through Moodle's own HTTP client, so the site's blocked hosts and ports (*Site administration > General >
  Security > HTTP security*) apply. A mistyped domain that someone has registered still loads a page and cannot be
  told apart from a working link.

On a large site, run the first full scan at night.

## Web services

The pre-built service **Completion Doctor API** (short name `local_completiondoctor`) is disabled by default and restricted to authorised users. Enable it under *Site administration > Server > Web services > External services*. Every function checks the capability named below in the relevant context.

| Function | Parameters | Returns | Capability |
|---|---|---|---|
| `local_completiondoctor_audit_course` | courseid, refresh | Counts by severity, last audit, open findings (refresh queues a new check in the background) | viewcoursereport |
| `local_completiondoctor_get_course_status` | courseid | Whether a check of the course is waiting or running | viewcoursereport |
| `local_completiondoctor_get_findings` | categoryid, severity, checkid, status, page, perpage | Paged findings across the site | viewsitereport |
| `local_completiondoctor_analyze_scorm` | cmid, refresh | Package analysis, what learners reported, recommended settings, size estimate, display (refresh queues a new analysis) | viewcoursereport |
| `local_completiondoctor_analyze_package` | draftitemid (zip uploaded with `core_files_upload`, up to the inline analysis size) | The same, without a course | viewsitereport |
| `local_completiondoctor_preview_fix` | findingid, params | What would change, warnings, parameters | applyfix |
| `local_completiondoctor_apply_fix` | findingid, params | Result and fix log id | applyfix |
| `local_completiondoctor_undo_fix` | fixlogid | Result | applyfix |
| `local_completiondoctor_ignore_finding` | findingid, reason, ignore | New status | ignorefinding |
| `local_completiondoctor_explain_user_completion` | courseid, userid | Conditions, their state and what blocks completion | viewlearnerdiagnostic |
| `local_completiondoctor_get_scan_status` | none | Progress of the latest site scan | viewsitereport |

## Testing

```
vendor/bin/phpunit --testsuite local_completiondoctor_testsuite
vendor/bin/behat --config <behat.yml> --tags=@local_completiondoctor
```

The Behat context adds the step `the Completion Doctor demo course "<check>" exists` and the named pages
*Course report*, *Learner diagnostic*, *SCORM panel* and *Dashboard* (prefix `local_completiondoctor >`).

## Configuration

Site administration > Plugins > Local plugins > Completion Doctor: scanning, SCORM analysis limits and speed targets, site check thresholds, the teacher banner, and per-check enable and severity settings.

## Privacy

Audits are read-only. The plugin stores only the user id of people who ignore findings, apply fixes or start scans.

## License

GNU GPL v3 or later. See `LICENSE`.

© 2026 Sebale

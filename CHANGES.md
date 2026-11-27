# Changelog

## 0.3.0 (unreleased): Chronify.Compliance, step A1

- Renamed from Completion Doctor (`local_completiondoctor`) to Chronify.Compliance (`local_chronifycompliance`).
  This is a fresh install: tables, capabilities, settings and language strings use the new name, and the
  old upgrade steps are removed.
- Course completion checks moved from the `check\course` namespace to `check\completion`.
- Step A2: every check belongs to a pack (Completion, SCORM and content, Site and performance, Security).
  The registry filters and groups checks by pack, and the checks settings page lists them under a heading per pack.
  Checks added through the `register_checks` hook can choose a pack; without one they go to Site (site scope)
  or Completion.
- Step A3: data layer core. New table `local_chronifycompliance_refresh` holds each dataset's read position
  (high water mark) and last refresh outcome. Datasets (`data\dataset`, discovered in `data\datasets`) read
  source rows after the high water mark in batches; the refresher saves each batch and the new mark in one
  transaction, so an interrupted refresh resumes without duplicates. A scheduled task refreshes due datasets
  inside the refresh window (default 1 to 5) within a time budget; an ad hoc task serves Refresh now. Both
  respect Pause all scans. New settings: refresh window, rows per batch, seconds between batches, minutes per run.
- Step A4: first dataset, Activity. One pass over the standard log fills events per user, course and day
  (`local_chronifycompliance_actday`), site totals per day with active users, logins, failed logins and completions
  (`local_chronifycompliance_siteday`), and the latest completion per user and course (`local_chronifycompliance_compl`).
  The first load reads the last 90 days (setting); daily data is kept 730 days (setting). Privacy API covers the new
  tables; deleting a course removes its rows. Measured on 100,000 users and 7.5 million log rows in 90 days: first
  load 39 seconds of work, 13 minutes with the default pause between batches.

## 0.2.3 (unreleased): QA fixes

- Site dashboard: "Scan all courses" and "Cancel scan" stay together on the right while a scan runs (VM-317).
- Site dashboard downloads (CSV, Excel, HTML, JSON, ODS, PDF) no longer get a debugging message added at the end, which broke the Excel, ODS and JSON files on sites that show debugging messages (VM-316).
- Site dashboard: the Critical, Warnings and Info counts and filters now include every course with at least one problem of that level, so a course with a critical problem and a warning appears under both. The tiles are renamed "Courses with warnings" and "Courses with suggestions" (VM-313).

## 0.2.2 (unreleased): code review round 2

- A check answers a "Check again" request made in the same second it started, so the page no longer stays on "Checking…".
- While scans are paused, batches of a cancelled scan end at once instead of waiting for the pause to end.
- Development tooling (ZIP build script, CI configuration, research notes) is no longer part of the plugin folder.

## 0.2.1 (unreleased): code review round 1

- First release: the database is created from `install.xml`; the upgrade steps of the development versions are removed (test sites with a version before 0.2.0 need a reinstall).
- "Check again" while a check of the same course is running queues a new check, and a check only answers requests made before it started. Requests never answered are cleared after an hour.
- Scans paused for a long time are no longer cancelled when the pause ends; stuck scans are cancelled only by the clean-up task, never by a page view. Cancelling a scan no longer deletes rows of Moodle's task queue: waiting batches skip themselves, and a running batch stops before its next course.
- A SCORM package is never analyzed twice at the same time (lock per package).
- Link checks whose task died are marked as failed after two hours without progress, so the button works again.
- `datecriteria`: the enrolment period of manual enrolment is only a default and no longer counts as a limit.
- `selfcompletionblock`: a Self completion block shown on every course page from the site or a category counts.
- `circular`: course prerequisites are read once per check, not cached for the whole process.
- Bulk changes of findings and stored links set their modification time and user.
- The clean-up task runs hourly.
- The demo seeder moved to `tests/cli/seed_demo.php`. Unused strings removed. American spelling throughout (the event is now `package_analyzed`).

## 0.2.0 (unreleased): senior review

- Fixes use core APIs (completion criteria classes, the course completion updated event, core's activity completion update) and never recalculate completion, like Moodle's own completion settings page. The preview says how many learners met every other condition.
- Undo refuses, with a clear message, when the setting was changed after the fix.
- Checks run in the background: re-check, SCORM analysis and the first report visit queue a task; pages poll and update themselves. Web services queue instead of running checks.
- Automatic link checks are off by default and run from a scheduled task that picks new courses whose content changed (course cache revision); no event observers. New table `local_completiondoctor_linkcheck`.
- Moodle log events for report views, learner diagnostics, SCORM analysis views, the dashboard, fixes, undos, ignore and reopen, scans and link checks.
- Learner diagnostic respects separate groups and shows the site's identity fields.
- Turning a check off resolves its open findings; changing a check's severity updates stored findings.
- Scans: cancel, stuck scans cancelled after 6 hours, no duplicate re-checks. Package analysis gives up after 3 failed attempts. Dry runs store nothing.
- Daily clean-up task. Fix log rows are deleted with their course.
- Names and dates in messages are formatted when shown (multi-language names, the viewer's time zone); course loops list course names.
- Checks: `unreachable` treats past dates, closed activities and empty groups as warnings, ignores dates after the course end, and follows Moodle 5 subsections; `addedaftercriteria` no longer reads the log and only reports key activities; `manualrole` checks the capability and active users; `prereqbroken` judges only the prerequisite's own settings; SCORM checks compare scores in the package's own units and treat "failed" as proof of pass/fail reporting.
- New checks: `activityrules` (assignment without submissions, H5P without tracking, quiz with limited attempts), `enrolment` (no open enrolment method), `datecriteria` (duration longer than enrolment, completion date after the course end).
- New setting *SCORM pop-ups open as full browser tabs*. Connection speed is now shown as a size estimate.
- Optional popup notification to teachers about new Critical problems (off by default).
- Theme colors instead of fixed colors; demo course generator moved to `tests/classes`.
- Persistent classes for every table (`usermodified` and `timemodified` everywhere), privacy provider updated.
- Fixed on a real site: SQL that joined core and plugin text columns failed where the tables have different collations.

## 0.1.0 (unreleased)

- Plugin skeleton: version, capabilities, settings page, privacy provider, language strings.
- Rule engine: checks, findings with fingerprints, audit context, course and site auditors, check registry with the `register_checks` hook.
- Data model: findings, SCORM package cache, fix log and scan tables.
- Fixer with preview, apply, undo and a fix log.
- Queued scans in batches (ad-hoc tasks with progress), a disabled scheduled site scan, optional re-audit after course changes.
- CLI: `scan.php`, `fix.php`, `seed_demo.php`.
- Privacy provider (metadata, export, anonymization).
- 13 course checks: trackingoff, nocriteria, trackednotrequired, addedaftercriteria, weakcondition, nopassgrade, overlapgrade, unreachable, circular, selfcompletionblock, prereqbroken, manualrole, requireddeleted. Fixes with undo for 7 of them.
- Audit table: last audit per course (time, duration, counts, errors).
- Demo courses for every check.
- 3 site checks (completiontasks, cronstale, completiondisabled), also shown on Site administration > Reports > System status.
- SCORM analyzer: manifest, authoring tool (Storyline, Rise; experimental Captivate, iSpring, Adapt, Evolve, Lectora), reporting mode, stage size, connection speed, language builds, cached by content hash.
- Runtime evidence from learners' attempts (aggregates only), recommendations with confidence.
- 10 SCORM checks: scormstatusmismatch, scormrequireview, scormnostatus, scormmultisco, scormpassgrade, scormheavy, scormstage, scormmultibuild, scormreplaced, scormsuspenddata.
- `cli/analyze.php` for a zip file, an activity, or every package on the site (inline or queued).
- Course report with fix preview, apply, undo, ignore and reopen; SCORM analysis panel; learner completion diagnostic; site dashboard (report builder, filters, export, scan progress); warning banner for teachers; "Completion health" in the course menu.
- Web services: audit_course, preview_fix, apply_fix, undo_fix, ignore_finding, get_scan_status.
- Web services: get_findings, analyze_scorm, analyze_package, explain_user_completion; pre-built service "Completion Doctor API" (disabled by default).
- Redesigned dashboard and course report: health tiles, top problems, site health, view chips, severity pills.
- Production safeguards: criteria save time recorded by the plugin (new audit field), bounded log lookup, single-pass cached SCORM evidence (sample 200), package analysis queue with hours and per-run limits, paced scans with one runner, media read budget, Pause all scans.
- Behat features: course report (fix, undo, ignore, reopen, access), dashboard (filters, scan), learner diagnostic, SCORM panel.
- Documentation of every check (`docs/checks.md`), shipped in the ZIP.
- Tested: install, upgrade from an earlier schema and uninstall on Moodle 4.5 (PostgreSQL) and 5.3 (MariaDB).
- Link check (extra): per-course, on-request check of links in texts and in PDF and Office files; internal links (deleted activities and courses, missing files, draft file links) and outside links (DNS, HTTP status), with throttling, limits and reuse of recent results. New table `local_completiondoctor_link`, capability `checklinks`, settings under *Link check*.
- Automatic link checks of new courses: after creation, copy or restore, and 10 minutes after changes during the first 30 days (settings *Check new courses automatically*, *New course period*).
- Link check tested end to end with real documents (Chrome and LibreOffice PDF, Word, Excel); these are now test fixtures. Refresh link on the links panel reloads the page.
- Links panel shows where each link is: grouped by course summary, section or activity (icon, type, name, section, Open and Edit), with the link text the learner sees and the field or file it is in. New field `linktext` in `local_completiondoctor_link`.
- Course report shows SCORM packages still waiting for analysis (large packages are analyzed in the background), with an *Analyze now* button that analyzes the package and checks the course again.
- New SCORM check `scormquiz` (quiz and completion): minimum score unreachable (points, not percent), different from the quiz pass mark, or quiz ignored by completion; fix sets the minimum score. Storyline pass mark is now read only from quiz scorings; learner evidence records the maximum score.
- New checks: `scormgradenoscore` (SCORM requires a grade but the package sends no score: grade 0 completes at once or always fails) and `passgradeunreachable` (grade to pass above the maximum grade, course grade condition above the maximum, quiz without marks), both fixable.

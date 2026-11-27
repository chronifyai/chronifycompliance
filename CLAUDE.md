# Chronify.Compliance (local_chronifycompliance)

One free Moodle plugin that checks completion, SCORM, content, security and framework
controls, shows one health score, and fixes what it finds. Built on Completion Doctor 0.2.3
(the first commit of this repo).

- Plan: https://claude.ai/artifact/LdDgB8hxY9ZB1v5feLZ3Q2 (Plan tab, UX and UI tab)
- Build guide, one step per session: https://claude.ai/artifact/E9tZ6VhFyXkwHPDxd5bPKY

## Decisions (settled 2026-11-26)

| Topic | Decision |
| --- | --- |
| Name | Display name Chronify.Compliance, component `local_chronifycompliance` |
| Moodle versions | 4.5 to 5.3, PHP 8.1+, PostgreSQL, MySQL, MariaDB |
| Outbound calls | None in this build. Nothing is sent to hub.chronifyai.com or anywhere else |
| Reports | The ten reports follow the roadmap (Phase 3). The data layer and the Data and refresh page are built now |
| Reserved tables | `connect` and `fixplan` are created in step A7 but nothing writes to them yet |
| Fix it for me | A button with no action for now |
| Launch frameworks | NIST CSF 2.0, GDPR, FERPA. A named reviewer signs off each framework file |
| Health score weights | Config in code (Security 35, Completion 30, Site 20, SCORM and content 15), tuned later |
| Vulnerability list | Ships in the plugin, rebuilt by a repo script before each release |

## Rules for every change

- One build guide step per branch and pull request. Branch name: `step/<id>-<slug>`, for example `step/a1-rename`.
- Pages never query live Moodle tables for heavy data. They read the plugin's own tables. Logs, SCORM
  tracking and file storage are read only by refresh tasks, incrementally, in batches.
- Checks are read only (`run()` never writes). Writes happen only through `fix\fixer`: transaction, fix log, undo.
- Every new check: language strings `check:<id>:name` and `check:<id>:desc`, an entry in `docs/checks.md`,
  a PHPUnit test, and a demo scenario in `tests/classes/demo/scenario/`.
- Every new table: `db/install.xml`, Privacy API entries in `classes/privacy/provider.php`, cleanup in the cleanup task.
- Security fixes always preview and confirm. Nothing security related is applied automatically.
- User facing text: plain words, no em dashes and no en dashes.
- Moodle coding style: phpcs and phpdoc with zero warnings, GPL header on every file.
- If the plan is unclear, ask in the pull request instead of guessing.

## Folder map

- `classes/check/` engine (`base_check`, registry, auditors, `finding`) and the checks by pack
- `classes/fix/` fixer, previews, fix log, undo
- `classes/scorm/` SCORM package analysis
- `classes/links/` link check
- `classes/local/` scan manager, stores, dashboard data, access
- `classes/output/`, `templates/`, `amd/src/` screens
- `classes/task/` scheduled and ad hoc tasks
- `tests/` PHPUnit, Behat, demo scenarios and seeder
- `dev/` development scripts (not part of the release ZIP)

## Local test site

```bash
dev/setup-testsite.sh            # Moodle 4.5 + PostgreSQL in ~/moodle-dev, plugin linked, PHPUnit ready
dev/run-tests.sh                 # this plugin's PHPUnit suite
dev/run-tests.sh ~/moodle-dev/moodle405 --filter=scan_manager
```

The first setup takes about 10 minutes (Moodle clone, install, PHPUnit init). Baseline before any
change: 153 PHPUnit tests, all passing.

## Release ZIP

Leave out `dev/`, `.github/`, `CLAUDE.md` and test fixture sources when building the ZIP (step F7).

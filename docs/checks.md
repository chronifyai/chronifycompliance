# Chronify.Compliance checks

Every check is read-only. Fixable checks show a preview first, write a fix log entry, and can be undone.
Each check can be switched off, or given another severity, in *Site administration > Plugins > Local plugins > Chronify.Compliance > Checks*.

Severity: **Critical** means learners can never complete, or complete when they should not. **Warning** means a likely mistake. **Info** is a suggestion.

## Course completion

### No completion conditions (`nocriteria`) · Critical
Course completion is on, but no completion conditions are set, so nobody can ever complete the course. It is Critical when activities track completion (completion is clearly intended), Info otherwise. **Fix:** open *Course completion* and choose the conditions.

### Completion tracking off (`trackingoff`) · Info · fixable
Activities have completion conditions, but completion tracking is off in the course settings, so nothing is recorded. **Fix:** turn on completion tracking (the plugin's fix does this, and undo turns it off again).

### Key activity not required (`trackednotrequired`) · Warning · fixable
A graded or key activity (quiz, SCORM, assignment, H5P, lesson) tracks completion but is not one of the course completion conditions, while other activities are. Usually the activity was forgotten. It is skipped when the activity conditions use "any". **Fix:** add the activity to the course completion conditions.

### Activity added after completion was set up (`addedaftercriteria`) · Warning · fixable
A key activity (quiz, SCORM, assignment, H5P, lesson) or an activity with a grade or submission rule was added after the completion settings were last saved, and is not required. Moodle does not add new activities to the conditions by itself. The save time comes from the plugin's own record, so a course is checked once its completion settings have been saved after the plugin was installed. Logs are not read. **Fix:** add the activity to the conditions.

### Weak completion condition (`weakcondition`) · Info
A required activity completes just by opening it, or by the learner ticking it. Completion then does not show that the work was done. SCORM activities are covered by "SCORM completes on view". **Fix:** use a stronger rule (grade, submission, passing grade) where it matters.

### Pass grade missing (`nopassgrade`) · Critical · fixable
"Receive a passing grade" is required but the grade to pass is 0, so any grade counts as a pass. Or the course grade condition has no pass value, so nobody can ever meet it. **Fix:** set the grade to pass (you enter the value in the preview).

### Pass grade can never be reached (`passgradeunreachable`) · Critical · fixable
The grade to pass is higher than the activity's maximum grade (Moodle does not check it again when the maximum grade is lowered later), the course grade condition is higher than the maximum course grade, or a quiz has no questions with marks. Nobody can pass, so nobody completes. **Fix:** set a grade to pass up to the maximum (you enter the value); an empty quiz needs questions.

### Grade completion without a pass requirement (`overlapgrade`) · Warning or Info · fixable
The activity completes on "receive a grade" but has a grade to pass. If the grade item is hidden, Moodle ignores the pass mark and failing learners complete it (Warning). If it is visible, failing learners see "complete (failed)", which does not count towards course completion; that is correct but often surprises teachers (Info). **Fix:** also require a passing grade.

### Required activity unreachable (`unreachable`) · Critical, or Warning
A required activity cannot be opened by a normal learner: it, its section or the subsection holding it (Moodle 5) is hidden, or its access restrictions can never be met (a deleted activity, grade item, group or grouping, or an activity without completion). Critical. These are warnings: hidden only from the course page, an empty group (it may fill at enrolment), an "until" date in the past, and a passed close date of a quiz, SCORM, choice or feedback or cut-off date of an assignment or forum. Dates are ignored once the course end date has passed. "Or" restrictions are only reported when every branch is impossible. Restrictions from other plugins (profile fields, third-party conditions) are not judged. **Fix:** correct the visibility, the restriction or the date.

### Circular dependency (`circular`) · Critical
Activities whose restrictions need each other to be completed first (directly, through their sections, or through "previous activity"), or courses that are each other's completion prerequisite. Nobody can get past them. **Fix:** remove one of the restrictions in the loop.

### Self completion block missing (`selfcompletionblock`) · Critical · fixable when the block is enabled
Self completion is a condition, but the course page shows no Self completion block (a block added to the site or a category with "Display throughout" counts). Moodle disables this block on new sites; then learners can only mark themselves complete in the Moodle app. **Fix:** add the block, or enable it on the site first, or use another condition.

### Broken prerequisite course (`prereqbroken`) · Critical
A course required for completion no longer exists, does not track completion, or has no completion conditions itself. **Fix:** update the prerequisite condition or fix the other course.

### Nobody can mark completion (`manualrole`) · Critical
"Manual completion by others" uses a role that no active (not suspended) user has in the course or above it, a role that is not allowed to mark completion (`moodle/course:markcomplete`), or a role that no longer exists. **Fix:** assign the role or the capability, or change the condition.

### Activity settings block completion (`activityrules`) · Critical, or Warning
An activity's own settings work against its completion rule: an assignment requires a submission but no submission type is enabled; an H5P activity requires a grade but attempt tracking is off; a quiz requires a passing grade with limited attempts and without "or all available attempts completed" (always a warning). Critical for required activities, warning for the others. **Fix:** change the activity settings or the rule.

### Learners cannot enrol (`enrolment`) · Warning
The course has completion conditions but no enabled enrolment method (guest access does not count), or every method has passed its end date. Courses that have ended are skipped. **Fix:** enable an enrolment method or extend its dates.

### Date or duration condition cannot be met (`datecriteria`) · Critical, or Warning
"Days after enrolment" is longer than every enabled enrolment method lasts, so enrolments end first (critical). Manual enrolment counts as unlimited: its period is only the default of the enrol dialog. Or the completion date is after the course end date (warning). **Fix:** shorten the condition, lengthen the enrolment, or correct the dates.

### Required activity deleted (`requireddeleted`) · Critical · fixable
A completion condition refers to an activity that was deleted, is being deleted, or belongs to another course. With "all activities" nobody can complete. **Fix:** remove the condition (undo adds it back). Learners who met every other condition are marked complete the next time their progress changes; the fix does not recalculate completion.

## Site

### Completion tasks (`completiontasks`) · Critical or Warning
The scheduled tasks "Calculate regular completion data" and "Completion mark as started" are disabled (Critical), have never run, are late or are failing (Warning). Learners are not marked complete while they do not run. **Fix:** enable the tasks and make sure cron runs.

### Cron not running (`cronstale`) · Critical
Cron is disabled, has never run, or has not started recently (setting, default 15 minutes). **Fix:** fix the cron job of the server.

### Completion disabled on the site (`completiondisabled`) · Critical
Completion tracking is off for the whole site while courses have completion set up. **Fix:** turn on *Enable completion tracking* in the advanced features.

The site checks also appear in *Site administration > Reports > System status*.

## SCORM

### SCORM settings cannot be met (`scormstatusmismatch`) · Critical · fixable
The activity requires a status the package never reports (for example "passed" from a package that reports "completed"), or a score from a package that never sends one. What the package reports comes from learners' attempts (high confidence), the authoring tool settings (medium) or the manifest (low). Also reports packages Moodle cannot launch: no SCO, or imsmanifest.xml inside a folder of the zip. If learners are already marked complete, the message says so: something else is completing them. **Fix:** apply the recommended status setting; republish broken packages.

### SCORM requires a grade but sends no score (`scormgradenoscore`) · Critical or Info · fixable
"Require grade" or "Require passing grade" on a package without a quiz. When a learner finishes, Moodle records a grade of 0 (opening alone records nothing). With a grade to pass the 0 is always a fail and nobody completes (Critical); without one, the rule only means "finished" and every learner shows 0 in the gradebook (Info). Known from the authoring tool settings (Storyline, Rise) or from learners' attempts that report a status but never a score. Activities graded by "learning objects" are skipped. **Fix:** remove the grade rules and require the status the package reports.

### SCORM completes on view (`scormrequireview`) · Warning · fixable
Viewing is the only completion condition, so opening the package completes it. **Fix:** require the status the package reports.

### SCORM package sends no status (`scormnostatus`) · Critical
At least five recent attempts never reported a completion or success status. A status-based rule can never be met. **Fix:** republish the package with LMS reporting turned on.

### Multi-SCO package completes too early (`scormmultisco`) · Warning · fixable
A package with several SCOs completes when any one of them reports a status. **Fix:** require all SCOs to return a status.

### SCORM score and pass grade differ (`scormpassgrade`) · Warning · fixable
The minimum score required for completion is different from the grade to pass, so learners can complete while failing, or pass without completing. **Fix:** align the grade to pass with the score.

### SCORM quiz and completion (`scormquiz`) · Critical, Warning or Info · fixable
Moodle compares "Require minimum score" with the raw score the package reports, and every completion rule must be met. The pass mark comes from the authoring tool (for example a Storyline quiz) or the manifest mastery score; learners' attempts show whether scores are out of 100. Reported: the minimum score can never be reached because scores are out of fewer points (Critical); the minimum score differs from the quiz pass mark, so learners see "passed" without completing, or complete while failing (Warning); the package completes without its quiz being passed and no minimum score is set (Info). Nothing is reported when "passed" is required from a package that only reports "passed" after the quiz. **Fix:** set the minimum score to the pass mark (or the same share of the maximum points).

### Heavy SCORM package (`scormheavy`) · Info
The package is large for its first screen, slides or videos (rough estimate over the threshold, default 10 Mbps) or has more than 1,000 files. This is a size warning, not a measured speed: the estimate divides the first screen by 5 s, the largest slide asset by 2 s and adds the video bitrate (×1.5), with uncompressed sizes and no latency or caching. Moodle cannot know a learner's connection. **Fix:** compress media in the authoring tool.

### SCORM display size (`scormstage`) · Info
A fixed-size package (for example 960×540) does not fit the frame or pop-up the activity gives it, or the side table of contents takes width from it. **Fix:** adjust the display settings, or publish the package responsive.

### Language builds share saved progress (`scormmultibuild`) · Critical, or Info when the builds match
The package holds several complete course builds (usually one per language) behind one SCO, so all languages share one saved-progress record. If the builds differ (player version, slides, variables, objects on a slide), learners who switch language get endless loading or a blank screen. **Fix:** republish every language from one source project (translation export and import) with the same player version.

### Package replaced after learners started (`scormreplaced`) · Warning · fixable
The package was replaced while attempts still hold saved progress from the earlier version. The saved position can point to slides or lessons that no longer exist. **Fix:** reset the affected attempts, or set "Force new attempt" so completed learners start clean (the plugin's fix); avoid replacing packages after learners started.

### Saved progress too large for SCORM 1.2 (`scormsuspenddata`) · Warning or Info
SCORM 1.2 allows 4,096 characters of saved progress. Attempts over or near it are reported, as are packages that turn off their own limit on sites with SCORM standards mode on (Moodle then refuses longer saves). **Fix:** republish as SCORM 2004 (64,000 characters).

## Link check (extra, on request)

Not part of the completion audit and not counted in the health tiles. A teacher presses *Check links* on the course report; the check runs in the background for that course only.

New courses can also be checked automatically (setting *Check new courses automatically*, off by default): a scheduled task every 10 minutes checks courses created in the last 30 days (*New course period*) whose content changed since their last check and has not changed for 10 minutes, at most 20 courses per run. Courses older than that are only checked on request.

- **Where links are read:** course and section summaries, activity descriptions, page content, book chapters, lesson pages, glossary entries, question texts (latest version), URL activities, and links inside PDF, Word, PowerPoint and Excel files stored in the course (up to 20 MB each). Forum posts and other learner writing are not read.
- **Broken:** page not found (404, 410), a domain that does not exist, a link to a deleted activity or course, a missing file (embedded or via pluginfile.php), or a link to a draft file (only works for its author). **Fix:** edit the text with the *Edit* link.
- **Could not verify:** the site refused the check (401, 403, 429), timed out, had a certificate problem or a server error. Open the link yourself.
- **Not checked:** blocked by the site's HTTP security settings, or the limit per check was reached.

<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Admin settings for Completion Doctor.
 *
 * @package    local_completiondoctor
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$ADMIN->add('reports', new admin_externalpage(
    'local_completiondoctor_dashboard',
    new lang_string('dashboard:title', 'local_completiondoctor'),
    new moodle_url('/local/completiondoctor/index.php'),
    'local/completiondoctor:viewsitereport'
));

if ($hassiteconfig) {
    $component = 'local_completiondoctor';
    $ADMIN->add('localplugins', new admin_category(
        'local_completiondoctor_category',
        new lang_string('pluginname', $component)
    ));

    // General settings.
    $settings = new admin_settingpage('local_completiondoctor', new lang_string('settings:general', $component));
    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_heading(
            "{$component}/scanheading",
            new lang_string('settings:scanning', $component),
            ''
        ));
        $settings->add(new admin_setting_configcheckbox(
            "{$component}/autoreaudit",
            new lang_string('settings:autoreaudit', $component),
            new lang_string('settings:autoreaudit_desc', $component),
            0
        ));
        $settings->add(new admin_setting_configtext(
            "{$component}/scanbatchsize",
            new lang_string('settings:scanbatchsize', $component),
            new lang_string('settings:scanbatchsize_desc', $component),
            20,
            PARAM_INT
        ));

        $settings->add(new admin_setting_heading(
            "{$component}/loadheading",
            new lang_string('settings:load', $component),
            new lang_string('settings:load_desc', $component)
        ));
        $settings->add(new admin_setting_configcheckbox(
            "{$component}/paused",
            new lang_string('settings:paused', $component),
            new lang_string('settings:paused_desc', $component),
            0
        ));
        $settings->add(new admin_setting_configtext(
            "{$component}/scanpace",
            new lang_string('settings:scanpace', $component),
            new lang_string('settings:scanpace_desc', $component),
            30,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configtext(
            "{$component}/inlineanalysismb",
            new lang_string('settings:inlineanalysismb', $component),
            new lang_string('settings:inlineanalysismb_desc', $component),
            10,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configtext(
            "{$component}/packagesperrun",
            new lang_string('settings:packagesperrun', $component),
            new lang_string('settings:packagesperrun_desc', $component),
            10,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configtext(
            "{$component}/packagehours",
            new lang_string('settings:packagehours', $component),
            new lang_string('settings:packagehours_desc', $component),
            '',
            '/^(\s*\d{1,2}\s*-\s*\d{1,2}\s*)?$/'
        ));

        $settings->add(new admin_setting_heading(
            "{$component}/siteheading",
            new lang_string('settings:sitechecks', $component),
            ''
        ));
        $settings->add(new admin_setting_configtext(
            "{$component}/completiontaskmaxage",
            new lang_string('settings:completiontaskmaxage', $component),
            new lang_string('settings:completiontaskmaxage_desc', $component),
            60,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configtext(
            "{$component}/cronmaxage",
            new lang_string('settings:cronmaxage', $component),
            new lang_string('settings:cronmaxage_desc', $component),
            15,
            PARAM_INT
        ));

        $settings->add(new admin_setting_heading(
            "{$component}/scormheading",
            new lang_string('settings:scorm', $component),
            ''
        ));
        $settings->add(new admin_setting_configtext(
            "{$component}/maxpackagesize",
            new lang_string('settings:maxpackagesize', $component),
            new lang_string('settings:maxpackagesize_desc', $component),
            2048,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configtext(
            "{$component}/evidencesample",
            new lang_string('settings:evidencesample', $component),
            new lang_string('settings:evidencesample_desc', $component),
            200,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configcheckbox(
            "{$component}/popupfulltab",
            new lang_string('settings:popupfulltab', $component),
            new lang_string('settings:popupfulltab_desc', $component),
            0
        ));
        $settings->add(new admin_setting_configtext(
            "{$component}/targetstartup",
            new lang_string('settings:targetstartup', $component),
            new lang_string('settings:targetstartup_desc', $component),
            5,
            PARAM_FLOAT
        ));
        $settings->add(new admin_setting_configtext(
            "{$component}/targetslide",
            new lang_string('settings:targetslide', $component),
            new lang_string('settings:targetslide_desc', $component),
            2,
            PARAM_FLOAT
        ));
        $settings->add(new admin_setting_configtext(
            "{$component}/videoheadroom",
            new lang_string('settings:videoheadroom', $component),
            new lang_string('settings:videoheadroom_desc', $component),
            1.5,
            PARAM_FLOAT
        ));
        $settings->add(new admin_setting_configtext(
            "{$component}/heavythreshold",
            new lang_string('settings:heavythreshold', $component),
            new lang_string('settings:heavythreshold_desc', $component),
            10,
            PARAM_FLOAT
        ));

        $settings->add(new admin_setting_heading(
            "{$component}/linksheading",
            new lang_string('settings:links', $component),
            new lang_string('settings:links_desc', $component)
        ));
        $settings->add(new admin_setting_configcheckbox(
            "{$component}/linkcheck",
            new lang_string('settings:linkcheck', $component),
            new lang_string('settings:linkcheck_desc', $component),
            1
        ));
        $settings->add(new admin_setting_configcheckbox(
            "{$component}/linkautonew",
            new lang_string('settings:linkautonew', $component),
            new lang_string('settings:linkautonew_desc', $component),
            0
        ));
        $settings->add(new admin_setting_configtext(
            "{$component}/linkautodays",
            new lang_string('settings:linkautodays', $component),
            new lang_string('settings:linkautodays_desc', $component),
            30,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configtext(
            "{$component}/linkmaxurls",
            new lang_string('settings:linkmaxurls', $component),
            new lang_string('settings:linkmaxurls_desc', $component),
            500,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configtext(
            "{$component}/linktimeout",
            new lang_string('settings:linktimeout', $component),
            new lang_string('settings:linktimeout_desc', $component),
            8,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configtext(
            "{$component}/linkcachehours",
            new lang_string('settings:linkcachehours', $component),
            new lang_string('settings:linkcachehours_desc', $component),
            24,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configtext(
            "{$component}/linkmaxfilemb",
            new lang_string('settings:linkmaxfilemb', $component),
            new lang_string('settings:linkmaxfilemb_desc', $component),
            20,
            PARAM_INT
        ));

        $settings->add(new admin_setting_heading(
            "{$component}/notifyheading",
            new lang_string('settings:display', $component),
            ''
        ));
        $settings->add(new admin_setting_configcheckbox(
            "{$component}/showbanner",
            new lang_string('settings:showbanner', $component),
            new lang_string('settings:showbanner_desc', $component),
            1
        ));
        $settings->add(new admin_setting_configcheckbox(
            "{$component}/notifyteachers",
            new lang_string('settings:notifyteachers', $component),
            new lang_string('settings:notifyteachers_desc', $component),
            0
        ));
    }
    $ADMIN->add('local_completiondoctor_category', $settings);

    // Per-check settings.
    $checkspage = new admin_settingpage('local_completiondoctor_checks', new lang_string('settings:checks', $component));
    if ($ADMIN->fulltree) {
        $checks = \local_completiondoctor\check\check_registry::get_checks();
        if (!$checks) {
            $checkspage->add(new admin_setting_heading(
                "{$component}/nochecks",
                '',
                new lang_string('settings:nochecks', $component)
            ));
        }
        $severities = [
            0 => new lang_string('settings:severitydefault', $component),
            \local_completiondoctor\check\base_check::SEVERITY_INFO => new lang_string('severity:info', $component),
            \local_completiondoctor\check\base_check::SEVERITY_WARNING => new lang_string('severity:warning', $component),
            \local_completiondoctor\check\base_check::SEVERITY_CRITICAL => new lang_string('severity:critical', $component),
        ];
        foreach ($checks as $id => $check) {
            $checkspage->add(new admin_setting_heading(
                "{$component}/checkheading_{$id}",
                $check->get_name(),
                $check->get_description()
            ));
            // Changes apply to the stored findings at once, not only after the next audit of each course.
            $enabled = new admin_setting_configcheckbox(
                "{$component}/enabled_{$id}",
                new lang_string('settings:checkenabled', $component),
                '',
                1
            );
            $enabled->set_updatedcallback('local_completiondoctor\\local\\finding_store::setting_changed');
            $checkspage->add($enabled);
            $severity = new admin_setting_configselect(
                "{$component}/severity_{$id}",
                new lang_string('settings:checkseverity', $component),
                new lang_string('settings:checkseverity_desc', $component, $severities[$check->get_default_severity()]->out()),
                0,
                $severities
            );
            $severity->set_updatedcallback('local_completiondoctor\\local\\finding_store::setting_changed');
            $checkspage->add($severity);
        }
    }
    $ADMIN->add('local_completiondoctor_category', $checkspage);
}

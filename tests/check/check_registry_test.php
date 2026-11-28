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

namespace local_chronifycompliance\check;

use local_chronifycompliance\tests\check_testcase_trait;
use local_chronifycompliance\tests\fake_check;
use local_chronifycompliance\tests\fake_site_check;

#[\PHPUnit\Framework\Attributes\CoversClass(check_registry::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_chronifycompliance\hook\register_checks::class)]
/**
 * Tests for the check registry and the register_checks hook.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\check\check_registry
 * @covers \local_chronifycompliance\hook\register_checks
 */
final class check_registry_test extends \advanced_testcase {
    use check_testcase_trait;

    /**
     * Reset the registry.
     */
    protected function tearDown(): void {
        $this->reset_checks();
        parent::tearDown();
    }

    /**
     * Checks registered through the hook are available, filtered by scope and enabled state.
     */
    public function test_hook_registration_and_filters(): void {
        $this->resetAfterTest();
        $this->register_checks([new fake_check(), new fake_site_check()]);

        $this->assertInstanceOf(fake_check::class, check_registry::get_check('fakecheck'));
        $this->assertArrayHasKey('fakecheck', check_registry::get_checks([base_check::SCOPE_COURSE]));
        $this->assertArrayNotHasKey('fakesitecheck', check_registry::get_checks([base_check::SCOPE_COURSE]));
        $this->assertArrayHasKey('fakesitecheck', check_registry::get_checks([base_check::SCOPE_SITE]));

        set_config('enabled_fakecheck', 0, 'local_chronifycompliance');
        $this->assertArrayNotHasKey('fakecheck', check_registry::get_checks(null, true));
        $this->assertArrayHasKey('fakecheck', check_registry::get_checks(null, false));
    }

    /**
     * Duplicate ids are skipped with a debugging message.
     */
    public function test_duplicate_ids_skipped(): void {
        $this->register_checks([new fake_check(), new fake_check()]);
        $checks = check_registry::get_checks();
        $this->assertDebuggingCalled();
        $this->assertCount(1, array_filter(array_keys($checks), fn($id) => $id === 'fakecheck'));
    }

    /**
     * Invalid ids are skipped with a debugging message.
     */
    public function test_invalid_id_skipped(): void {
        $bad = new class extends fake_check {
            /**
             * Invalid id.
             *
             * @return string
             */
            public function get_id(): string {
                return 'Bad-Id';
            }
        };
        $this->register_checks([$bad]);
        $this->assertNull(check_registry::get_check('Bad-Id'));
        $this->assertDebuggingCalled();
    }

    /**
     * The admin can override the severity.
     */
    public function test_severity_override(): void {
        $this->resetAfterTest();
        $check = new fake_check();
        $this->assertSame(base_check::SEVERITY_WARNING, $check->get_severity());
        set_config('severity_fakecheck', base_check::SEVERITY_CRITICAL, 'local_chronifycompliance');
        $this->assertSame(base_check::SEVERITY_CRITICAL, $check->get_severity());
        set_config('severity_fakecheck', 99, 'local_chronifycompliance');
        $this->assertSame(base_check::SEVERITY_WARNING, $check->get_severity());
    }

    /**
     * Every built-in check belongs to the expected pack. A new check must be added here on purpose.
     */
    public function test_builtin_check_packs(): void {
        $expected = [
            base_check::PACK_COMPLETION => [
                'activityrules', 'addedaftercriteria', 'circular', 'datecriteria', 'enrolment', 'manualrole',
                'nocriteria', 'nopassgrade', 'overlapgrade', 'passgradeunreachable', 'prereqbroken', 'requireddeleted',
                'selfcompletionblock', 'trackednotrequired', 'trackingoff', 'unreachable', 'weakcondition',
            ],
            base_check::PACK_SCORM => [
                'scormgradenoscore', 'scormheavy', 'scormmultibuild', 'scormmultisco', 'scormnostatus', 'scormpassgrade',
                'scormquiz', 'scormreplaced', 'scormrequireview', 'scormstage', 'scormstatusmismatch', 'scormsuspenddata',
            ],
            base_check::PACK_SITE => ['completiondisabled', 'completiontasks', 'coreperformance', 'cronstale'],
            base_check::PACK_SECURITY => ['adminmfa', 'coresecurity', 'debugdisplay', 'exposedfiles', 'guestaccess',
                'inactiveadmins', 'passwordstrength', 'riskyroles', 'selfregistration', 'sessioncookies', 'webservices',
                'wstokens'],
        ];
        $actual = array_map(fn(array $checks): array => array_keys($checks), check_registry::get_checks_by_pack());
        $this->assertSame(base_check::get_packs(), array_keys($actual), 'Every pack is listed, in display order.');
        foreach ($expected as $pack => $ids) {
            $this->assertSame($ids, $actual[$pack], "Checks of pack {$pack}");
        }
        $this->assertCount(45, check_registry::get_checks());
    }

    /**
     * Checks can be filtered by pack, and every pack has a name.
     */
    public function test_filter_by_pack(): void {
        $scorm = check_registry::get_checks(null, false, [base_check::PACK_SCORM]);
        $this->assertCount(12, $scorm);
        foreach ($scorm as $check) {
            $this->assertSame(base_check::PACK_SCORM, $check->get_pack());
        }
        $both = check_registry::get_checks(null, false, [base_check::PACK_SITE, base_check::PACK_COMPLETION]);
        $this->assertCount(21, $both);
        $this->assertCount(12, check_registry::get_checks(null, false, [base_check::PACK_SECURITY]));
        foreach (base_check::get_packs() as $pack) {
            $this->assertNotEmpty(base_check::get_pack_name($pack));
        }
    }

    /**
     * Hook checks without their own pack go to Site when they run for the site, to Completion otherwise.
     * Checks with an unknown pack are skipped.
     */
    public function test_hook_check_packs(): void {
        $badpack = new class extends fake_check {
            /**
             * Id of this check.
             *
             * @return string
             */
            public function get_id(): string {
                return 'fakebadpack';
            }

            /**
             * A pack that does not exist.
             *
             * @return string
             */
            public function get_pack(): string {
                return 'nosuchpack';
            }
        };
        $this->register_checks([new fake_check(), new fake_site_check(), $badpack]);
        $this->assertSame(base_check::PACK_COMPLETION, check_registry::get_check('fakecheck')->get_pack());
        $this->assertSame(base_check::PACK_SITE, check_registry::get_check('fakesitecheck')->get_pack());
        $this->assertNull(check_registry::get_check('fakebadpack'));
        $this->assertDebuggingCalled();
        $bypack = check_registry::get_checks_by_pack();
        $this->assertArrayHasKey('fakecheck', $bypack[base_check::PACK_COMPLETION]);
        $this->assertArrayHasKey('fakesitecheck', $bypack[base_check::PACK_SITE]);
    }
}

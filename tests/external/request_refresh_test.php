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

namespace local_chronifycompliance\external;

use local_chronifycompliance\data\refresher;

#[\PHPUnit\Framework\Attributes\CoversClass(request_refresh::class)]
/**
 * Tests of Refresh now.
 *
 * @package    local_chronifycompliance
 * @category   test
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_chronifycompliance\external\request_refresh
 */
final class request_refresh_test extends \core_external\tests\externallib_testcase {
    /**
     * Queues one ad hoc refresh, needs the capability and a known dataset.
     */
    public function test_request_refresh(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = request_refresh::execute('activity');
        $result = \core_external\external_api::clean_returnvalue(request_refresh::execute_returns(), $result);
        $this->assertTrue($result['queued']);
        $this->assertTrue(refresher::is_requested('activity'));
        // Asking twice does not queue a second task.
        request_refresh::execute('activity');
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(\local_chronifycompliance\task\refresh_dataset::class));

        try {
            request_refresh::execute('nosuchdataset');
            $this->fail('Unknown dataset accepted');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('nosuchdataset', $e->getMessage());
        }

        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        request_refresh::execute('activity');
    }
}

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
 * Development tool: load one dataset from the start and report how long it takes.
 *
 * Runs the refresh in runs of the configured minutes per run, without the pause between batches,
 * and prints rows per second and how many nightly runs the first load needs.
 * Not part of the plugin release.
 *
 * Usage, from the Moodle directory:
 *   php local/chronifycompliance/dev/measure-refresh.php --dataset=activity
 *
 * @package    local_chronifycompliance
 * @copyright  2026 Sebale {@link https://sebale.net}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(getcwd() . '/config.php');
require_once($CFG->libdir . '/clilib.php');

use local_chronifycompliance\data\dataset_registry;
use local_chronifycompliance\data\refresher;

[$options] = cli_get_params(['dataset' => 'activity', 'batch' => 0]);
$dataset = dataset_registry::get_dataset($options['dataset']);
if (!$dataset) {
    cli_error('Unknown dataset');
}
refresher::reset($dataset);
$budget = refresher::get_budget();
$batch = (int) $options['batch'] ?: refresher::get_batch_size();
$start = microtime(true);
$runs = 0;
$rows = 0;
do {
    $runs++;
    $outcome = refresher::refresh($dataset, ['deadline' => time() + $budget, 'pace' => 0, 'batchsize' => $batch]);
    $rows += $outcome->rows;
    $seconds = microtime(true) - $start;
    cli_writeln(sprintf('Run %d: %s, %d rows so far, %.0f s, %.0f rows/s', $runs, $outcome->result, $rows, $seconds, $rows / max(1, $seconds)));
} while ($outcome->result === refresher::RESULT_MORE);

$seconds = microtime(true) - $start;
$pace = refresher::get_pace();
$batches = (int) refresher::get_state($dataset->get_id())->batches;
$withpace = $seconds + $batches * $pace;
cli_writeln(sprintf(
    'First load: %d rows in %.0f s (%.1f min); with %d s between %d batches: %.1f min; %d runs of %d min.',
    $rows,
    $seconds,
    $seconds / 60,
    $pace,
    $batches,
    $withpace / 60,
    (int) ceil($withpace / $budget),
    $budget / 60
));

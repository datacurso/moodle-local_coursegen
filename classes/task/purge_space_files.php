<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_coursegen\task;

use local_coursegen\local\reference\reference_file_storage;

/**
 * Deletes the files a teacher brought that no generation used in time.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class purge_reference_files extends \core\task\scheduled_task {
    /** @var int How long a file may wait for its generation, in seconds. */
    public const KEEP_FOR = 7 * DAYSECS;

    #[\Override]
    public function get_name() {
        return get_string('task_purge_reference_files', 'local_coursegen');
    }

    #[\Override]
    public function execute() {
        $before = time() - self::KEEP_FOR;
        $deleted = reference_file_storage::purge_older_than($before);
        mtrace("Deleted $deleted stale reference files.");
    }
}

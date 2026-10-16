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

namespace local_coursegen\local\service;

defined('MOODLE_INTERNAL') || die();

/**
 * Testable course import service that fails while restoring the staging course onto the copy.
 *
 * Exercises the rollback path without depending on a real restore failure.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_course_import_service extends course_import_service {
    /**
     * Throw instead of restoring when the target is the staging import onto the copy.
     *
     * @param string $backupid Backup identifier.
     * @param int $courseid Destination course id.
     * @param int $userid Acting user id.
     * @param int $target One of the backup::TARGET_* constants.
     * @param array $settings Restore settings to apply.
     * @return void
     */
    protected static function restore_course(string $backupid, int $courseid, int $userid, int $target, array $settings): void {
        if ($target !== \backup::TARGET_NEW_COURSE) {
            throw new \RuntimeException('Injected restore failure');
        }
        parent::restore_course($backupid, $courseid, $userid, $target, $settings);
    }
}

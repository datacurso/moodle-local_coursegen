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

/**
 * Testable import whose backup of the target fails after the backup, with user data, was stored.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_failing_backup_import_service extends course_import_service {
    /**
     * Run the backup, then fail as if a later step had thrown.
     *
     * @param \backup_controller $bc Backup controller.
     * @return void
     */
    protected static function execute_backup_plan(\backup_controller $bc): void {
        parent::execute_backup_plan($bc);
        throw new \RuntimeException('Backup failed after storing the file');
    }
}

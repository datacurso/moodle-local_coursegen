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

require_once(__DIR__ . '/testable_course_import_service.php');

/**
 * Testable import whose process dies while the import fails, before the rollback runs.
 *
 * The staging import fails (as in testable_course_import_service) and the process "dies" right
 * after: the import is left recorded as running, the original renamed and hidden, the copy in place.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_interrupted_import_service extends testable_course_import_service {
    /**
     * Die instead of rolling back.
     *
     * @return void
     */
    protected static function interrupt_point(): void {
        throw new \RuntimeException('Process died');
    }
}

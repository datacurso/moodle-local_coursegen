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

namespace local_coursegen;

/**
 * Testable create_course_service that lets tests force the final consistency
 * verification result through the protected counter seam (late static binding).
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_create_course_service extends \local_coursegen\local\service\create_course_service {
    /** @var int|null Forced result for the unresolved modinfo reference counter. */
    public static $forcedunresolvedcount = null;

    /**
     * Return the forced counter when set, the real count otherwise.
     *
     * @param int $courseid Course ID.
     * @return int Number of unresolved references.
     */
    protected static function count_unresolved_modinfo_sequence_references(int $courseid): int {
        if (static::$forcedunresolvedcount !== null) {
            return static::$forcedunresolvedcount;
        }

        return parent::count_unresolved_modinfo_sequence_references($courseid);
    }
}

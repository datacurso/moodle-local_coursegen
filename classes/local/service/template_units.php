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


/**
 * The number of units of a template course, and the number the professor asks for.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local\service;

/**
 * Units exist only in a course whose format has subsections (flexsections).
 */
class template_units {
    /**
     * How many units the course has.
     *
     * @param \course_modinfo $modinfo
     * @return int|null Null when the format has no units or the course has none.
     */
    public static function count(\course_modinfo $modinfo): ?int {
        $tree = template_section_tree::from_modinfo($modinfo);
        if ($tree === null) {
            return null;
        }
        $units = count($tree->units());
        return $units > 0 ? $units : null;
    }

    /**
     * The number of units to resize the template to, when the professor asked for another one.
     *
     * Whatever comes from the client is kept between 1 and the maximum.
     *
     * @param \course_modinfo $modinfo
     * @param int|null $asked What the professor asked for, null for nothing.
     * @return int|null Null when nothing has to change: no number, no units, or the template's own count.
     */
    public static function requested(\course_modinfo $modinfo, ?int $asked): ?int {
        $current = self::count($modinfo);
        if ($asked === null || $current === null) {
            return null;
        }
        $asked = min(template_unit_resizer::MAX_UNITS, max(1, $asked));
        return $asked === $current ? null : $asked;
    }
}

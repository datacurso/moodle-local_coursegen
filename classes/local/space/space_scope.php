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

namespace local_coursegen\local\space;

/**
 * The spaces of the course being built.
 *
 * The activities of a course reach the file pass one by one, through several classes. All of them need to know which
 * files of the template stand for a space and what the teacher brought for it, so the creation of the course runs
 * inside this scope instead of every class being handed the selection.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class space_scope {
    /** @var space_selection|null The spaces of the course being built, null when none is. */
    private static ?space_selection $current = null;

    /**
     * Put a selection in scope.
     *
     * @param space_selection $selection
     * @throws \coding_exception A selection is already in scope.
     */
    public static function enter(space_selection $selection): void {
        if (self::$current !== null) {
            throw new \coding_exception('The spaces of another course are already in scope.');
        }
        self::$current = $selection;
    }

    /**
     * Take the selection out of scope.
     */
    public static function leave(): void {
        self::$current = null;
    }

    /**
     * The selection in scope.
     *
     * @return space_selection|null Null when no course is being built with spaces.
     */
    public static function current(): ?space_selection {
        return self::$current;
    }
}

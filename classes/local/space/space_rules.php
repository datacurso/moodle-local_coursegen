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

use local_coursegen\local\models\template_activity;

/**
 * Which activities a template can mark as a space for the teacher.
 *
 * For now only a file resource can be one: the teacher brings a file and the new course gets it in the same place.
 * A space saved on any other type is read as exclude everywhere, so nothing of it reaches the teacher or the AI service.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class space_rules {
    /** The only activity type that can be a space. */
    public const MODNAME = 'resource';

    /**
     * Whether an activity type can be a space.
     *
     * @param string $modname
     * @return bool
     */
    public static function allows(string $modname): bool {
        return $modname === self::MODNAME;
    }

    /**
     * The action an activity really has, given the one saved for it.
     *
     * @param string $action The saved action.
     * @param string $modname The activity's type.
     * @return string The action, or exclude for a space on a type that cannot be one.
     */
    public static function effective_action(string $action, string $modname): string {
        if ($action === template_activity::ACTION_SPACE && !self::allows($modname)) {
            return template_activity::ACTION_EXCLUDE;
        }
        return $action;
    }
}

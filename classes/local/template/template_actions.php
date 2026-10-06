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

namespace local_coursegen\local\template;

/**
 * The two things an admin can ask for an activity of a template.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_actions {
    /** The AI never touches the activity. */
    public const KEEP = 'keep';

    /** The AI modifies the activity following the optional instruction. */
    public const AI = 'ai';

    /**
     * All the actions an activity can have.
     *
     * @return string[]
     */
    public static function all(): array {
        return [self::KEEP, self::AI];
    }

    /**
     * Whether a value is one of the actions.
     *
     * @param string $action Value to check, for example "ai".
     * @return bool
     */
    public static function is_valid(string $action): bool {
        $actions = self::all();

        return in_array($action, $actions, true);
    }
}

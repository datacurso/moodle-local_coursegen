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

namespace local_coursegen\local;

/**
 * The activity types the modal to create an activity with the AI offers.
 *
 * Only what the service can write content for and the site has installed and enabled, so the teacher knows what is
 * created and the AI does not decide it. The chooser draws the icon and the name itself from Moodle's own list.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_type_catalog {
    /**
     * The types for the modal, by name.
     *
     * @return array[] Each with modname and name.
     */
    public static function for_modal(): array {
        $types = [];
        foreach (ai_activity_types::installed() as $modname) {
            $types[] = [
                'modname' => $modname,
                'name' => get_string('modulename', 'mod_' . $modname),
            ];
        }
        usort($types, static fn($a, $b) => strcoll($a['name'], $b['name']));
        return $types;
    }

    /**
     * Whether a module name is one the modal offers on this site.
     *
     * @param string $modname
     * @return bool
     */
    public static function is_offered(string $modname): bool {
        return in_array($modname, ai_activity_types::installed(), true);
    }
}

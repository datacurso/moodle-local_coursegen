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
 * The activity types a template can work with on this site.
 *
 * A type is supported when the AI content service has a content contract
 * for it (template_content_generator::AI_SUPPORTED_TYPES) and the module is
 * installed and enabled here. Every template allows all of them: the admin no
 * longer narrows the list per template.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class supported_activity_types {
    /**
     * Module names that are both AI-supported and available on this site.
     *
     * @return string[] Sorted module names, e.g. ['book', 'forum', ...].
     */
    public static function installed(): array {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $typenames = get_module_types_names();
        $available = array_keys($typenames);
        $supported = array_intersect($available, template_content_generator::AI_SUPPORTED_TYPES);
        sort($supported);
        $sorted = array_values($supported);
        return $sorted;
    }

    /**
     * Whether a module name is one of the installed, AI-supported types.
     *
     * @param string $modname
     * @return bool
     */
    public static function is_supported(string $modname): bool {
        $installed = self::installed();
        return in_array($modname, $installed, true);
    }
}

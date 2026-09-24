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

use cm_info;
use local_coursegen\mod_export\base_export;

/**
 * One real activity's "parameters" for the course-template payload.
 *
 * A mold is the case that actually matters here: the AI reproduces its
 * structure page by page, so a lesson's real pages (title + content_html,
 * markers and all) must travel intact under mod_settings.pages - the shape
 * course_ai's _mold_lesson_pages() reads. Every other module type only needs
 * enough to identify and place it.
 *
 * Each type's own export lives in \local_coursegen\mod_export, resolved by
 * name from the module, exactly as create_mod_service resolves mod_settings
 * and mod_parameters. A type with no class of its own ships the minimal pair.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_activity_export {
    /**
     * Build one activity's parameters.
     *
     * @param cm_info $cm
     * @return array
     */
    public static function parameters_for(cm_info $cm): array {
        $classpath = self::get_export_class($cm->modname);
        if (!self::is_valid_export_class($classpath)) {
            return [
                'name' => $cm->name,
                'section' => (int) $cm->sectionnum,
            ];
        }

        /** @var base_export $export */
        $export = new $classpath($cm);
        return $export->parameters();
    }

    /**
     * Get the fully qualified class name of the module-specific export class.
     *
     * @param string $modname Module plugin name.
     * @return string Fully-qualified class name.
     */
    private static function get_export_class($modname) {
        $exportclass = '\\local_coursegen\\mod_export\\' . $modname . '_export';
        return $exportclass;
    }

    /**
     * Check that an export class exists and extends base_export.
     *
     * @param string $class Class name to validate.
     * @return bool True if usable.
     */
    private static function is_valid_export_class($class) {
        $classexists = class_exists($class);
        $issubclass = is_subclass_of($class, base_export::class);
        return $classexists && $issubclass;
    }
}

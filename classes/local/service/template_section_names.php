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
 * The names the AI gives the sections of a course made from a template.
 *
 * The result of a generation names each section (`name`) and says what the template called it (`original_name`).
 * The new name goes to the section of the new course that has the same `section` number, and only to the name:
 * what a section is (its number, the section it comes from, the unit it hangs under) is the structure that was
 * sent, and is never read from here. A name that is empty, or the one the section already has, changes nothing.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_section_names {
    /** @var int The longest name a section can have. */
    private const MAXLENGTH = 255;

    /**
     * A name as it is stored: without the spaces around it, and no longer than a section name can be.
     *
     * @param string $name
     * @return string Empty when the name says nothing.
     */
    public static function clean_name(string $name): string {
        return (string) \core_text::substr(trim($name), 0, self::MAXLENGTH);
    }

    /**
     * Give the sections of the new course the names of the result.
     *
     * @param int $courseid The new course.
     * @param array $sectionsinfo The result's sections_info.
     */
    public static function apply(int $courseid, array $sectionsinfo): void {
        global $DB;

        $sections = $DB->get_records('course_sections', ['course' => $courseid], '', 'section, id, name');
        $changed = false;
        foreach ($sectionsinfo as $info) {
            $section = $sections[(int) ($info['section'] ?? -1)] ?? null;
            $name = self::clean_name((string) ($info['name'] ?? ''));
            if ($section === null || $name === '' || $name === (string) $section->name) {
                continue;
            }
            $DB->set_field('course_sections', 'name', $name, ['id' => $section->id]);
            $changed = true;
        }
        if ($changed) {
            rebuild_course_cache($courseid, true);
        }
    }
}

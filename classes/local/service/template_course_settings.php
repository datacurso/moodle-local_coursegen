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
 * The settings of a template's course that the new course takes as they are.
 *
 * A course made from a template is the template's course with other content
 * in it, so whatever the template does not mark is carried over exactly:
 * its language, its format and the way the format is set up, and the
 * course-wide settings the free flow does not own. The name, the short name,
 * the description and the category come from the teacher, and the course
 * stays visible as a free one does, so none of those travel here.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_course_settings {
    /** @var string[] Columns of the course record that are copied as they are. */
    private const COLUMNS = [
        'lang',
        'theme',
        'newsitems',
        'showgrades',
        'showreports',
        'showactivitydates',
        'showcompletionconditions',
        'maxbytes',
        'groupmode',
        'groupmodeforce',
    ];

    /**
     * The settings of a template's course, as they travel in the payload.
     *
     * @param \stdClass $course The template's course.
     * @return array Column => value.
     */
    public static function export(\stdClass $course): array {
        $settings = [];
        foreach (self::COLUMNS as $column) {
            $settings[$column] = $course->{$column};
        }
        return $settings;
    }

    /**
     * Put the template's format and settings on the data of the new course.
     *
     * @param \stdClass $coursedata Data the new course is created from.
     * @param array $config The payload's course_configuration.
     */
    public static function apply_to_new_course(\stdClass $coursedata, array $config): void {
        $settings = $config['course_settings'] ?? [];
        foreach (self::COLUMNS as $column) {
            if (array_key_exists($column, $settings)) {
                $coursedata->{$column} = $settings[$column];
            }
        }
        $format = trim((string) ($config['format'] ?? ''));
        if ($format !== '') {
            $coursedata->format = $format;
        }
    }

    /**
     * Set up the format of the new course the way the template's is.
     *
     * Creating the course gives its format the defaults; the values the
     * template's course stores are written over them. They are read from the
     * template's course rather than from the payload, which carries them with
     * the site's defaults already resolved for the preview, so an option the
     * template leaves to the site stays left to the site.
     *
     * @param \stdClass $course The new course.
     * @param int $sourcecourseid The template's course.
     */
    public static function apply_format_options(\stdClass $course, int $sourcecourseid): void {
        $sourcecourse = get_course($sourcecourseid);
        $options = course_get_format($sourcecourse)->get_format_options();
        course_get_format($course)->update_course_format_options($options);
    }
}

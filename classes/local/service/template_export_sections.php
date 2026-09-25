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
 * A template's course, described the way its own format lays it out: every
 * section with its saved behavior merged in, the format's own settings with
 * their defaults resolved, and the picture each section is shown with, where
 * its format gives it one. What the course preview (course_from_payload.php)
 * draws is built from exactly this, and nothing read from the live course.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_export_sections {
    /**
     * Every section, with its saved behavior merged in.
     *
     * @param int $templateid
     * @param \stdClass $course
     * @param \course_modinfo $modinfo
     * @param array $behaviors
     * @return array
     */
    public static function sections_info(int $templateid, $course, $modinfo, array $behaviors): array {
        $format = course_get_format($course);
        $images = self::section_images($course);
        $contextid = \context_course::instance($course->id)->id;

        $sectioninfos = $modinfo->get_section_info_all();
        $sections = [];
        foreach ($sectioninfos as $section) {
            $sections[] = self::section_entry($templateid, $course, $format, $section, $images, $behaviors, $contextid);
        }
        return $sections;
    }

    /**
     * One section, in the shape the course preview reads.
     *
     * @param int $templateid
     * @param \stdClass $course
     * @param mixed $format The course's format, as course_get_format() returns it.
     * @param \section_info $section
     * @param array $images Section id => image address, as section_images() returns it.
     * @param array $behaviors Section id => template behavior.
     * @param int $contextid
     * @return array
     */
    private static function section_entry(
        int $templateid,
        $course,
        $format,
        $section,
        array $images,
        array $behaviors,
        int $contextid
    ): array {
        $sectionid = (int) $section->id;

        $uid = template_export_uids::stable_uid($templateid, 'section', $sectionid);
        $name = get_section_name($course, $section);

        $summary = $section->summary ?? '';
        $summary = (string) $summary;
        // A summary refers to its pictures by a placeholder that only
        // means something to the page that owns them. Whatever reads
        // this payload owns nothing, so they travel as addresses.
        $summary = file_rewrite_pluginfile_urls($summary, 'pluginfile.php', $contextid, 'course', 'section', $sectionid);

        $summaryformat = $section->summaryformat ?? FORMAT_HTML;
        $summaryformat = (int) $summaryformat;

        // What the format was told about this section in particular,
        // which is where a format keeps the look of it.
        $formatoptions = $format->get_format_options($section);

        $image = $images[$sectionid] ?? null;
        $behavior = $behaviors[$sectionid] ?? 'aimodify';

        return [
            'uid' => $uid,
            'section' => (int) $section->section,
            'name' => $name,
            'summary' => $summary,
            'summaryformat' => $summaryformat,
            'format_options' => $formatoptions,
            'image' => $image,
            'template_behavior' => ['behavior' => $behavior],
        ];
    }

    /**
     * How the course's format is set up, with its defaults already resolved.
     *
     * A format option left alone is stored empty and means "whatever the site
     * says", so a payload carrying it raw describes a course laid out at zero
     * width. A format that resolves its own settings is asked to.
     *
     * @param \stdClass $course
     * @return array
     */
    public static function format_settings($course): array {
        $format = course_get_format($course);
        if (method_exists($format, 'get_settings')) {
            return (array) $format->get_settings();
        }
        return $format->get_format_options();
    }

    /**
     * The picture each section is shown with, where its format gives it one.
     *
     * A format can put a picture on a section, and for the formats that do it
     * is most of what the course looks like. The picture is a file on this
     * site, so what travels is where it can be read from.
     *
     * @param \stdClass $course
     * @return array Section id => image address.
     */
    private static function section_images($course): array {
        global $DB, $CFG;

        if ($course->format !== 'grid' || !file_exists($CFG->dirroot . '/course/format/grid/classes/toolbox.php')) {
            return [];
        }

        $toolbox = \format_grid\toolbox::get_instance();
        $contextid = \context_course::instance($course->id)->id;
        $webp = (get_config('format_grid', 'defaultdisplayedimagefiletype') == 2);

        $gridimages = $DB->get_records('format_grid_image', ['courseid' => $course->id]);
        $images = [];
        foreach ($gridimages as $image) {
            if ((int) $image->displayedimagestate < 1) {
                continue;
            }
            $sectionid = (int) $image->sectionid;
            $uri = $toolbox->get_displayed_image_uri($image, $contextid, $sectionid, $webp);
            $address = (string) $uri;
            if ($uri instanceof \moodle_url) {
                $address = $uri->out(false);
            }
            $images[$sectionid] = $address;
        }
        return $images;
    }
}

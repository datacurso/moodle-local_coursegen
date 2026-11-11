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

namespace local_coursegen\local\placeholder;

/**
 * Reads the sections of a course with its activities and what each one holds in placeholders.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_structure_reader {
    /**
     * Read the course.
     *
     * @param \stdClass $course The course record.
     * @return array {structure, activities, problems, hidden}: the sections in the shape the plan builder reads, how
     *     many activities there are, the malformed markers found and how many markers sit in comments or scripts.
     */
    public static function read(\stdClass $course): array {
        $modinfo = get_fast_modinfo($course);
        $result = ['structure' => [], 'activities' => 0, 'problems' => [], 'hidden' => 0];
        $sectioninfos = $modinfo->get_section_info_all();
        foreach ($sectioninfos as $sectioninfo) {
            $section = self::read_section($modinfo, $sectioninfo);
            $result['structure'][] = $section['section'];
            $result['activities'] += count($section['section']['activities']);
            $result['problems'] = array_merge($result['problems'], $section['problems']);
            $result['hidden'] += $section['hidden'];
        }
        return $result;
    }

    /**
     * Read one section.
     *
     * @param \course_modinfo $modinfo The course information.
     * @param \section_info $sectioninfo The section.
     * @return array {section, problems, hidden}
     */
    private static function read_section(\course_modinfo $modinfo, \section_info $sectioninfo): array {
        $number = (int) $sectioninfo->section;
        $cmids = $modinfo->sections[$number] ?? [];
        $read = ['activities' => [], 'problems' => [], 'hidden' => 0];
        foreach ($cmids as $cmid) {
            $cm = $modinfo->get_cm($cmid);
            $read = self::add_activity($read, $cm);
        }
        return [
            'section' => [
                'sectionid' => (int) $sectioninfo->id,
                'sectionnum' => $number,
                'activities' => $read['activities'],
            ],
            'problems' => $read['problems'],
            'hidden' => $read['hidden'],
        ];
    }

    /**
     * Scan one activity and add it to what has been read; an activity being deleted is skipped.
     *
     * @param array $read The activities, problems and hidden markers so far.
     * @param \cm_info $cm The activity.
     * @return array What has been read, with this activity added.
     */
    private static function add_activity(array $read, \cm_info $cm): array {
        if ($cm->deletioninprogress) {
            return $read;
        }
        $scan = activity_scanner::scan($cm);
        $name = self::plain_name($cm);
        $typelabel = $cm->get_module_type_name();
        $placeholders = $scan->placeholders();
        $read['activities'][] = [
            'cmid' => (int) $cm->id,
            'modname' => (string) $cm->modname,
            'name' => $name,
            'typelabel' => (string) $typelabel,
            'placeholders' => $placeholders,
        ];
        foreach ($scan->problems as $problem) {
            $read['problems'][] = 'cm ' . $cm->id . ' (' . $cm->modname . ') ' . $problem;
        }
        $read['hidden'] += $scan->hidden;
        return $read;
    }

    /**
     * The name of an activity as plain text on one line.
     *
     * @param \cm_info $cm The activity.
     * @return string
     */
    private static function plain_name(\cm_info $cm): string {
        $formatted = $cm->get_formatted_name();
        $text = strip_tags($formatted);
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $single = preg_replace('/\s+/u', ' ', $decoded);
        return trim((string) $single);
    }
}

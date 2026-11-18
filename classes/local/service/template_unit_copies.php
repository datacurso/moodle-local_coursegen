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

use local_coursegen\local\models\course_session;

/**
 * The sections of a run whose template section was repeated once per unit of the syllabus.
 *
 * The AI service writes the copies after reading the syllabus, so the sections it answers are not the ones the
 * plugin sent: the template section is there once per unit and every section after it moved down. Each entry
 * says which section of the template it comes from (source_section) and a copy says it is one (cloned), which is
 * what a resized template already says. The answered sections take the place of the sent ones in the session,
 * so the build places every section, every kept activity and every copy where it belongs.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_unit_copies {
    /**
     * Whether the answered sections hold copies of a template section.
     *
     * @param array $sectionsinfo The result's sections_info.
     * @return bool
     */
    public static function has_copies(array $sectionsinfo): bool {
        foreach ($sectionsinfo as $entry) {
            if (!empty($entry['cloned'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Keep the answered sections as the session's own when they hold copies; leave the session alone otherwise.
     *
     * @param course_session $session
     * @param array $sectionsinfo The result's sections_info.
     * @return bool Whether the session took them.
     */
    public static function adopt(course_session $session, array $sectionsinfo): bool {
        if (!self::has_copies($sectionsinfo)) {
            return false;
        }
        $coursedata = json_decode((string) $session->get('coursedata'), true) ?: [];
        $coursedata['payload']['sections_info'] = array_values($sectionsinfo);
        $session->set('coursedata', json_encode($coursedata, JSON_UNESCAPED_UNICODE));
        $session->update();
        return true;
    }

    /**
     * Give the activities of every copy the order the template section has in the course.
     *
     * The first copy is the template section itself and is ordered from the template. A copied activity says which
     * activity it repeats (copy_of_uid), so each copy follows the order of the first one; an activity of a copy that
     * repeats nothing stays after the others, in the order it has.
     *
     * @param int $courseid The new course.
     * @param array $sectionsinfo The sections the session kept (see adopt()).
     * @param array $payloadactivities Every activity entry of the result.
     * @param array $generatedcms Activity key (generated_activity_key) => created cmid.
     */
    public static function order_copies(int $courseid, array $sectionsinfo, array $payloadactivities, array $generatedcms): void {
        global $DB;
        if ($courseid <= 0 || !self::has_copies($sectionsinfo)) {
            return;
        }
        $original = [];
        foreach ($payloadactivities as $entry) {
            $source = (string) ($entry['copy_of_uid'] ?? '');
            $copy = $generatedcms[generated_activity_key::of($entry)] ?? null;
            if ($source !== '' && $copy !== null && isset($generatedcms[$source])) {
                $original[(int) $copy] = (int) $generatedcms[$source];
            }
        }
        $map = template_section_map::from_sections_info($sectionsinfo);
        $sequences = self::sequences($courseid);
        foreach ($sectionsinfo as $entry) {
            if (empty($entry['cloned'])) {
                continue;
            }
            $number = (int) $entry['section'];
            $first = $map->target_of_source((int) ($entry['source_section'] ?? $number));
            if ($first === null || !isset($sequences[$number], $sequences[$first])) {
                continue;
            }
            $ordered = self::ordered_like($sequences[$number]['cmids'], array_flip($sequences[$first]['cmids']), $original);
            if ($ordered !== $sequences[$number]['cmids']) {
                $DB->set_field('course_sections', 'sequence', implode(',', $ordered), ['id' => $sequences[$number]['id']]);
            }
        }
        rebuild_course_cache($courseid, true);
    }

    /**
     * Section number => {id, cmids} of a course.
     *
     * @param int $courseid
     * @return array
     */
    private static function sequences(int $courseid): array {
        global $DB;
        $sequences = [];
        foreach ($DB->get_records('course_sections', ['course' => $courseid], 'section', 'id, section, sequence') as $row) {
            $cmids = $row->sequence === '' || $row->sequence === null ? [] : array_map('intval', explode(',', $row->sequence));
            $sequences[(int) $row->section] = ['id' => (int) $row->id, 'cmids' => $cmids];
        }
        return $sequences;
    }

    /**
     * The course modules of a copy in the order of the activities they repeat.
     *
     * @param int[] $cmids The copy's course modules, as they are.
     * @param array $positions Course module of the first copy => its place.
     * @param array $original Course module of a copy => the course module it repeats.
     * @return int[]
     */
    public static function ordered_like(array $cmids, array $positions, array $original): array {
        $placed = [];
        $rest = [];
        foreach ($cmids as $index => $cmid) {
            $position = $positions[$original[$cmid] ?? 0] ?? null;
            if ($position === null) {
                $rest[] = $cmid;
                continue;
            }
            $placed[] = [$position, $index, $cmid];
        }
        usort($placed, static fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return array_merge(array_column($placed, 2), $rest);
    }
}

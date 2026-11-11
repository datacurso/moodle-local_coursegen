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
 * The conversation of a generation from a template, kept with its session so a reload shows it again.
 *
 * The run is followed in the browser, but every step that changes it passes through Moodle: each answer to the
 * review and the building of the course. Those steps are recorded here, with the activities the course got, and the
 * page replays them in the same thread the live run wrote. Each record reads the session again before writing it, so
 * what another step changed on it meanwhile, such as its status, is kept.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_run_history {
    /**
     * Record an answer to the review: the course approved, or the change asked for.
     *
     * @param int $sessionid
     * @param string $action The action sent to the service.
     * @param string $instruction What the professor asked to change, empty when approving.
     */
    public static function record_review(int $sessionid, string $action, string $instruction): void {
        $session = new course_session($sessionid);
        $coursedata = self::coursedata($session);
        $coursedata['reviews'][] = [
            'approved' => $action === 'accept',
            'instruction' => $instruction,
            'time' => time(),
        ];
        self::save($session, $coursedata);
    }

    /**
     * Record that the course was built, with the activities the AI wrote in it, section by section.
     *
     * @param int $sessionid
     * @param int $courseid The course built.
     * @param array $generatedcms Payload cmid => created cmid, for the activities the AI wrote.
     */
    public static function record_completed(int $sessionid, int $courseid, array $generatedcms): void {
        $session = new course_session($sessionid);
        $coursedata = self::coursedata($session);
        $coursedata['completed'] = time();
        $coursedata['written'] = self::written_sections($courseid, array_map('intval', array_values($generatedcms)));
        self::save($session, $coursedata);
    }

    /**
     * What the page needs to replay the conversation of a session.
     *
     * @param course_session $session
     * @return array {started, reviews, completed, sections}
     */
    public static function for_page(course_session $session): array {
        $coursedata = self::coursedata($session);
        $history = [
            'started' => (int) $session->get('timecreated'),
            'reviews' => array_values((array) ($coursedata['reviews'] ?? [])),
            'completed' => (int) ($coursedata['completed'] ?? 0),
            'sections' => array_values((array) ($coursedata['written'] ?? [])),
        ];
        // A course built before its steps were kept: all that is known is that it was approved and then built, when
        // the session was last changed. The activities it got are not known, so they are not listed.
        if ($history['completed'] === 0 && (int) $session->get('status') === course_session::STATUS_CREATED) {
            $finished = (int) $session->get('timemodified');
            $history['reviews'] = [['approved' => true, 'instruction' => '', 'time' => $finished]];
            $history['completed'] = $finished;
        }
        return $history;
    }

    /**
     * The sections of a course with the given activities in them, in the course's order.
     *
     * @param int $courseid
     * @param int[] $cmids
     * @return array [{name, activities: [{title, type}]}]
     */
    private static function written_sections(int $courseid, array $cmids): array {
        if ($courseid <= 0 || empty($cmids)) {
            return [];
        }
        $modinfo = get_fast_modinfo($courseid, -1);
        $sections = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            $activities = [];
            foreach ($modinfo->sections[$section->section] ?? [] as $cmid) {
                if (!in_array((int) $cmid, $cmids, true)) {
                    continue;
                }
                $cm = $modinfo->get_cm($cmid);
                $activities[] = ['title' => $cm->get_formatted_name(), 'type' => (string) $cm->get_module_type_name()];
            }
            if ($activities) {
                $sections[] = ['name' => get_section_name($courseid, $section), 'activities' => $activities];
            }
        }
        return $sections;
    }

    /**
     * The session's stored data, decoded.
     *
     * @param course_session $session
     * @return array
     */
    private static function coursedata(course_session $session): array {
        return (array) json_decode((string) $session->get('coursedata'), true);
    }

    /**
     * Store the session's data.
     *
     * @param course_session $session
     * @param array $coursedata
     */
    private static function save(course_session $session, array $coursedata): void {
        $session->set('coursedata', json_encode($coursedata));
        $session->update();
    }
}

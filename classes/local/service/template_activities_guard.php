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
 * A course from a template is created with every activity the AI wrote, or not at all.
 *
 * Each activity is created on its own, and one that fails used to be skipped: the course went on without it, the links
 * of the other activities to it could not be resolved, and the person only read that the course could not be created.
 * When an activity cannot be created the creation stops right there, the half-built course is removed, the session is
 * marked failed and the person reads which activities could not be created and why: when the files the service made
 * for them could not be fetched, generating the course again makes them anew.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_activities_guard {
    /** @var int How many activity names the message lists; the rest are counted. */
    private const LISTED = 5;

    /**
     * Stop the creation when an activity the AI wrote could not be created.
     *
     * @param course_session $session
     * @param int $courseid The course this creation made, removed when it stops.
     * @param array $activityerrors The activities that could not be created (create_course_service), each with title
     *                              and cause.
     * @throws \moodle_exception template_activities_not_created, with the activities and the cause.
     */
    public static function ensure_all_created(course_session $session, int $courseid, array $activityerrors): void {
        global $CFG;

        if ($activityerrors === []) {
            return;
        }
        require_once($CFG->dirroot . '/course/lib.php');
        course_session_service::update_status((int) $session->get('id'), course_session::STATUS_FAILED);
        if ($courseid > 0) {
            debugging('local_coursegen: course ' . $courseid . ' removed, some activities could not be created: '
                . json_encode($activityerrors, JSON_UNESCAPED_UNICODE), DEBUG_NORMAL);
            delete_course($courseid, false);
        }
        throw new \moodle_exception('template_activities_not_created', 'local_coursegen', '', self::details($activityerrors));
    }

    /**
     * What the message says: how many, the first names, and why.
     *
     * @param array $activityerrors
     * @return \stdClass count, names and reason.
     */
    public static function details(array $activityerrors): \stdClass {
        $names = array_values(array_unique(array_filter(array_map(
            static fn(array $error): string => trim((string) ($error['title'] ?? '')),
            $activityerrors
        ))));
        $listed = array_slice($names, 0, self::LISTED);
        $more = count($names) - count($listed);
        $text = '«' . implode('», «', $listed) . '»';
        if ($more > 0) {
            $text .= ' ' . get_string('template_activities_not_created_more', 'local_coursegen', $more);
        }
        $downloads = array_filter($activityerrors, static fn(array $error): bool => ($error['cause'] ?? '') === 'download');
        $reason = count($downloads) === count($activityerrors) ? 'download' : 'other';
        return (object) [
            'count' => count($activityerrors),
            'names' => $text,
            'reason' => get_string('template_activities_not_created_' . $reason, 'local_coursegen'),
        ];
    }
}

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
use local_coursegen\local\streaming\stream_type;

/**
 * What the page needs to reopen a generation from a template that did not finish.
 *
 * A run that was stopped, failed, or whose page was closed or reloaded keeps its place in the service. The page opens
 * it again where it was, paused, and the person resumes it.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_resume {
    /** @var int[] The states of a session whose run can go on. */
    private const RESUMABLE = [course_session::STATUS_PENDING, course_session::STATUS_FAILED];

    /**
     * The run of a session to reopen, or null when it cannot go on.
     *
     * @param course_session $session
     * @param array $coursedata The data the session was started with.
     * @return array|null {id, created, resumable, templateid, streamurl, context}
     */
    public static function for_page(course_session $session, array $coursedata): ?array {
        $threadid = (string) $session->get('session_id');
        if ($threadid === '' || !in_array((int) $session->get('status'), self::RESUMABLE, true)) {
            return null;
        }
        return [
            'id' => (int) $session->get('id'),
            'created' => false,
            'resumable' => true,
            'templateid' => (int) $session->get('templateid'),
            'streamurl' => streaming_url_builder::relay(stream_type::TEMPLATE, $threadid),
            'context' => template_run_summary::from_coursedata($coursedata),
        ];
    }
}

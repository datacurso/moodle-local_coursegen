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
use local_coursegen\local\template\template_repository;

/**
 * What the page needs to pick up a template generation again after a reload.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_resume_context {
    /**
     * The context of a session when it is an unfinished template generation of the user.
     *
     * @param int $sessionid Local session id, for example 139.
     * @param int $userid Id of the user who loads the page.
     * @return array|null sessionid, templateid, prompt and templatename, or null when the session is not one to resume.
     */
    public static function for_session(int $sessionid, int $userid): ?array {
        if ($sessionid <= 0) {
            return null;
        }
        $session = course_session::get_record(['id' => $sessionid, 'userid' => $userid]);
        if (!$session) {
            return null;
        }
        if ((int) $session->get('status') === course_session::STATUS_CREATED) {
            return null;
        }
        $coursedata = self::coursedata($session);
        $rawid = $coursedata['templateid'] ?? 0;
        $templateid = (int) $rawid;
        if ($templateid <= 0) {
            return null;
        }
        return [
            'sessionid' => $sessionid,
            'templateid' => $templateid,
            'prompt' => self::prompt($coursedata),
            'templatename' => self::template_name($templateid),
        ];
    }

    /**
     * The data stored with the session, as an array.
     *
     * @param course_session $session The session.
     * @return array The decoded data, empty when there is none.
     */
    private static function coursedata(course_session $session): array {
        $raw = $session->get('coursedata');
        $text = (string) $raw;
        $data = json_decode($text, true);
        if (!is_array($data)) {
            return [];
        }
        return $data;
    }

    /**
     * What the teacher asked, kept with the payload of the run.
     *
     * @param array $coursedata The data stored with the session.
     * @return string The general instruction, empty when there was none.
     */
    private static function prompt(array $coursedata): string {
        $payload = $coursedata['payload'] ?? [];
        if (!is_array($payload)) {
            return '';
        }
        $instruction = $payload['general_instruction'] ?? '';
        return (string) $instruction;
    }

    /**
     * The name of the template, empty when it no longer exists.
     *
     * @param int $templateid Template id.
     * @return string The name.
     */
    private static function template_name(int $templateid): string {
        $repository = new template_repository();
        $template = $repository->find($templateid);
        if ($template === null) {
            return '';
        }
        return (string) $template->name;
    }
}

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

namespace local_coursegen\local\reference;

use local_coursegen\local\models\course_session;

/**
 * The files a generation uses, by the name the payload gives their place.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reference_file_urls {
    /**
     * The address of the file of every place the payload lists.
     *
     * @param array $payload The init payload as the session stored it.
     * @param int $userid The teacher.
     * @param int $sessionid
     * @return array<string,string> Place named by the payload => address of its file.
     * @throws \moodle_exception When a listed place has lost its file.
     */
    public static function for_session(array $payload, int $userid, int $sessionid): array {
        $storedkeys = reference_payload_keys::stored_keys($payload);
        $urls = [];
        foreach ($storedkeys as $payloadkey => $storedkey) {
            $file = reference_file_storage::session_file($userid, $sessionid, $storedkey);
            if ($file === null) {
                throw new \moodle_exception('referencefilemissing', 'local_coursegen', '', $payloadkey);
            }
            $url = reference_file_storage::url_of($file);
            $urls[$payloadkey] = $url->out(false);
        }
        return $urls;
    }

    /**
     * The address of the file of every place a session lists.
     *
     * @param course_session $session
     * @return array<string,string>
     */
    public static function for_course_session(course_session $session): array {
        $coursedata = $session->get('coursedata');
        $coursedata = (string) $coursedata;
        $data = json_decode($coursedata, true);
        $payload = $data['payload'] ?? [];
        $userid = (int) $session->get('userid');
        $sessionid = (int) $session->get('id');
        return self::for_session($payload, $userid, $sessionid);
    }
}

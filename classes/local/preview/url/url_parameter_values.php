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

namespace local_coursegen\local\preview\url;

/**
 * The values a URL's variables stand for, read from the course and the
 * reader. Kept apart from view.php only because together they crossed the
 * 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait url_parameter_values {
    /**
     * mod/url/locallib.php url_get_variable_values().
     *
     * The values a URL's variables stand for. Read from the course and the
     * reader the same way the module reads them; the course module and its
     * context are the ones handed in.
     *
     * @param stdClass $url
     * @param stdClass $cm
     * @param stdClass $course
     * @param stdClass $config
     * @return array
     */
    public static function url_get_variable_values($url, $cm, $course, $config) {
        global $USER, $CFG;

        $site = get_site();

        $coursecontext = \context_course::instance($course->id);

        $values = array (
            'courseid'        => $course->id,
            'coursefullname'  => format_string($course->fullname, true, array('context' => $coursecontext)),
            'courseshortname' => format_string($course->shortname, true, array('context' => $coursecontext)),
            'courseidnumber'  => $course->idnumber,
            'coursesummary'   => $course->summary,
            'courseformat'    => $course->format,
            'lang'            => current_language(),
            'sitename'        => format_string($site->fullname, true, array('context' => $coursecontext)),
            'serverurl'       => $CFG->wwwroot,
            'currenttime'     => time(),
            'urlinstance'     => $url->id,
            'urlcmid'         => $cm->id,
            'urlname'         => format_string($url->name, true, array('context' => $coursecontext)),
            'urlidnumber'     => $cm->idnumber ?? '',
        );

        if (isloggedin()) {
            $values['userid']          = $USER->id;
            $values['userusername']    = $USER->username;
            $values['useridnumber']    = $USER->idnumber;
            $values['userfirstname']   = $USER->firstname;
            $values['userlastname']    = $USER->lastname;
            $values['userfullname']    = fullname($USER);
            $values['useremail']       = $USER->email;
            $values['usericq']         = $USER->icq;
            $values['userphone1']      = $USER->phone1;
            $values['userphone2']      = $USER->phone2;
            $values['userinstitution'] = $USER->institution;
            $values['userdepartment']  = $USER->department;
            $values['useraddress']     = $USER->address;
            $values['usercity']        = $USER->city;
            $now = new \DateTime('now', \core_date::get_user_timezone_object());
            $values['usertimezone']    = $now->getOffset() / 3600.0; // Value in hours for BC.
            $values['userurl']         = $USER->url;
        }

        // weak imitation of Single-Sign-On, for backwards compatibility only
        // NOTE: login hack is not included in 2.0 any more, new contrib auth plugin
        //       needs to be createed if somebody needs the old functionality!
        if (!empty($config->secretphrase)) {
            $values['encryptedcode'] = self::url_get_encrypted_parameter($url, $config);
        }

        //hmm, this is pretty fragile and slow, why do we need it here??
        if ($url->parameters) {
            $parameters = (array) unserialize_array($url->parameters);
            foreach ($parameters as $parse => $parameter) {
                if (strpos($parameter, 'course') === 0 && substr($parameter, 0, 6) === 'course') {
                    $field = substr($parameter, 6);
                    $values[$parameter] = $course->$field ?? '';
                }
            }
        }

        return $values;
    }

    /**
     * mod/url/locallib.php url_get_encrypted_parameter().
     *
     * @param stdClass $url
     * @param stdClass $config
     * @return string
     */
    public static function url_get_encrypted_parameter($url, $config) {
        global $CFG;

        if (file_exists("$CFG->dirroot/local/externserverfile.php")) {
            require_once("$CFG->dirroot/local/externserverfile.php");
            if (function_exists('extern_server_file')) {
                return extern_server_file($url, $config);
            }
        }
        return md5(getremoteaddr() . $config->secretphrase);
    }
}

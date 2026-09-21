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

use context;
use core_media_manager;
use html_writer;
use moodle_url;
use stdClass;

/**
 * mod_url's view code, ported to run against the payload.
 *
 * Copied from mod/url/locallib.php and mod/url/view.php (Moodle 4.5). Method
 * names are the functions they came from. What changed: the functions return
 * their output instead of printing it and ending the page; the context is
 * handed in; the header and footer, which the preview page draws itself, are
 * left out. Everything that decides what a URL looks like is untouched.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view {
    /**
     * The whole of what mod/url/view.php prints for one display type.
     *
     * @param stdClass $url
     * @param stdClass $cm
     * @param stdClass $course
     * @param context $context
     * @return string
     */
    public static function display(stdClass $url, stdClass $cm, stdClass $course, context $context): string {
        global $CFG;
        require_once($CFG->libdir . '/resourcelib.php');

        $displaytype = self::url_get_final_display_type($url);
        if ($displaytype == RESOURCELIB_DISPLAY_EMBED) {
            return self::url_display_embed($url, $cm, $course, $context);
        }
        // A frameset is a whole document of its own and cannot be shown
        // inside a page; the real page shows the link instead when it cannot
        // frame, and so does this - for RESOURCELIB_DISPLAY_FRAME and for
        // every other display type.
        return self::url_print_workaround($url, $cm, $course, $context);
    }

    /**
     * mod/url/locallib.php url_get_full_url().
     *
     * @param stdClass $url
     * @param stdClass $cm
     * @param stdClass $course
     * @param stdClass|null $config
     * @return string
     */
    public static function url_get_full_url($url, $cm, $course, $config = null) {

        $parameters = empty($url->parameters) ? [] : (array) unserialize_array($url->parameters);

        // make sure there are no encoded entities, it is ok to do this twice
        $fullurl = html_entity_decode($url->externalurl, ENT_QUOTES, 'UTF-8');

        $letters = '\pL';
        $latin = 'a-zA-Z';
        $digits = '0-9';
        $symbols = '\x{20E3}\x{00AE}\x{00A9}\x{203C}\x{2047}\x{2048}\x{2049}\x{3030}\x{303D}\x{2139}\x{2122}\x{3297}\x{3299}' .
                   '\x{2300}-\x{23FF}\x{2600}-\x{27BF}\x{2B00}-\x{2BF0}';
        $arabic = '\x{FE00}-\x{FEFF}';
        $math = '\x{2190}-\x{21FF}\x{2900}-\x{297F}';
        $othernumbers = '\x{2460}-\x{24FF}';
        $geometric = '\x{25A0}-\x{25FF}';
        $emojis = '\x{1F000}-\x{1F6FF}';

        if (preg_match('/^(\/|https?:|ftp:)/i', $fullurl) or preg_match('|^/|', $fullurl)) {
            // encode extra chars in URLs - this does not make it always valid, but it helps with some UTF-8 problems
            // Thanks to 💩.la emojis count as valid, too.
            $allowed = "[" . $letters . $latin . $digits . $symbols . $arabic . $math . $othernumbers . $geometric .
                $emojis . "]" . preg_quote(';/?:@=&$_.+!*(),-#%', '/');
            $fullurl = preg_replace_callback("/[^$allowed]/u", [self::class, 'url_filter_callback'], $fullurl);
        } else {
            // encode special chars only
            $fullurl = str_replace('"', '%22', $fullurl);
            $fullurl = str_replace('\'', '%27', $fullurl);
            $fullurl = str_replace(' ', '%20', $fullurl);
            $fullurl = str_replace('<', '%3C', $fullurl);
            $fullurl = str_replace('>', '%3E', $fullurl);
        }

        if (!$config) {
            $config = get_config('url');
        }

        // add variable url parameters
        if ($config->allowvariables && !empty($parameters)) {
            $paramvalues = self::url_get_variable_values($url, $cm, $course, $config);

            foreach ($parameters as $parse => $parameter) {
                if (isset($paramvalues[$parameter])) {
                    $parameters[$parse] = rawurlencode($parse) . '=' . rawurlencode($paramvalues[$parameter]);
                } else {
                    unset($parameters[$parse]);
                }
            }

            if (!empty($parameters)) {
                if (stripos($fullurl, 'teamspeak://') === 0) {
                    $fullurl = $fullurl . '?' . implode('?', $parameters);
                } else {
                    $join = (strpos($fullurl, '?') === false) ? '?' : '&';
                    $fullurl = $fullurl . $join . implode('&', $parameters);
                }
            }
        }

        // encode all & to &amp; entity
        $fullurl = str_replace('&', '&amp;', $fullurl);

        return $fullurl;
    }

    /**
     * mod/url/locallib.php url_filter_callback().
     *
     * @param array $matches
     * @return string
     */
    public static function url_filter_callback($matches) {
        return rawurlencode($matches[0]);
    }

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

    /**
     * mod/url/locallib.php url_get_final_display_type().
     *
     * @param stdClass $url
     * @return int
     */
    public static function url_get_final_display_type($url) {
        global $CFG;

        if ($url->display != RESOURCELIB_DISPLAY_AUTO) {
            return $url->display;
        }

        // detect links to local moodle pages
        if (strpos($url->externalurl, $CFG->wwwroot) === 0) {
            if (strpos($url->externalurl, 'file.php') === false and strpos($url->externalurl, '.php') !== false ) {
                // most probably our moodle page with navigation
                return RESOURCELIB_DISPLAY_OPEN;
            }
        }

        // Binaries and other formats that are known to cause trouble for external links.
        static $download = ['application/zip', 'application/x-tar', 'application/g-zip',
                            'application/pdf', 'text/html', 'document/unknown'];
        static $embed    = array('image/gif', 'image/jpeg', 'image/png', 'image/svg+xml',         // images
                                 'application/x-shockwave-flash', 'video/x-flv', 'video/x-ms-wm', // video formats
                                 'video/quicktime', 'video/mpeg', 'video/mp4',
                                 'audio/mp3', 'audio/x-realaudio-plugin', 'x-realaudio-plugin',   // audio formats,
                                );

        $mimetype = resourcelib_guess_url_mimetype($url->externalurl);

        if (in_array($mimetype, $download)) {
            return RESOURCELIB_DISPLAY_DOWNLOAD;
        }
        if (in_array($mimetype, $embed)) {
            return RESOURCELIB_DISPLAY_EMBED;
        }

        // let the browser deal with it somehow
        return RESOURCELIB_DISPLAY_OPEN;
    }

    /**
     * mod/url/locallib.php url_get_intro(), with the context handed in.
     *
     * @param stdClass $url
     * @param stdClass $cm
     * @param context $context
     * @param bool $ignoresettings
     * @return string
     */
    public static function url_get_intro(stdClass $url, stdClass $cm, context $context, bool $ignoresettings = false): string {
        $options = empty($url->displayoptions) ? [] : (array) unserialize_array($url->displayoptions);
        if ($ignoresettings || !empty($options['printintro'])) {
            if (!html_is_blank($url->intro)) {
                // format_module_intro('url', $url, $cm->id), with the context given.
                $formatoptions = ['noclean' => true, 'para' => false, 'filter' => true, 'context' => $context, 'overflowdiv' => true];
                $intro = file_rewrite_pluginfile_urls($url->intro, 'pluginfile.php', $context->id, 'mod_url', 'intro', null);
                return trim(format_text($intro, $url->introformat, $formatoptions, null));
            }
        }

        return '';
    }

    /**
     * mod/url/locallib.php url_print_workaround(), returning what it prints.
     *
     * @param stdClass $url
     * @param stdClass $cm
     * @param stdClass $course
     * @param context $context
     * @return string
     */
    public static function url_print_workaround($url, $cm, $course, context $context): string {
        $fullurl = new moodle_url(self::url_get_full_url($url, $cm, $course));

        $display = self::url_get_final_display_type($url);
        if ($display == RESOURCELIB_DISPLAY_POPUP) {
            $jsfullurl = addslashes_js($fullurl->out(false));
            $options = empty($url->displayoptions) ? [] : (array) unserialize_array($url->displayoptions);
            $width  = empty($options['popupwidth'])  ? 620 : $options['popupwidth'];
            $height = empty($options['popupheight']) ? 450 : $options['popupheight'];
            $wh = "width=$width,height=$height,toolbar=no,location=no,menubar=no,copyhistory=no,status=no,directories=no,scrollbars=yes,resizable=yes";
            $attributes = ['onclick' => "window.open('$jsfullurl', '', '$wh'); return false;"];

        } else if ($display == RESOURCELIB_DISPLAY_NEW) {
            $attributes = ['onclick' => "this.target='_blank';"];

        } else {
            $attributes = [];
        }

        $out = '<div class="urlworkaround">';
        $out .= get_string('clicktoopen', 'url', html_writer::link($fullurl, format_string($cm->name), $attributes));
        $out .= '</div>';
        return $out;
    }

    /**
     * mod/url/locallib.php url_display_embed(), returning what it prints.
     *
     * @param stdClass $url
     * @param stdClass $cm
     * @param stdClass $course
     * @param context $context
     * @return string
     */
    public static function url_display_embed($url, $cm, $course, context $context): string {
        global $PAGE;

        $mimetype = resourcelib_guess_url_mimetype($url->externalurl);
        $fullurl  = self::url_get_full_url($url, $cm, $course);
        $title    = $url->name;

        $moodleurl = new moodle_url($fullurl);
        $link = html_writer::link($moodleurl, format_string($cm->name));
        $clicktoopen = get_string('clicktoopen', 'url', $link);

        $extension = resourcelib_get_extension($url->externalurl);

        $mediamanager = core_media_manager::instance($PAGE);
        $embedoptions = array(
            core_media_manager::OPTION_TRUSTED => true,
            core_media_manager::OPTION_BLOCK => true
        );

        if (in_array($mimetype, array('image/gif','image/jpeg','image/png'))) {  // It's an image
            $code = resourcelib_embed_image($fullurl, $title);

        } else if ($mediamanager->can_embed_url($moodleurl, $embedoptions)) {
            // Media (audio/video) file.
            $code = $mediamanager->embed_url($moodleurl, $title, 0, 0, $embedoptions);

        } else {
            // anything else - just try object tag enlarged as much as possible
            $code = resourcelib_embed_general($fullurl, $title, $clicktoopen, $mimetype);
        }

        return $code;
    }
}

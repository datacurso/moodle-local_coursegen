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
use moodle_url;
use stdClass;

/**
 * Which display type a URL resolves to, its description, and the two ways it
 * can be drawn: a workaround link, or embedded. Kept apart from view.php only
 * because together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait url_display {
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

        global $OUTPUT;
        $link = $OUTPUT->render_from_template('local_coursegen/preview_link', [
            'url' => $fullurl->out(false),
            'text' => format_string($cm->name),
            'onclick' => $attributes['onclick'] ?? '',
        ]);
        return $OUTPUT->render_from_template('local_coursegen/preview_url_workaround', [
            'clicktoopen' => get_string('clicktoopen', 'url', $link),
        ]);
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
        global $PAGE, $OUTPUT;

        $mimetype = resourcelib_guess_url_mimetype($url->externalurl);
        $fullurl  = self::url_get_full_url($url, $cm, $course);
        $title    = $url->name;

        $moodleurl = new moodle_url($fullurl);
        $link = $OUTPUT->render_from_template('local_coursegen/preview_link', [
            'url' => $moodleurl->out(false),
            'text' => format_string($cm->name),
        ]);
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

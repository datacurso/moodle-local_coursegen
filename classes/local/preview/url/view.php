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
use stdClass;

/**
 * mod_url's view code, run here against the payload instead of the database.
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
    use url_link_building;
    use url_parameter_values;
    use url_display;

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
}

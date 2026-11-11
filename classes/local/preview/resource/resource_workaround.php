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

namespace local_coursegen\local\preview\resource;

/**
 * mod/resource/locallib.php resource_print_workaround() and the per-display-type
 * links it draws, one to a method, dispatched by a map. Kept apart from
 * view.php only because together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait resource_workaround {
    /**
     * mod/resource/locallib.php resource_print_workaround(), returning what it prints.
     *
     * @param stdClass $resource
     * @param stdClass $cm
     * @param stdClass $course
     * @param json_file $file
     * @return string
     */
    protected function resource_print_workaround($resource, $cm, $course, $file) {
        global $OUTPUT;
        $resource->mainfile = $file->get_filename();
        $finaldisplaytype = $this->resource_get_final_display_type($resource);
        $method = $this->workaround_method($finaldisplaytype);
        $content = $this->$method($resource, $file);

        return $OUTPUT->render_from_template('local_coursegen/preview_container', [
            'classes' => 'resourceworkaround',
            'content' => $content,
        ]);
    }

    /**
     * Which method draws a resource's workaround link for a display type,
     * mapped rather than switched on.
     *
     * @param mixed $displaytype
     * @return string
     */
    protected function workaround_method($displaytype): string {
        $methods = [
            RESOURCELIB_DISPLAY_POPUP => 'workaround_popup',
            RESOURCELIB_DISPLAY_NEW => 'workaround_new_window',
            RESOURCELIB_DISPLAY_DOWNLOAD => 'workaround_download',
        ];
        return $methods[$displaytype] ?? 'workaround_open';
    }

    /**
     * RESOURCELIB_DISPLAY_POPUP's own workaround link, opening in a popup window.
     *
     * @param stdClass $resource
     * @param json_file $file
     * @return string
     */
    protected function workaround_popup($resource, $file): string {
        $path = '/'.$file->get_contextid().'/mod_resource/content/'.$resource->revision.$file->get_filepath().$file->get_filename();
        $fullurl = file_encode_url($this->wwwroot().'/pluginfile.php', $path, false);
        $options = [];
        if (!empty($resource->displayoptions)) {
            $options = (array) unserialize_array($resource->displayoptions);
        }
        $width = 620;
        if (!empty($options['popupwidth'])) {
            $width = $options['popupwidth'];
        }
        $height = 450;
        if (!empty($options['popupheight'])) {
            $height = $options['popupheight'];
        }
        $wh = "width=$width,height=$height,toolbar=no,location=no,menubar=no,copyhistory=no,status=no,directories=no,scrollbars=yes,resizable=yes";
        $onclick = "window.open('$fullurl', '', '$wh'); return false;";
        return $this->resource_get_clicktoopen($file, $resource->revision, $onclick);
    }

    /**
     * RESOURCELIB_DISPLAY_NEW's own workaround link, opening in a new tab.
     *
     * @param stdClass $resource
     * @param json_file $file
     * @return string
     */
    protected function workaround_new_window($resource, $file): string {
        return $this->resource_get_clicktoopen($file, $resource->revision, "this.target='_blank'");
    }

    /**
     * RESOURCELIB_DISPLAY_DOWNLOAD's own workaround link.
     *
     * @param stdClass $resource
     * @param json_file $file
     * @return string
     */
    protected function workaround_download($resource, $file): string {
        return $this->resource_get_clicktodownload($file, $resource->revision);
    }

    /**
     * RESOURCELIB_DISPLAY_OPEN's own workaround link, and every display type
     * the map above does not name.
     *
     * @param stdClass $resource
     * @param json_file $file
     * @return string
     */
    protected function workaround_open($resource, $file): string {
        return $this->resource_get_clicktoopen($file, $resource->revision);
    }

    /**
     * $CFG->wwwroot.
     *
     * @return string
     */
    protected function wwwroot(): string {
        global $CFG;
        return $CFG->wwwroot;
    }
}

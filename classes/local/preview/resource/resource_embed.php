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

use core_media_manager;
use moodle_url;

/**
 * RESOURCELIB_DISPLAY_EMBED's own rendering, kept apart from view.php only
 * because together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait resource_embed {
    /**
     * mod/resource/locallib.php resource_display_embed(), returning what it prints.
     *
     * @param stdClass $resource
     * @param stdClass $cm
     * @param stdClass $course
     * @param json_file $file
     * @return string
     */
    protected function resource_display_embed($resource, $cm, $course, $file) {
        global $PAGE;

        $clicktoopen = $this->resource_get_clicktoopen($file, $resource->revision);

        $context = $this->context;
        $moodleurl = moodle_url::make_pluginfile_url($context->id, 'mod_resource', 'content', $resource->revision,
                $file->get_filepath(), $file->get_filename());

        $mimetype = $file->get_mimetype();
        $title    = $resource->name;

        $extension = resourcelib_get_extension($file->get_filename());

        $mediamanager = core_media_manager::instance($PAGE);
        $embedoptions = array(
            core_media_manager::OPTION_TRUSTED => true,
            core_media_manager::OPTION_BLOCK => true,
        );

        if (file_mimetype_in_typegroup($mimetype, 'web_image')) {  // It's an image
            $code = resourcelib_embed_image($moodleurl->out(), $title);

        } else if ($mimetype === 'application/pdf') {
            // PDF document
            $code = resourcelib_embed_pdf($moodleurl->out(), $title, $clicktoopen);

        } else if ($mediamanager->can_embed_url($moodleurl, $embedoptions)) {
            // Media (audio/video) file.
            $code = $mediamanager->embed_url($moodleurl, $title, 0, 0, $embedoptions);

        } else {
            // We need a way to discover if we are loading remote docs inside an iframe.
            $moodleurl->param('embed', 1);

            // anything else - just try object tag enlarged as much as possible
            $code = resourcelib_embed_general($moodleurl, $title, $clicktoopen, $mimetype);
        }

        return format_text($code, FORMAT_HTML, ['noclean' => true]);
    }
}

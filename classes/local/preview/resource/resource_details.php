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
 * The file's size/type/date details and the description built around them,
 * ported from mod/resource/locallib.php. Kept apart from view.php only
 * because together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait resource_details {
    /**
     * mod/resource/locallib.php resource_get_file_details(), against the payload's files.
     *
     * @param stdClass $resource
     * @param stdClass $cm
     * @return array
     */
    protected function resource_get_file_details($resource, $cm) {
        $options = empty($resource->displayoptions) ? [] : (array) unserialize_array($resource->displayoptions);
        $filedetails = array();
        if (!empty($options['showsize']) || !empty($options['showtype']) || !empty($options['showdate'])) {
            $context = $this->context;
            $files = $this->fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder DESC, id ASC', false);
            // For a typical file resource, the sortorder is 1 for the main file
            // and 0 for all other files. This sort approach is used just in case
            // there are situations where the file has a different sort order.
            $mainfile = $files ? reset($files) : null;
            if (!empty($options['showsize'])) {
                $filedetails['size'] = 0;
                foreach ($files as $file) {
                    // This will also synchronize the file size for external files if needed.
                    $filedetails['size'] += $file->get_filesize();
                    if ($file->get_repository_id()) {
                        // If file is a reference the 'size' attribute can not be cached.
                        $filedetails['isref'] = true;
                    }
                }
            }
            if (!empty($options['showtype'])) {
                if ($mainfile) {
                    $filedetails['type'] = get_mimetype_description($mainfile);
                    $filedetails['mimetype'] = $mainfile->get_mimetype();
                    $filedetails['extension'] = strtoupper(resourcelib_get_extension($mainfile->get_filename()));
                    // Only show type if it is not unknown.
                    if ($filedetails['type'] === get_mimetype_description('document/unknown')) {
                        $filedetails['type'] = '';
                    }
                } else {
                    $filedetails['type'] = '';
                }
            }
            if (!empty($options['showdate'])) {
                if ($mainfile) {
                    // Modified date may be up to several minutes later than uploaded date just because
                    // teacher did not submit the form promptly. Give teacher up to 5 minutes to do it.
                    if ($mainfile->get_timemodified() > $mainfile->get_timecreated() + 5 * MINSECS) {
                        $filedetails['modifieddate'] = $mainfile->get_timemodified();
                    } else {
                        $filedetails['uploadeddate'] = $mainfile->get_timecreated();
                    }
                    if ($mainfile->get_repository_id()) {
                        // If main file is a reference the 'date' attribute can not be cached.
                        $filedetails['isref'] = true;
                    }
                } else {
                    $filedetails['uploadeddate'] = '';
                }
            }
        }
        return $filedetails;
    }

    /**
     * mod/resource/locallib.php resource_get_optional_details().
     *
     * @param stdClass $resource
     * @param stdClass $cm
     * @param bool $showtype
     * @return string
     */
    protected function resource_get_optional_details($resource, $cm, bool $showtype = true) {
        $details = '';

        $options = empty($resource->displayoptions) ? [] : (array) unserialize_array($resource->displayoptions);
        if (!empty($options['showsize']) || ($showtype && !empty($options['showtype'])) || !empty($options['showdate'])) {
            if (!array_key_exists('filedetails', $options)) {
                $filedetails = $this->resource_get_file_details($resource, $cm);
            } else {
                $filedetails = $options['filedetails'];
            }
            $size = '';
            $type = '';
            $date = '';
            $langstring = '';
            $infodisplayed = 0;
            if (!empty($options['showsize'])) {
                if (!empty($filedetails['size'])) {
                    $size = display_size($filedetails['size']);
                    $langstring .= 'size';
                    $infodisplayed += 1;
                }
            }
            if ($showtype && !empty($options['showtype'])) {
                if (!empty($filedetails['type'])) {
                    $type = $filedetails['extension'];
                    $langstring .= 'type';
                    $infodisplayed += 1;
                }
            }
            if (!empty($options['showdate']) && (!empty($filedetails['modifieddate']) || !empty($filedetails['uploadeddate']))) {
                if (!empty($filedetails['modifieddate'])) {
                    $date = get_string('modifieddate', 'mod_resource', userdate($filedetails['modifieddate'],
                        get_string('strftimedatetimeshort', 'langconfig')));
                } else if (!empty($filedetails['uploadeddate'])) {
                    $date = get_string('uploadeddate', 'mod_resource', userdate($filedetails['uploadeddate'],
                        get_string('strftimedatetimeshort', 'langconfig')));
                }
                $langstring .= 'date';
                $infodisplayed += 1;
            }

            if ($infodisplayed > 1) {
                $details = get_string("resourcedetails_{$langstring}", 'resource',
                        (object)array('size' => $size, 'type' => $type, 'date' => $date));
            } else {
                // Only one of size, type and date is set, so just append.
                $details = $size . $type . $date;
            }
        }

        return $details;
    }

    /**
     * mod/resource/locallib.php resource_get_intro().
     *
     * @param stdClass $resource
     * @param stdClass $cm
     * @param bool $ignoresettings
     * @return string
     */
    protected function resource_get_intro(object $resource, object $cm, bool $ignoresettings = false): string {
        $options = empty($resource->displayoptions) ? [] : (array) unserialize_array($resource->displayoptions);

        global $OUTPUT;
        $extraintro = $this->resource_get_optional_details($resource, $cm);
        if ($extraintro) {
            // Put a paragaph tag around the details
            $extraintro = $OUTPUT->render_from_template('local_coursegen/preview_resource_details', ['text' => $extraintro]);
        }

        $content = "";
        if ($ignoresettings || !empty($options['printintro']) || $extraintro) {
            $resourceintro = !empty($options['printintro']) && !html_is_blank($resource->intro);

            if ($resourceintro) {
                $content .= ($this->intro)($resource);
            }

            if ($extraintro) {
                $content .= $extraintro;
            }
        }

        return $content;
    }
}

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

use context;
use core_media_manager;
use html_writer;
use local_coursegen\local\preview\json_file;
use local_coursegen\local\preview\json_file_storage;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/resourcelib.php');

/**
 * mod_resource's view code, ported to run against the payload.
 *
 * Copied from mod/resource/view.php and locallib.php (Moodle 4.5). Method
 * names are the functions they came from. What changed: the file is read from
 * the payload's listing instead of the file storage; the context is handed
 * in; a resource that would send the reader straight to the file is shown the
 * way its author sees it on a course with a view page, as a link to open it,
 * because a preview is a page and a download is not; and the frame display,
 * which is a frameset around the whole window, is shown as that same link.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view {
    /** @var stdClass */
    protected stdClass $resource;
    /** @var stdClass */
    protected stdClass $cm;
    /** @var stdClass */
    protected stdClass $course;
    /** @var context */
    protected context $context;
    /** @var json_file_storage */
    protected json_file_storage $fs;
    /** @var callable The module's format_module_intro(). */
    protected $intro;
    /** @var json_file|null The main file, once looked for. */
    protected ?json_file $file = null;
    /** @var bool Whether the main file has been looked for. */
    protected bool $looked = false;

    /**
     * Constructor.
     *
     * @param stdClass $resource
     * @param stdClass $cm
     * @param stdClass $course
     * @param context $context
     * @param json_file_storage $fs
     * @param callable $intro
     */
    public function __construct(
        stdClass $resource,
        stdClass $cm,
        stdClass $course,
        context $context,
        json_file_storage $fs,
        callable $intro
    ) {
        $this->resource = $resource;
        $this->cm = $cm;
        $this->course = $course;
        $this->context = $context;
        $this->fs = $fs;
        $this->intro = $intro;
    }

    /**
     * The main file, as mod/resource/view.php picks it.
     *
     * @return json_file|null
     */
    protected function main_file(): ?json_file {
        if (!$this->looked) {
            $this->looked = true;
            $files = $this->fs->get_area_files($this->context->id, 'mod_resource', 'content', 0, 'sortorder DESC, id ASC', false);
            $this->file = $files ? reset($files) : null;
            if ($this->file) {
                $this->resource->mainfile = $this->file->get_filename();
            }
        }
        return $this->file;
    }

    /**
     * How the file is shown, as mod/resource/view.php decides it.
     *
     * @return int A RESOURCELIB_DISPLAY_* constant.
     */
    public function display_type(): int {
        $this->main_file();
        return (int) $this->resource_get_final_display_type($this->resource);
    }

    /**
     * What the module puts in the activity header.
     *
     * @return string
     */
    public function description(): string {
        $file = $this->main_file();
        if ($file === null) {
            return $this->resource_get_intro($this->resource, $this->cm);
        }
        // resource_display_embed() shows the description as set; the other
        // displays show it regardless of the setting.
        $ignoresettings = $this->display_type() != RESOURCELIB_DISPLAY_EMBED;
        return $this->resource_get_intro($this->resource, $this->cm, $ignoresettings);
    }

    /**
     * mod/resource/view.php from the file lookup to the footer.
     *
     * @return string
     */
    public function page(): string {
        global $OUTPUT;

        $file = $this->main_file();
        if ($file === null) {
            // resource_print_filenotfound(), for a resource nobody migrated.
            return $OUTPUT->notification(get_string('filenotfound', 'resource'));
        }

        if ($this->display_type() === RESOURCELIB_DISPLAY_EMBED) {
            return $this->resource_display_embed($this->resource, $this->cm, $this->course, $file);
        }
        return $this->resource_print_workaround($this->resource, $this->cm, $this->course, $file);
    }

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

    /**
     * mod/resource/locallib.php resource_get_clicktoopen().
     *
     * @param json_file $file
     * @param int $revision
     * @param string $extra
     * @return string
     */
    protected function resource_get_clicktoopen($file, $revision, $extra='') {
        global $CFG;

        $filename = $file->get_filename();
        $path = '/'.$file->get_contextid().'/mod_resource/content/'.$revision.$file->get_filepath().$file->get_filename();
        $fullurl = file_encode_url($CFG->wwwroot.'/pluginfile.php', $path, false);

        $string = get_string('clicktoopen2', 'resource', "<a href=\"$fullurl\" $extra>$filename</a>");

        return $string;
    }

    /**
     * mod/resource/locallib.php resource_get_clicktodownload().
     *
     * @param json_file $file
     * @param int $revision
     * @return string
     */
    protected function resource_get_clicktodownload($file, $revision) {
        global $CFG;

        $filename = $file->get_filename();
        $path = '/'.$file->get_contextid().'/mod_resource/content/'.$revision.$file->get_filepath().$file->get_filename();
        $fullurl = file_encode_url($CFG->wwwroot.'/pluginfile.php', $path, true);

        $string = get_string('clicktodownload', 'resource', "<a href=\"$fullurl\">$filename</a>");

        return $string;
    }

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
        $out = '';
        $resource->mainfile = $file->get_filename();
        $out .= '<div class="resourceworkaround">';
        $finaldisplaytype = $this->resource_get_final_display_type($resource);
        if ($finaldisplaytype == RESOURCELIB_DISPLAY_POPUP) {
            $path = '/'.$file->get_contextid().'/mod_resource/content/'.$resource->revision.$file->get_filepath().$file->get_filename();
            $fullurl = file_encode_url($this->wwwroot().'/pluginfile.php', $path, false);
            $options = empty($resource->displayoptions) ? [] : (array) unserialize_array($resource->displayoptions);
            $width  = empty($options['popupwidth'])  ? 620 : $options['popupwidth'];
            $height = empty($options['popupheight']) ? 450 : $options['popupheight'];
            $wh = "width=$width,height=$height,toolbar=no,location=no,menubar=no,copyhistory=no,status=no,directories=no,scrollbars=yes,resizable=yes";
            $extra = "onclick=\"window.open('$fullurl', '', '$wh'); return false;\"";
            $out .= $this->resource_get_clicktoopen($file, $resource->revision, $extra);
        } else if ($finaldisplaytype == RESOURCELIB_DISPLAY_NEW) {
            $extra = 'onclick="this.target=\'_blank\'"';
            $out .= $this->resource_get_clicktoopen($file, $resource->revision, $extra);
        } else if ($finaldisplaytype == RESOURCELIB_DISPLAY_DOWNLOAD) {
            $out .= $this->resource_get_clicktodownload($file, $resource->revision);
        } else {
            $out .= $this->resource_get_clicktoopen($file, $resource->revision);
        }
        $out .= '</div>';

        return $out;
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

        $extraintro = $this->resource_get_optional_details($resource, $cm);
        if ($extraintro) {
            // Put a paragaph tag around the details
            $extraintro = html_writer::tag('p', $extraintro, array('class' => 'resourcedetails'));
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

    /**
     * mod/resource/locallib.php resource_get_final_display_type().
     *
     * @param stdClass $resource
     * @return int
     */
    protected function resource_get_final_display_type($resource) {
        if ($resource->display != RESOURCELIB_DISPLAY_AUTO) {
            return $resource->display;
        }

        if (empty($resource->mainfile)) {
            return RESOURCELIB_DISPLAY_DOWNLOAD;
        } else {
            $mimetype = mimeinfo('type', $resource->mainfile);
        }

        if (file_mimetype_in_typegroup($mimetype, 'archive')) {
            return RESOURCELIB_DISPLAY_DOWNLOAD;
        }
        if (file_mimetype_in_typegroup($mimetype, array('web_image', '.htm', 'web_video', 'web_audio'))) {
            return RESOURCELIB_DISPLAY_EMBED;
        }

        // let the browser deal with it somehow
        return RESOURCELIB_DISPLAY_OPEN;
    }
}

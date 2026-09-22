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
use local_coursegen\local\preview\json_file;
use local_coursegen\local\preview\json_file_storage;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/resourcelib.php');

/**
 * mod_resource's view code, run here against the payload instead of the database.
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
    use resource_links;
    use resource_embed;
    use resource_workaround;
    use resource_details;

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

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

namespace local_coursegen\local\preview\folder;

use context;
use html_writer;
use local_coursegen\local\preview\json_file_storage;
use moodle_url;
use single_button;
use stdClass;

/**
 * mod_folder's view code, ported to run against the payload.
 *
 * Copied from mod/folder/renderer.php and lib.php (Moodle 4.5). Method names
 * are the functions they came from. What changed: the files are read from
 * the payload's listing instead of the file storage; the context is handed
 * in; the site's download limit is handed in; and the buttons that would
 * edit or zip the real folder keep their place but lead back to the preview.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view {
    /** mod/folder/lib.php. */
    const FOLDER_DISPLAY_PAGE = 0;
    /** mod/folder/lib.php. */
    const FOLDER_DISPLAY_INLINE = 1;

    /** @var stdClass */
    protected stdClass $folder;
    /** @var stdClass */
    protected stdClass $cm;
    /** @var context */
    protected context $context;
    /** @var json_file_storage */
    protected json_file_storage $fs;
    /** @var int get_config('folder', 'maxsizetodownload'), in MB. */
    protected int $maxsizetodownload;
    /** @var moodle_url Where the preview is read. */
    protected moodle_url $here;
    /** @var callable The module's format_module_intro(). */
    protected $intro;

    /**
     * Constructor.
     *
     * @param stdClass $folder
     * @param stdClass $cm
     * @param context $context
     * @param json_file_storage $fs
     * @param int $maxsizetodownload
     * @param moodle_url $here
     * @param callable $intro
     */
    public function __construct(
        stdClass $folder,
        stdClass $cm,
        context $context,
        json_file_storage $fs,
        int $maxsizetodownload,
        moodle_url $here,
        callable $intro
    ) {
        $this->folder = $folder;
        $this->cm = $cm;
        $this->context = $context;
        $this->fs = $fs;
        $this->maxsizetodownload = $maxsizetodownload;
        $this->here = $here;
        $this->intro = $intro;
    }

    /**
     * mod/folder/renderer.php display_folder().
     *
     * @return string
     */
    public function display_folder(): string {
        global $OUTPUT;
        static $treecounter = 0;

        $folder = $this->folder;
        $cm = $this->cm;
        $context = $this->context;

        $data = [];
        if (trim($folder->intro)) {
            if ($folder->display == self::FOLDER_DISPLAY_INLINE && !empty($cm->showdescription)) {
                // for "display inline" do not filter, filters run at display time.
                $data['intro'] = ($this->intro)($folder, false);
            }
        }
        $buttons = [];
        // Display the "Edit" button if current user can edit folder contents.
        // Do not display it on the course page for the teachers because there
        // is an "Edit settings" option in the action menu with the same functionality.
        $canmanagefolderfiles = has_capability('mod/folder:managefiles', $context);
        $canmanagecourseactivities = has_capability('moodle/course:manageactivities', $context);
        if ($canmanagefolderfiles && ($folder->display != self::FOLDER_DISPLAY_INLINE || !$canmanagecourseactivities)) {
            $editbutton = new single_button(new moodle_url($this->here),
                get_string('edit'), 'post', single_button::BUTTON_PRIMARY);
            $editbutton->class = 'navitem';
            $data['edit_button'] = $editbutton->export_for_template($OUTPUT);
            $data['hasbuttons'] = true;
        }

        $downloadable = $this->folder_archive_available($folder, $cm);
        if ($downloadable) {
            $downloadbutton = new single_button(new moodle_url($this->here),
                get_string('downloadfolder', 'folder'), 'get');
            $downloadbutton->class = 'navitem ms-auto';
            $data['download_button'] = $downloadbutton->export_for_template($OUTPUT);
            $data['hasbuttons'] = true;
        }

        // folder_tree: the area laid out as directories.
        $foldertree = new stdClass();
        $foldertree->folder = $folder;
        $foldertree->cm = $cm;
        $foldertree->context = $context;
        $foldertree->dir = $this->fs->get_area_tree($context->id, 'mod_folder', 'content', 0);
        if ($folder->display == self::FOLDER_DISPLAY_INLINE) {
            // Display module name as the name of the root directory.
            $foldertree->dir['dirname'] = format_string($cm->name, true, ['context' => $context]);
        }

        $data['id'] = 'folder_tree'. ($treecounter++);
        $data['showexpanded'] = !empty($foldertree->folder->showexpanded);
        $data['dir'] = $this->renderable_tree_elements($foldertree, ['files' => [], 'subdirs' => [$foldertree->dir]]);

        return $OUTPUT->render_from_template('mod_folder/folder', $data);
    }

    /**
     * mod/folder/renderer.php renderable_tree_elements().
     *
     * @param stdClass $tree
     * @param array $dir
     * @return array
     */
    protected function renderable_tree_elements(stdClass $tree, array $dir): array {
        global $OUTPUT;
        if (empty($dir['subdirs']) && empty($dir['files'])) {
            return [];
        }
        $elements = [];
        foreach ($dir['subdirs'] as $subdir) {
            $htmllize = $this->renderable_tree_elements($tree, $subdir);
            $image = $OUTPUT->pix_icon(file_folder_icon(), $subdir['dirname'], 'moodle');
            $elements[] = [
                'name' => $subdir['dirname'],
                'icon' => $image,
                'subdirs' => $htmllize,
                'hassubdirs' => !empty($htmllize),
            ];
        }
        foreach ($dir['files'] as $file) {
            $filename = $file->get_filename();
            $filenamedisplay = clean_filename($filename);

            $url = moodle_url::make_pluginfile_url($file->get_contextid(), $file->get_component(),
                $file->get_filearea(), $file->get_itemid(), $file->get_filepath(), $filename, false);
            if (file_extension_in_typegroup($filename, 'web_image')) {
                $image = $url->out(false, ['preview' => 'tinyicon', 'oid' => $file->get_timemodified()]);
                $image = html_writer::empty_tag('img', ['src' => $image]);
            } else {
                $image = $OUTPUT->pix_icon(file_file_icon($file), $filenamedisplay, 'moodle');
            }

            if ($tree->folder->forcedownload) {
                $url->param('forcedownload', 1);
            }

            $elements[] = [
                'name' => $filenamedisplay,
                'icon' => $image,
                'url' => $url,
                'subdirs' => null,
                'hassubdirs' => false,
            ];
        }

        return $elements;
    }

    /**
     * mod/folder/lib.php folder_archive_available().
     *
     * @param stdClass $folder
     * @param stdClass $cm
     * @return bool
     */
    protected function folder_archive_available($folder, $cm) {
        if (!$folder->showdownloadfolder) {
            return false;
        }

        $context = $this->context;
        $dir = $this->fs->get_area_tree($context->id, 'mod_folder', 'content', 0);

        $size = $this->folder_get_directory_size($dir);
        $maxsize = $this->maxsizetodownload * 1024 * 1024;

        if ($size == 0) {
            return false;
        }

        if (!empty($maxsize) && $size > $maxsize) {
            return false;
        }

        return true;
    }

    /**
     * mod/folder/lib.php folder_get_directory_size().
     *
     * @param array $directory
     * @return int
     */
    protected function folder_get_directory_size($directory) {
        $size = 0;

        foreach ($directory['files'] as $file) {
            $size += $file->get_filesize();
        }

        foreach ($directory['subdirs'] as $subdirectory) {
            $size += $this->folder_get_directory_size($subdirectory);
        }

        return $size;
    }
}

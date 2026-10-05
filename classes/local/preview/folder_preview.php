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

namespace local_coursegen\local\preview;

use local_coursegen\local\preview\folder\view;

/**
 * A folder, drawn by mod_folder's own view code run against the payload.
 *
 * The files are the ones the activity's own result lists, each with the address
 * it is served from.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class folder_preview extends preview_base {
    /**
     * The module's short name.
     *
     * @return string
     */
    protected function modname(): string {
        return 'folder';
    }

    /**
     * The folder, as mod/folder/view.php draws it.
     *
     * @return string
     */
    public function render(): string {
        $folder = $this->instance();
        if ($folder === null) {
            return $this->nothing_yet();
        }
        $cm = $this->cm();
        $context = $this->context();
        $files = $this->files();
        $maxsizetodownload = get_config('folder', 'maxsizetodownload');
        $maxsizetodownload = (int) $maxsizetodownload;
        $here = $this->url_to();
        $introcallback = fn($activity, $filter = true) => $this->module_intro($activity, $filter);
        $view = new view($folder, $cm, $context, $files, $maxsizetodownload, $here, $introcallback);
        return $view->display_folder();
    }

    /**
     * This module reads its own page at the narrower width (mod/folder/view.php).
     *
     * @return bool
     */
    public function limited_width(): bool {
        return true;
    }
}

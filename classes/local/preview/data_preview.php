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

use local_coursegen\local\preview\data\view;

/**
 * A database, drawn by mod_data's own view code run against the payload.
 *
 * The fields of the database are rows of its own tree, the ones the AI wrote
 * included; entries are the readers' and there are none yet.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class data_preview extends preview_base {
    /**
     * The module's short name.
     *
     * @return string
     */
    protected function modname(): string {
        return 'data';
    }

    /**
     * The database page, as mod/data/view.php draws it.
     *
     * @return string
     */
    public function render(): string {
        $data = $this->instance();
        if ($data === null) {
            return $this->nothing_yet();
        }
        $course = $this->course();
        $cmid = $this->cmid;
        $modinfo = get_fast_modinfo($course);
        if (!$cmid || !isset($modinfo->cms[$cmid])) {
            return $this->nothing_yet();
        }
        $view = new view($data, $modinfo->get_cm($cmid), $this->context(), $this->store(), $this->url_to());
        return $view->page();
    }
}

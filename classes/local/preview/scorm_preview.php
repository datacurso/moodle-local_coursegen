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

use local_coursegen\local\preview\scorm\view;

/**
 * A SCORM package, drawn by mod_scorm's own view code run against the payload.
 *
 * The package is the one the activity's own result lists among its files.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scorm_preview extends preview_base {
    /**
     * The module's short name.
     *
     * @return string
     */
    protected function modname(): string {
        return 'scorm';
    }

    /**
     * The package's page, as mod/scorm/view.php draws it.
     *
     * @return string
     */
    public function render(): string {
        global $USER;
        $scorm = $this->instance();
        if ($scorm === null) {
            return $this->nothing_yet();
        }
        $view = new view($scorm, $this->cm(), $this->context(), $this->store(), $this->url_to(), $USER, $this->files());
        return $view->page();
    }

    /**
     * A package prints its description on its own page, not in the header (view.php).
     *
     * @return string
     */
    public function header_description(): string {
        return '';
    }
}

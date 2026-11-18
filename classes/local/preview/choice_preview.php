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

use local_coursegen\local\preview\choice\view;

/**
 * A choice, drawn by mod_choice's own view code run against the payload.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class choice_preview extends preview_base {
    /**
     * The module's short name.
     *
     * @return string
     */
    protected function modname(): string {
        return 'choice';
    }

    /**
     * The choice page, as mod/choice/view.php draws it.
     *
     * @return string
     */
    public function render(): string {
        $choice = $this->instance();
        if ($choice === null) {
            return $this->nothing_yet();
        }
        $cm = $this->cm();
        $course = $this->course();
        $context = $this->context();
        $store = $this->store();
        $here = $this->url_to();
        $view = new view($choice, $cm, $course, $context, $store, $here);
        return $view->page();
    }

    /**
     * This module reads its own page at the narrower width (mod/choice/view.php).
     *
     * @return bool
     */
    public function limited_width(): bool {
        return true;
    }
}

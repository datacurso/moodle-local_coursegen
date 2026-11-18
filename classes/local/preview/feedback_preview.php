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

use local_coursegen\local\preview\feedback\view;
use stdClass;

/**
 * A feedback, drawn by mod_feedback's own view code run against the payload.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class feedback_preview extends preview_base {
    /**
     * The module's short name.
     *
     * @return string
     */
    protected function modname(): string {
        return 'feedback';
    }

    /**
     * The feedback page, as mod/feedback/view.php draws it.
     *
     * @return string
     */
    public function render(): string {
        $feedback = $this->instance();
        if ($feedback === null) {
            return $this->nothing_yet();
        }
        $course = $this->course();
        $cmid = $this->cmid;
        $modinfo = get_fast_modinfo($course);
        if (!$cmid || !isset($modinfo->cms[$cmid])) {
            return $this->nothing_yet();
        }
        $view = new view(
            $feedback,
            $modinfo->get_cm($cmid),
            $course,
            $this->context(),
            $this->store(),
            $this->url_to(),
            [$this, 'intro_for']
        );
        return $view->page();
    }

    /**
     * module_intro(), exposed publicly so view can call it as a callable.
     *
     * @param stdClass $activity
     * @return string
     */
    public function intro_for(stdClass $activity): string {
        return $this->module_intro($activity);
    }

    /**
     * mod/feedback/view.php shows the description in its own box, not the header.
     *
     * @return string
     */
    public function header_description(): string {
        return '';
    }

    /**
     * This module reads its own page at the narrower width (mod/feedback/view.php).
     *
     * @return bool
     */
    public function limited_width(): bool {
        return true;
    }
}

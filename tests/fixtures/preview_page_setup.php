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

namespace local_coursegen;

/**
 * What a preview test sets up so the module's own view code can draw: a page with a context and a renderer.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait preview_page_setup {
    /**
     * Give the global page a context and its renderer, as the preview page script does.
     */
    protected function prepare_preview_page(): void {
        global $PAGE;
        $PAGE->set_url('/local/coursegen/activity_preview.php');
        $PAGE->set_context(\context_system::instance());
        $PAGE->initialise_theme_and_output();
    }

    /**
     * A preview of a finished activity opened at one of its pages, built from its parameters alone.
     *
     * @param string $modname
     * @param array $parameters The activity's own parameters, with the tree its result carries.
     * @param int $index The page to open.
     * @return \local_coursegen\local\preview\activity_preview
     */
    protected function preview_of(string $modname, array $parameters, int $index = 0) {
        $preview = \local_coursegen\local\preview\preview_factory::for_activity($modname, $parameters);
        $here = new \moodle_url('/local/coursegen/activity_preview.php', ['sessionid' => 'abc', 'uid' => 'u', 'page' => $index]);
        $preview->opened_at($here, $index);
        return $preview;
    }

    /**
     * The visible text of a rendered preview, on one line.
     *
     * @param string $html
     * @return string
     */
    protected function text_of(string $html): string {
        $stripped = strip_tags($html);
        return preg_replace('/\s+/', ' ', $stripped);
    }
}

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

/**
 * A text and media area, drawn the way the course page draws it.
 *
 * A label has no page of its own: mod/label/view.php sends the reader to the
 * course page, where the label's content is the row itself. That content is
 * what label_get_coursemodule_info() (mod/label/lib.php, Moodle 4.5) produces:
 * format_module_intro('label', $label, $cmid, false), filtered later by the
 * course page on output. Here it is formatted the same way, filters included,
 * from the label row the payload carries.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class label_preview extends preview_base {
    /**
     * The module's short name.
     *
     * @return string
     */
    protected function modname(): string {
        return 'label';
    }

    /**
     * A draft replaces the label's text.
     *
     * @param json_store $store
     */
    protected function overlay(json_store $store): void {
        $rows = $store->get_records('label');
        if (!$rows) {
            return;
        }
        $row = reset($rows);
        $intro = $this->parameters['introeditor'] ?? ($this->parameters['intro'] ?? null);
        if (is_array($intro)) {
            $intro = $intro['text'] ?? null;
        }
        if (is_string($intro) && trim($intro) !== '') {
            $store->set('label', $row->id, 'intro', $intro);
        }
    }

    /**
     * The label's content, as the course page shows it.
     *
     * @return string
     */
    public function render(): string {
        $label = $this->instance();
        if ($label === null) {
            return $this->nothing_yet();
        }
        // label_get_coursemodule_info() formats the intro without filters and
        // caches it; the course page then shows it through
        // cm_info::get_formatted_content(['overflowdiv' => true, 'noclean' => true])
        // (course/format/classes/output/local/content/cm.php:202), which
        // filters it. Both steps, in that order.
        $content = $this->module_intro($label, false);
        return format_text($content, FORMAT_HTML, ['overflowdiv' => true, 'noclean' => true, 'context' => $this->context()]);
    }

    /**
     * A label's text is its content, not a description of it.
     *
     * @return string
     */
    public function header_description(): string {
        return '';
    }
}

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

use local_coursegen\local\preview\imscp\view;

/**
 * An IMS content package, drawn by mod_imscp's own view code run against the payload.
 *
 * The package is the template's; a draft cannot upload one, so a planned
 * package shows the mould's table of contents.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class imscp_preview extends ported_preview {
    /**
     * The module's short name.
     *
     * @return string
     */
    protected function modname(): string {
        return 'imscp';
    }

    /**
     * A draft replaces the description.
     *
     * @param json_store $store
     */
    protected function overlay(json_store $store): void {
        $rows = $store->get_records('imscp');
        if (!$rows) {
            return;
        }
        $row = reset($rows);
        $intro = $this->parameters['introeditor'] ?? null;
        if (is_array($intro)) {
            $intro = $intro['text'] ?? null;
        }
        if (is_string($intro) && trim($intro) !== '') {
            $store->set('imscp', $row->id, 'intro', $intro);
        }
    }

    /**
     * The package's table of contents, as mod/imscp/view.php draws it.
     *
     * @return string
     */
    public function render(): string {
        global $OUTPUT;

        $imscp = $this->instance();
        if ($imscp === null) {
            return $this->nothing_yet();
        }
        // Verify imsmanifest was parsed properly: view.php sends the reader
        // back to the course with this message.
        if (empty($imscp->structure)) {
            return $OUTPUT->notification(get_string('deploymenterror', 'imscp'), 'error', false);
        }
        $view = new view($imscp, $this->cm(), $this->context());
        $view->require_page_assets();
        return $view->imscp_print_content();
    }
}

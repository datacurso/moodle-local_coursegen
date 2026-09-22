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

use local_coursegen\local\preview\url\view;

/**
 * A URL, drawn by mod_url's own view code run against the payload.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class url_preview extends ported_preview {
    /**
     * The module's short name.
     *
     * @return string
     */
    protected function modname(): string {
        return 'url';
    }

    /**
     * A draft may give the address and the description.
     *
     * @param json_store $store
     */
    protected function overlay(json_store $store): void {
        $rows = $store->get_records('url');
        if (!$rows) {
            return;
        }
        $row = reset($rows);
        if (!empty($this->parameters['externalurl'])) {
            $store->set('url', $row->id, 'externalurl', (string) $this->parameters['externalurl']);
        }
        $intro = $this->parameters['introeditor'] ?? null;
        if (is_array($intro) && !empty($intro['text'])) {
            $store->set('url', $row->id, 'intro', (string) $intro['text']);
        }
    }

    /**
     * The URL, as mod/url/view.php draws it for its display type.
     *
     * @return string
     */
    public function render(): string {
        $url = $this->instance();
        if ($url === null) {
            return $this->nothing_yet();
        }
        return view::display($url, $this->cm(), $this->course(), $this->context());
    }

    /**
     * The description, only when the URL is set to print it.
     *
     * @return string
     */
    public function header_description(): string {
        $url = $this->instance();
        if ($url === null) {
            return '';
        }
        return view::url_get_intro($url, $this->cm(), $this->context());
    }
}

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

use local_coursegen\local\preview\h5pactivity\view;

/**
 * An H5P activity, drawn by mod_h5pactivity's own view code run against the payload.
 *
 * The package is the mould's: an answer writes the description of an H5P
 * activity, not its content, so what plays is what the template carries.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class h5pactivity_preview extends preview_base {
    /**
     * The module's short name.
     *
     * @return string
     */
    protected function modname(): string {
        return 'h5pactivity';
    }

    /**
     * A draft's description replaces the mould's.
     *
     * @param json_store $store
     */
    protected function overlay(json_store $store): void {
        $rows = $store->get_records('h5pactivity');
        if (!$rows) {
            return;
        }
        $row = reset($rows);
        $value = $this->parameters['introeditor'] ?? $this->parameters['intro'] ?? null;

        if (is_array($value)) {
            $text = (string) ($value['text'] ?? '');
        } else {
            $text = (string) ($value ?? '');
        }

        if (trim($text) === '') {
            return;
        }

        $introformat = FORMAT_HTML;
        if (is_array($value)) {
            $introformat = (int) ($value['format'] ?? FORMAT_HTML);
        }

        $store->set('h5pactivity', $row->id, 'intro', $text);
        $store->set('h5pactivity', $row->id, 'introformat', $introformat);
    }

    /**
     * The activity's page, as mod/h5pactivity/view.php draws it.
     *
     * @return string
     */
    public function render(): string {
        $instance = $this->instance();
        if ($instance === null) {
            return $this->nothing_yet();
        }
        $context = $this->context();
        $filestorage = $this->files();
        // The package, as view.php finds it: the one file in the package area.
        $packagefiles = $filestorage->get_area_files($context->id, 'mod_h5pactivity', 'package', 0, 'id', false);
        $file = null;
        if ($packagefiles) {
            $file = reset($packagefiles);
        }
        $store = $this->store();
        $here = $this->url_to();
        $view = new view($instance, $context, $store, $file, $here);
        return $view->page();
    }
}

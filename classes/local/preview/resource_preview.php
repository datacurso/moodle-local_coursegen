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

use local_coursegen\local\preview\resource\view;

/**
 * A file, drawn by mod_resource's own view code run against the payload.
 *
 * The file is the template's, listed in the payload; a draft cannot upload
 * one, so a planned file shows the mould's.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resource_preview extends ported_preview {
    /** @var view|null */
    protected ?view $view = null;

    /**
     * The module's short name.
     *
     * @return string
     */
    protected function modname(): string {
        return 'resource';
    }

    /**
     * A draft replaces the description.
     *
     * @param json_store $store
     */
    protected function overlay(json_store $store): void {
        $rows = $store->get_records('resource');
        if (!$rows) {
            return;
        }
        $row = reset($rows);
        $intro = $this->parameters['introeditor'] ?? null;
        if (is_array($intro)) {
            $intro = $intro['text'] ?? null;
        }
        if (is_string($intro) && trim($intro) !== '') {
            $store->set('resource', $row->id, 'intro', $intro);
        }
    }

    /**
     * The module's view, built once.
     *
     * @return view|null
     */
    protected function view(): ?view {
        if ($this->view !== null) {
            return $this->view;
        }
        $resource = $this->instance();
        if ($resource === null) {
            return null;
        }
        $this->view = new view(
            $resource,
            $this->cm(),
            $this->course(),
            $this->context(),
            $this->files(),
            fn($activity) => $this->module_intro($activity)
        );
        return $this->view;
    }

    /**
     * The file, as mod/resource/view.php shows it.
     *
     * @return string
     */
    public function render(): string {
        $view = $this->view();
        if ($view === null) {
            return $this->nothing_yet();
        }
        return $view->page();
    }

    /**
     * mod_resource sets the header description itself, details included.
     *
     * @return string
     */
    public function header_description(): string {
        $view = $this->view();
        if ($view === null) {
            return '';
        }
        return $view->description();
    }
}

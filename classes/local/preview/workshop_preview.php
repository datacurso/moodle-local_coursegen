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

use local_coursegen\local\preview\workshop\view;

/**
 * A workshop, drawn by mod_workshop's own view code run against the payload.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class workshop_preview extends preview_base {
    /** @var int[] The phase an answer may ask the workshop to open in, by its token (workshop_settings). */
    private const PHASE_MAP = [
        'submission' => 20,
        'assessment' => 30,
        'evaluation' => 40,
    ];

    /**
     * The module's short name.
     *
     * @return string
     */
    protected function modname(): string {
        return 'workshop';
    }

    /**
     * The module's row, opened in the phase the result asks for.
     *
     * The phase is a setting of how the workshop starts, which the plugin
     * applies when it creates it, so the result carries it as a token of its
     * own rather than in the tree.
     *
     * @return \stdClass|null
     */
    protected function instance(): ?\stdClass {
        $row = parent::instance();
        if ($row === null) {
            return null;
        }
        $settings = $this->parameters['mod_settings'] ?? [];
        $token = $settings['initial_phase'] ?? null;
        if (is_string($token) && isset(self::PHASE_MAP[$token])) {
            $row->phase = self::PHASE_MAP[$token];
        }
        return $row;
    }

    /**
     * The workshop page, as mod/workshop/view.php draws it.
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
     * The heading with its help, as view.php puts it in the activity header.
     *
     * @return string
     */
    public function header_title(): string {
        $view = $this->view();
        return $view === null ? '' : $view->heading();
    }

    /**
     * A workshop shows its description on its own page, not in the header (view.php).
     *
     * @return string
     */
    public function header_description(): string {
        return '';
    }

    /**
     * The view, built over the payload's rows.
     *
     * @return view|null Null when the payload holds no workshop.
     */
    protected function view(): ?view {
        global $USER;
        $workshop = $this->instance();
        if ($workshop === null) {
            return null;
        }
        return new view($workshop, $this->cm(), $this->course(), $this->context(), $this->store(), $this->url_to(),
            (int) $USER->id);
    }
}

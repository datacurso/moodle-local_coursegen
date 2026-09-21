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

namespace local_coursegen\local\preview\workshop;

use moodle_url;
use single_button;
use workshop;

/**
 * The action buttons mod_workshop_renderer::view_page() opens with, kept
 * apart from view.php only because together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait workshop_action_buttons {
    /**
     * Ported from mod_workshop_renderer::render_action_buttons().
     *
     * The one button any phase has is the reader's own submission; whether
     * they have one is theirs, not the template's, so it is looked for in
     * rows the payload never carries and offered where the workshop allows.
     *
     * @return string
     */
    protected function render_action_buttons(): string {
        global $OUTPUT;
        $output = '';
        $workshop = $this->workshop;

        switch ($workshop->phase) {
            case workshop::PHASE_SUBMISSION:
                // Does the user have to assess examples before submitting their own work?
                $examplesmust = ($workshop->useexamples && $workshop->examplesmode == workshop::EXAMPLES_BEFORE_SUBMISSION);

                // Is the assessment of example submissions considered finished?
                $examplesdone = has_capability('mod/workshop:manageexamples', $this->context);

                if ($this->assessing_examples_allowed() && has_capability('mod/workshop:submit', $this->context) &&
                    !has_capability('mod/workshop:manageexamples', $this->context)) {
                    $examples = $this->get_examples();
                    $left = 0;
                    // Make sure the current user has all examples allocated.
                    foreach ($examples as $exampleid => $example) {
                        if (is_null($example->grade)) {
                            $left++;
                            break;
                        }
                    }
                    if ($left > 0 && $workshop->examplesmode != workshop::EXAMPLES_VOLUNTARY) {
                        $examplesdone = false;
                    } else {
                        $examplesdone = true;
                    }
                }

                if (has_capability('mod/workshop:submit', $this->context) && (!$examplesmust || $examplesdone)) {
                    if (!$this->get_submission_by_author($this->userid)) {
                        $btnurl = new moodle_url($this->submission_url(), ['edit' => 'on']);
                        $btntxt = get_string('createsubmission', 'workshop');
                        $output .= $OUTPUT->single_button($btnurl, $btntxt, 'get', ['type' => single_button::BUTTON_PRIMARY]);
                    }
                }
                break;

            case workshop::PHASE_ASSESSMENT:
                if (has_capability('mod/workshop:submit', $this->context)) {
                    if (!$this->get_submission_by_author($this->userid)) {
                        if ($this->creating_submission_allowed($this->userid)) {
                            $btnurl = new moodle_url($this->submission_url(), ['edit' => 'on']);
                            $btntxt = get_string('createsubmission', 'workshop');
                            $output .= $OUTPUT->single_button($btnurl, $btntxt, 'get',
                                ['type' => single_button::BUTTON_PRIMARY]);
                        }
                    }
                }
        }

        return $output;
    }
}

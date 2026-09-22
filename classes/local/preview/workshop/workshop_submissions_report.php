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
use workshop;

/**
 * Copied from mod_workshop_renderer::view_submissions_report(), the parts a
 * template carries. Each phase opens with what the workshop's author wrote
 * for it - the description and the example submissions while it is being
 * set up, the instructions for submitting, then for assessing, and the
 * conclusion once it is closed - and goes on to what the people in it have
 * done, which the payload never carries, so only the author's part is drawn
 * here. Kept apart from workshop_plan_render.php only because together they
 * crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait workshop_submissions_report {
    /**
     * The current phase's own report section.
     *
     * @return string
     */
    protected function view_submissions_report(): string {
        $method = $this->submissions_report_method($this->workshop->phase);
        if ($method === null) {
            return '';
        }
        return $this->$method();
    }

    /**
     * Which method draws the current phase's own report section, mapped
     * rather than switched on.
     *
     * @param mixed $phase One of workshop::PHASE_*.
     * @return string|null Null for a phase with nothing of its own to draw.
     */
    protected function submissions_report_method($phase): ?string {
        $methods = [
            workshop::PHASE_SETUP => 'view_submissions_report_setup',
            workshop::PHASE_SUBMISSION => 'view_submissions_report_submission',
            workshop::PHASE_ASSESSMENT => 'view_submissions_report_assessment',
            workshop::PHASE_CLOSED => 'view_submissions_report_closed',
        ];
        return $methods[$phase] ?? null;
    }

    /**
     * The setup phase's own part of view_submissions_report(): the
     * description and the example submissions.
     *
     * @return string
     */
    protected function view_submissions_report_setup(): string {
        global $OUTPUT;
        $workshop = $this->workshop;
        $output = '';

        if (trim($workshop->intro)) {
            $output .= print_collapsible_region_start('', 'workshop-viewlet-intro', get_string('introduction', 'workshop'),
                'workshop-viewlet-intro-collapsed', false, true);
            $output .= $OUTPUT->box($this->format_module_intro(), 'generalbox');
            $output .= print_collapsible_region_end(true);
        }
        if ($workshop->useexamples && has_capability('mod/workshop:manageexamples', $this->context)) {
            $output .= print_collapsible_region_start('', 'workshop-viewlet-allexamples',
                get_string('examplesubmissions', 'workshop'), 'workshop-viewlet-allexamples-collapsed', false, true);
            $output .= $OUTPUT->box_start('generalbox examples');
            if ($this->form_ready()) {
                if (!$examples = $this->get_examples_for_manager()) {
                    $output .= $OUTPUT->container(get_string('noexamples', 'workshop'), 'noexamples');
                }
                $aurl = new moodle_url($this->exsubmission_url(0), ['edit' => 'on']);
                $output .= $OUTPUT->single_button($aurl, get_string('exampleadd', 'workshop'), 'get');
            } else {
                $output .= $OUTPUT->container(get_string('noexamplesformready', 'workshop'));
            }
            $output .= $OUTPUT->box_end();
            $output .= print_collapsible_region_end(true);
        }
        return $output;
    }

    /**
     * The submission phase's own part of view_submissions_report(): the
     * instructions for submitting.
     *
     * @return string
     */
    protected function view_submissions_report_submission(): string {
        global $OUTPUT;
        $workshop = $this->workshop;
        $output = '';

        if (trim($workshop->instructauthors)) {
            $instructions = file_rewrite_pluginfile_urls($workshop->instructauthors,
                'pluginfile.php', $this->context->id,
                'mod_workshop', 'instructauthors', null, workshop::instruction_editors_options($this->context));
            $output .= print_collapsible_region_start('', 'workshop-viewlet-instructauthors',
                get_string('instructauthors', 'workshop'),
                'workshop-viewlet-instructauthors-collapsed', false, true);
            $output .= $OUTPUT->box(format_text($instructions, $workshop->instructauthorsformat, ['overflowdiv' => true]),
                ['generalbox', 'instructions']);
            $output .= print_collapsible_region_end(true);
        }
        return $output;
    }

    /**
     * The assessment phase's own part of view_submissions_report(): the
     * instructions for assessing.
     *
     * @return string
     */
    protected function view_submissions_report_assessment(): string {
        global $OUTPUT;
        $workshop = $this->workshop;
        $output = '';

        if (trim($workshop->instructreviewers)) {
            $instructions = file_rewrite_pluginfile_urls($workshop->instructreviewers,
                'pluginfile.php', $this->context->id,
                'mod_workshop', 'instructreviewers', null, workshop::instruction_editors_options($this->context));
            $output .= print_collapsible_region_start('', 'workshop-viewlet-instructreviewers',
                get_string('instructreviewers', 'workshop'),
                'workshop-viewlet-instructreviewers-collapsed', false, true);
            $output .= $OUTPUT->box(format_text($instructions, $workshop->instructreviewersformat,
                ['overflowdiv' => true]), ['generalbox', 'instructions']);
            $output .= print_collapsible_region_end(true);
        }
        return $output;
    }

    /**
     * The closed phase's own part of view_submissions_report(): the conclusion.
     *
     * @return string
     */
    protected function view_submissions_report_closed(): string {
        global $OUTPUT;
        $workshop = $this->workshop;
        $output = '';

        if (trim($workshop->conclusion)) {
            $conclusion = file_rewrite_pluginfile_urls($workshop->conclusion, 'pluginfile.php', $this->context->id,
                'mod_workshop', 'conclusion', null, workshop::instruction_editors_options($this->context));
            $output .= print_collapsible_region_start('', 'workshop-viewlet-conclusion',
                get_string('conclusion', 'workshop'),
                'workshop-viewlet-conclusion-collapsed', false, true);
            $output .= $OUTPUT->box(format_text($conclusion, $workshop->conclusionformat, ['overflowdiv' => true]),
                ['generalbox', 'conclusion']);
            $output .= print_collapsible_region_end(true);
        }
        return $output;
    }
}

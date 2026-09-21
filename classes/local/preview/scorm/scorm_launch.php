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

namespace local_coursegen\local\preview\scorm;

use html_writer;
use moodle_url;
use single_select;

/**
 * Ported from mod/scorm/locallib.php scorm_print_launch(), kept apart from
 * view.php only because together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait scorm_launch {
    /**
     * Ported from mod/scorm/locallib.php scorm_print_launch(), returning rather than echoing.
     *
     * @return string
     */
    protected function scorm_print_launch(): string {
        global $CFG, $OUTPUT;
        $scorm = $this->scorm;
        $output = '';

        $organization = optional_param('organization', '', PARAM_INT);

        if ($scorm->displaycoursestructure == 1) {
            $output .= $OUTPUT->box_start('generalbox boxaligncenter toc', 'toc');
            $output .= html_writer::div(get_string('contents', 'scorm'), 'structurehead');
        }
        if (empty($organization)) {
            $organization = $scorm->launch;
        }
        $orgs = [];
        foreach ($this->store->get_records('scorm_scoes', ['scorm' => $scorm->id], 'sortorder, id') as $org) {
            if ((string) ($org->launch ?? '') === '' && (string) ($org->organization ?? '') !== '') {
                $orgs[$org->id] = $org->title;
            }
        }
        if ($orgs) {
            if (count($orgs) > 1) {
                $select = new single_select(new moodle_url($this->here), 'organization', $orgs, $organization, null);
                $select->label = get_string('organizations', 'scorm');
                $select->class = 'scorm-center';
                $output .= $OUTPUT->render($select);
            }
        }
        $orgidentifier = '';
        if ($sco = $this->scorm_get_sco($organization, SCO_ONLY)) {
            if (($sco->organization == '') && ($sco->launch == '')) {
                $orgidentifier = $sco->identifier;
            } else {
                $orgidentifier = $sco->organization;
            }
        }

        $scorm->version = strtolower(clean_param($scorm->version, PARAM_SAFEDIR));   // Just to be safe.
        if (!file_exists($CFG->dirroot.'/mod/scorm/datamodels/'.$scorm->version.'lib.php')) {
            $scorm->version = 'scorm_12';
        }
        require_once($CFG->dirroot.'/mod/scorm/datamodels/'.$scorm->version.'lib.php');

        $result = $this->scorm_get_toc(TOCFULLURL, $orgidentifier);
        $incomplete = $result->incomplete;
        // Get latest incomplete sco to launch first if force new attempt isn't set to always.
        if (!empty($result->sco->id) && $scorm->forcenewattempt != SCORM_FORCEATTEMPT_ALWAYS) {
            $launchsco = $result->sco->id;
        } else {
            // Use launch defined by SCORM package.
            $launchsco = $scorm->launch;
        }

        // Do we want the TOC to be displayed?
        if ($scorm->displaycoursestructure == 1) {
            $output .= $result->toc;
            $output .= $OUTPUT->box_end();
        }

        // Is this the first attempt ?
        $attemptcount = $this->scorm_get_attempt_count();

        // The real page ends with a form that previews or enters the package
        // through the player, which records an attempt. Nobody may act on an
        // activity that does not exist, so nothing enters it. Previewing is
        // seeing, though, and the package's own pages are in the payload: the
        // preview button opens the page the package launches with, as a page,
        // in its own tab.
        if ($scorm->hidebrowse == 0) {
            $output .= html_writer::start_div('scorm-center');
            $output .= $this->browse_link($launchsco);
            $output .= html_writer::end_div();
        }
        return $output;
    }
}

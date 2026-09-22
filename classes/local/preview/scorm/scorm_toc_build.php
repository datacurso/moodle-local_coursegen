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

use stdClass;

/**
 * Builds the table of contents data from the payload's learning objects, kept
 * apart from view.php only because together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait scorm_toc_build {
    /**
     * Ported from scorm_get_toc(), for the view page's call: full links, no player, no header.
     *
     * @param int $toclink
     * @param string $currentorg
     * @return stdClass
     */
    protected function scorm_get_toc(int $toclink = TOCJSLINK, string $currentorg = ''): stdClass {
        $scorm = $this->scorm;
        $attempt = $this->scorm_get_last_attempt();
        $result = new stdClass();
        $organizationsco = null;

        if (!empty($currentorg)) {
            $organizationsco = $this->store->get_record('scorm_scoes', ['scorm' => $scorm->id, 'identifier' => $currentorg]);
        }

        $scoes = $this->scorm_get_toc_object($currentorg, '', 'normal', $attempt, false, $organizationsco);

        $treeview = $this->scorm_format_toc_for_treeview($scoes['scoes'][0]->children ?? [], $scoes['usertracks'],
            $toclink, $currentorg, $attempt, false, $organizationsco, false);

        $result->toc = $treeview->toc;

        $scoid = '';
        if (!empty($scoes['scoid'])) {
            $scoid = $scoes['scoid'];
        }

        if (empty($scoid)) {
            // If this is a normal package with an org sco and child scos get the first child.
            if (!empty($scoes['scoes'][0]->children)) {
                $result->sco = $scoes['scoes'][0]->children[0];
            } else { // This package only has one sco - it may be a simple external AICC package.
                $result->sco = $scoes['scoes'][0] ?? new stdClass();
            }
        } else {
            $result->sco = $this->scorm_get_sco($scoid);
        }

        $result->prerequisites = $treeview->prerequisites;
        $result->incomplete = $treeview->incomplete;
        $result->attemptleft = $treeview->attemptleft;

        return $result;
    }

    /**
     * Ported from scorm_get_toc_object(): every object as not attempted, because no tracks are carried.
     *
     * @param string $currentorg
     * @param string $scoid
     * @param string $mode
     * @param string|int $attempt
     * @param bool $play
     * @param stdClass|null $organizationsco
     * @return array
     */
    protected function scorm_get_toc_object(string $currentorg = '', $scoid = '', string $mode = 'normal', $attempt = '',
            bool $play = false, ?stdClass $organizationsco = null): array {
        global $OUTPUT;
        $scorm = $this->scorm;

        // Always pass the mode even if empty as that is what is done elsewhere and the urls have to match.
        $modestr = '&mode=';
        if ($mode != 'normal') {
            $modestr = '&mode='.$mode;
        }

        $result = [];
        $incomplete = false;

        if (!empty($organizationsco)) {
            $result[0] = $organizationsco;
            $result[0]->isvisible = 'true';
            $result[0]->statusicon = '';
            $result[0]->url = '';
        }

        $usertracks = [];
        if ($scoes = $this->scorm_get_scoes($currentorg)) {
            // The reader's tracks would be read here for each learning object; none are carried.
            foreach ($scoes as $sco) {
                if (!isset($sco->isvisible)) {
                    $sco->isvisible = 'true';
                }

                if (empty($sco->title)) {
                    $sco->title = $sco->identifier;
                }

                if (scorm_version_check($scorm->version, SCORM_13)) {
                    $sco->prereq = true;
                } else {
                    $sco->prereq = empty($sco->prerequisites) || scorm_eval_prerequisites($sco->prerequisites, $usertracks);
                }

                $statusicon = '';
                if ($sco->isvisible === 'true') {
                    if (!empty($sco->launch)) {
                        // Set first sco to launch if in browse/review mode.
                        if (empty($scoid) && ($mode != 'normal')) {
                            $scoid = $sco->id;
                        }
                        if (empty($scoid)) {
                            $scoid = $sco->id;
                        }
                        $incomplete = true;
                        if ($sco->scormtype == 'sco') {
                            $statusicon = $OUTPUT->pix_icon('notattempted', get_string('notattempted', 'scorm'), 'scorm');
                        } else {
                            $statusicon = $OUTPUT->pix_icon('asset', get_string('asset', 'scorm'), 'scorm');
                        }
                    }
                }

                if (empty($statusicon)) {
                    $sco->statusicon = $OUTPUT->pix_icon('notattempted', get_string('notattempted', 'scorm'), 'scorm');
                } else {
                    $sco->statusicon = $statusicon;
                }

                $sco->url = 'a='.$scorm->id.'&scoid='.$sco->id.'&currentorg='.$currentorg.$modestr.'&attempt='.$attempt;
                $sco->incomplete = $incomplete;

                if (!in_array($sco->id, array_keys($result))) {
                    $result[$sco->id] = $sco;
                }
            }
        }

        // Get the parent scoes!
        $result = $result ? scorm_get_toc_get_parent_child($result, $currentorg) : [];

        // Be safe, prevent warnings from showing up while returning array.
        if (!isset($scoid)) {
            $scoid = '';
        }

        return ['scoes' => $result, 'usertracks' => $usertracks, 'scoid' => $scoid];
    }
}

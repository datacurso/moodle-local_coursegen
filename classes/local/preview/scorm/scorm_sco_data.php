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
 * The package's learning objects, read from the store, kept apart from
 * view.php only because together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait scorm_sco_data {
    /**
     * Copied from scorm_get_sco(), over the store.
     *
     * @param int|string $id
     * @param int $what
     * @return stdClass|false
     */
    protected function scorm_get_sco($id, $what = SCO_ALL) {
        if ($sco = $this->store->get_record('scorm_scoes', ['id' => $id])) {
            $sco = ($what == SCO_DATA) ? new stdClass() : $sco;
            if (($what != SCO_ONLY) && ($scodatas = $this->store->get_records('scorm_scoes_data', ['scoid' => $id]))) {
                foreach ($scodatas as $scodata) {
                    $sco->{$scodata->name} = $scodata->value;
                }
            } else if (($what != SCO_ONLY) && (!($scodatas = $this->store->get_records('scorm_scoes_data', ['scoid' => $id])))) {
                $sco->parameters = '';
            }
            return $sco;
        } else {
            return false;
        }
    }

    /**
     * Copied from scorm_get_scoes(), over the store.
     *
     * @param string|false $organisation
     * @return array|false
     */
    protected function scorm_get_scoes($organisation = false) {
        $queryarray = ['scorm' => $this->scorm->id];
        if (!empty($organisation)) {
            $queryarray['organization'] = $organisation;
        }
        if ($scoes = $this->store->get_records('scorm_scoes', $queryarray, 'sortorder, id')) {
            // Drop keys so that it is a simple array as expected.
            $scoes = array_values($scoes);
            foreach ($scoes as $sco) {
                if ($scodatas = $this->store->get_records('scorm_scoes_data', ['scoid' => $sco->id])) {
                    foreach ($scodatas as $scodata) {
                        $sco->{$scodata->name} = $scodata->value;
                    }
                }
            }
            return $scoes;
        } else {
            return false;
        }
    }
}

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
        $sco = $this->store->get_record('scorm_scoes', ['id' => $id]);
        if (!$sco) {
            return false;
        }
        if ($what == SCO_DATA) {
            $sco = new stdClass();
        }
        if ($what != SCO_ONLY) {
            $scodatas = $this->store->get_records('scorm_scoes_data', ['scoid' => $id]);
            if ($scodatas) {
                self::apply_sco_data($sco, $scodatas);
            } else {
                $sco->parameters = '';
            }
        }
        return $sco;
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
                $scodatas = $this->store->get_records('scorm_scoes_data', ['scoid' => $sco->id]);
                if ($scodatas) {
                    self::apply_sco_data($sco, $scodatas);
                }
            }
            return $scoes;
        } else {
            return false;
        }
    }

    /**
     * Copy a learning object's own data rows onto its record.
     *
     * Shared by scorm_get_sco() and scorm_get_scoes(), which both read the
     * same scorm_scoes_data rows and fold them onto the sco the same way.
     *
     * @param stdClass $sco
     * @param iterable $scodatas
     */
    private static function apply_sco_data(stdClass $sco, iterable $scodatas): void {
        foreach ($scodatas as $scodata) {
            $sco->{$scodata->name} = $scodata->value;
        }
    }
}

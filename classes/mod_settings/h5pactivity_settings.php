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

namespace local_coursegen\mod_settings;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/gradelib.php');

/**
 * Class h5pactivity_settings
 *
 * Post-creation settings for AI generated H5P activities. The package fields
 * (file_path/file_name) are consumed upstream by the parameters handler; this
 * class applies the remaining supported settings and reports any unconsumed
 * key as developer debugging instead of silently discarding it.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class h5pactivity_settings extends base_settings {
    /**
     * @var string[] Keys consumed by this handler or upstream by the parameters handler.
     *
     * Two package flows reach this type, and each one owns its own keys:
     * file_path/file_name for the model-driven activity, whose package the
     * service writes and the plugin downloads, and mold_source_cmid plus the
     * two filled texts for a mold, whose package the plugin rebuilds out of the
     * mold's own .h5p (see \local_coursegen\mod_parameters\h5pactivity_parameters).
     */
    private const CONSUMED_KEYS = [
        'file_path', 'file_name', 'passing_score',
        'mold_source_cmid', 'content_json', 'h5p_json',
    ];

    /**
     * Add specific settings for the H5P activity module.
     */
    public function add_settings() {
        $this->apply_passing_score();
        $this->report_unconsumed_settings();
    }

    /**
     * Apply the passing_score setting to the activity grade item.
     *
     * The creation flow already maps the result gradepass through the module
     * form, so the value is only applied when the grade item does not carry a
     * passing grade yet (idempotent with add_moduleinfo()).
     *
     * @return void
     */
    private function apply_passing_score(): void {
        $passingscore = $this->modsettings['passing_score'] ?? null;
        if (!is_numeric($passingscore)) {
            return;
        }

        $gradeitem = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'h5pactivity',
            'iteminstance' => $this->cm->instance,
            'courseid' => $this->cm->course,
        ]);
        if (!$gradeitem) {
            return;
        }

        if ((float) $gradeitem->gradepass > 0) {
            // Already applied through the module parameters; keep it.
            return;
        }

        $gradeitem->gradepass = (float) $passingscore;
        $gradeitem->update('local_coursegen');
    }

    /**
     * Report unconsumed mod_settings keys as developer debugging.
     *
     * @return void
     */
    private function report_unconsumed_settings(): void {
        $unconsumed = array_diff(array_keys($this->modsettings), self::CONSUMED_KEYS);
        if (empty($unconsumed)) {
            return;
        }

        debugging(
            'local_coursegen: unconsumed h5pactivity mod_settings keys: ' . implode(', ', $unconsumed),
            DEBUG_DEVELOPER
        );
    }
}

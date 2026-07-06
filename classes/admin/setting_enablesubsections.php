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

namespace local_coursegen\admin;

use admin_setting_configcheckbox;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

/**
 * Checkbox for the AI subsections feature, validated against mod_subsection.
 *
 * Enabling the feature while the Subsection activity module is disabled would
 * silently flatten every generated subsection. Instead of returning a
 * validation error — which re-renders the form without redirecting, leaving
 * the checkbox ticked and re-posting on every reload — the value is
 * force-saved as DISABLED and the reason surfaces as an error notification
 * after the normal redirect.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_enablesubsections extends admin_setting_configcheckbox {
    /**
     * Save the setting, forcing it off when mod_subsection is disabled.
     *
     * @param mixed $data Submitted value ('0'/'1').
     * @return string Empty string on success, error message otherwise.
     */
    public function write_setting($data) {
        if ((string)$data === (string)$this->yes) {
            $enabledmods = \core_plugin_manager::instance()->get_enabled_plugins('mod');
            if (!array_key_exists('subsection', $enabledmods)) {
                \core\notification::error(
                    get_string('enablesubsections_error_moddisabled', 'local_coursegen')
                );
                return parent::write_setting($this->no);
            }
        }
        return parent::write_setting($data);
    }
}

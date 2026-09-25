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

namespace local_coursegen\event;

/**
 * Event fired when an AI generated package could not be downloaded and the module was created without it.
 *
 * Unlike generation_failed this is not a failure of the whole flow: the
 * activity exists but is missing its package (SCORM/IMS CP zip, resource
 * file, folder file). Carries the module name, the file name and a short
 * reason so site administrators can spot it in the logs.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class package_download_skipped extends \core\event\base {
    /** @var int Maximum length of the stored reason. */
    const REASON_MAX_LENGTH = 255;

    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Returns localised general event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_package_download_skipped', 'local_coursegen');
    }

    /**
     * Returns non-localised description of what happened.
     *
     * @return string
     */
    public function get_description() {
        $modname = $this->other['modname'] ?? '';
        $filename = $this->other['filename'] ?? '';
        $reason = $this->other['reason'] ?? '';
        return "The AI generated package '$filename' for a '$modname' module created by the user with id "
            . "'$this->userid' was skipped: $reason";
    }

    /**
     * Custom validation.
     *
     * @throws \coding_exception
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        foreach (['modname', 'filename', 'reason'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The '$key' value must be set in other.");
            }
        }
    }
}

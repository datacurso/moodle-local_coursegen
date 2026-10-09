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
 * Event fired when a file is sent to the external Datacurso course service.
 *
 * The payload is deliberately non-identifying: the original file name is
 * personal data (it routinely carries the learner or author name) and would
 * outlive the file itself in {logstore_standard_log}, so only the extension,
 * the size and the opaque generation thread/session identifiers are stored.
 * The file content is never carried either.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class external_transfer_initiated extends \core\event\base {
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
        return get_string('event_external_transfer_initiated', 'local_coursegen');
    }

    /**
     * Returns non-localised description of what happened.
     *
     * No user-supplied text is interpolated: the extension comes from the
     * server-side allow list and the thread id is minted by the AI backend.
     *
     * @return string
     */
    public function get_description() {
        $extension = $this->other['fileextension'] ?? '';
        $filesize = (int)($this->other['filesize'] ?? 0);
        $threadid = $this->other['threadid'] ?? '';

        return "The user with id '$this->userid' sent a '$extension' file of $filesize bytes "
            . "to the external Datacurso course service for the generation thread '$threadid'.";
    }

    /**
     * This event stores no restorable ids.
     *
     * The payload holds an extension, a byte count and identifiers minted by
     * the external service, none of which map to a Moodle record, so the
     * restore mapping is explicitly empty.
     *
     * @return bool False: nothing in 'other' can be mapped on restore.
     */
    public static function get_other_mapping() {
        return false;
    }
}

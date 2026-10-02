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

namespace local_coursegen\local\reference;

/**
 * Takes the file a teacher uploads for one slot of a template, or refuses it.
 *
 * Everything the browser says about the file is distrusted: the slot must
 * exist in the template, the size is the one of the content on disk and must
 * fit the site's limit, the name is made safe, and the type must be one the
 * place of the slot accepts.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reference_file_upload {
    /**
     * Keep an uploaded file in a slot of a template.
     *
     * @param int $userid The teacher.
     * @param int $templateid
     * @param string $slotkey
     * @param array $upload The entry of $_FILES: name, tmp_name and error.
     * @return string The name the file is kept under.
     * @throws \moodle_exception When the slot, the upload, the size or the type is not acceptable.
     */
    public static function accept(int $userid, int $templateid, string $slotkey, array $upload): string {
        $slot = self::slot_of($templateid, $slotkey);
        self::ensure_received((int) $upload['error']);
        $pathname = (string) $upload['tmp_name'];
        self::ensure_size($pathname);
        $filename = self::safe_name((string) $upload['name']);
        if (!reference_file_policy::accepts($slot->kind, $filename)) {
            throw new \moodle_exception('referencefiletype', 'local_coursegen');
        }
        reference_file_storage::stage($userid, $templateid, $slotkey, $filename, $pathname);
        return $filename;
    }

    /**
     * Empty a slot of a template.
     *
     * @param int $userid The teacher.
     * @param int $templateid
     * @param string $slotkey
     * @throws \moodle_exception When the template has no such slot.
     */
    public static function discard(int $userid, int $templateid, string $slotkey): void {
        self::slot_of($templateid, $slotkey);
        reference_file_storage::remove($userid, $templateid, $slotkey);
    }

    /**
     * The slot of a template with this name.
     *
     * @param int $templateid
     * @param string $slotkey
     * @return reference_slot
     * @throws \moodle_exception When the template has no such slot.
     */
    public static function slot_of(int $templateid, string $slotkey): reference_slot {
        $slots = reference_slot_scanner::for_template($templateid);
        foreach ($slots as $slot) {
            if ($slot->key() === $slotkey) {
                return $slot;
            }
        }
        throw new \moodle_exception('referenceslotunknown', 'local_coursegen');
    }

    /**
     * Refuse an upload that did not arrive whole.
     *
     * @param int $error The upload error code.
     * @throws \moodle_exception
     */
    private static function ensure_received(int $error): void {
        if ($error === UPLOAD_ERR_OK) {
            return;
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            $largest = self::largest_size();
            throw new \moodle_exception('referencefiletoolarge', 'local_coursegen', '', $largest);
        }
        throw new \moodle_exception('referencefileupload', 'local_coursegen', '', $error);
    }

    /**
     * Refuse a file that is empty or larger than the site allows.
     *
     * @param string $pathname
     * @throws \moodle_exception
     */
    private static function ensure_size(string $pathname): void {
        $size = filesize($pathname);
        if (!$size) {
            throw new \moodle_exception('referencefileempty', 'local_coursegen');
        }
        $limit = self::largest_bytes();
        if ($size > $limit) {
            $largest = self::largest_size();
            throw new \moodle_exception('referencefiletoolarge', 'local_coursegen', '', $largest);
        }
    }

    /**
     * The name of an uploaded file with nothing in it that could reach another directory.
     *
     * @param string $name
     * @return string
     * @throws \moodle_exception When nothing is left of the name.
     */
    private static function safe_name(string $name): string {
        $clean = clean_param($name, PARAM_FILE);
        $clean = trim($clean);
        if ($clean === '' || $clean === '.') {
            throw new \moodle_exception('referencefilename', 'local_coursegen');
        }
        return $clean;
    }

    /**
     * The largest file the site accepts, in bytes.
     *
     * @return int
     */
    private static function largest_bytes(): int {
        global $CFG;
        return get_max_upload_file_size($CFG->maxbytes);
    }

    /**
     * The largest file the site accepts, as the teacher reads it.
     *
     * @return string
     */
    private static function largest_size(): string {
        $bytes = self::largest_bytes();
        return display_size($bytes);
    }
}

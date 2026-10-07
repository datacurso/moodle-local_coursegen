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

namespace local_coursegen\local\service;

/**
 * Names the elements of an init payload.
 *
 * A section is named by a random uid drawn once per export. An activity is named by the opaque uid saved
 * with it in its template, which is never the number of its course module; the AI service echoes the
 * uids back in its answers.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_export_uids {
    /**
     * A fresh random uid for one element of one export.
     *
     * @return string A lowercase hexadecimal string, for example "9f2c41d7a0b3".
     */
    public static function random_uid(): string {
        $bytes = random_bytes(6);
        return bin2hex($bytes);
    }

    /**
     * A fresh opaque uid for an activity that is saved in a template.
     *
     * It is a version 4 UUID, which makes a clash across templates and sites practically impossible.
     *
     * @return string A UUID, for example "3f2a9c1e-77b4-4e0a-9d21-5c8f1b2e7a90".
     */
    public static function new_item_uid(): string {
        return \core\uuid::generate();
    }

    /**
     * The uid of an activity the template has no saved row for, because it was added to the course later.
     *
     * It is derived, so the same activity of the same template always gets the same one.
     *
     * @param int $templateid Template id, for example 3.
     * @param int $cmid Course module id of the activity, for example 11342.
     * @return string A lowercase hexadecimal string of 32 characters.
     */
    public static function stand_in_uid(int $templateid, int $cmid): string {
        $hash = hash('sha256', $templateid . ':' . $cmid);
        return substr($hash, 0, 32);
    }
}

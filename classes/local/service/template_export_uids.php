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
 * Every element of a payload is named by a random uid, drawn once per export and echoed back
 * by the AI service's answers.
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
}

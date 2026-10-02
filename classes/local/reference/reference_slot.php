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
 * One place of a template where the teacher may bring a file of their own.
 *
 * It is named by the template activity that holds its marker and the order of
 * the marker in that activity, so the name does not change between one export
 * of the template and the next.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reference_slot {
    /**
     * Constructor.
     *
     * @param int $cmid The template activity that holds the marker.
     * @param int $ordinal The order of the marker in that activity, from 1.
     * @param string $activityname The name of that activity.
     * @param string $instruction What the marker asks for.
     * @param string $kind One of the reference_file_policy::KIND_* constants.
     */
    public function __construct(
        /** @var int The template activity that holds the marker. */
        public readonly int $cmid,
        /** @var int The order of the marker in that activity, from 1. */
        public readonly int $ordinal,
        /** @var string The name of that activity. */
        public readonly string $activityname,
        /** @var string What the marker asks for. */
        public readonly string $instruction,
        /** @var string One of the reference_file_policy::KIND_* constants. */
        public readonly string $kind
    ) {
    }

    /**
     * The name of a slot: its activity and its order in that activity.
     *
     * @param int $cmid
     * @param int $ordinal
     * @return string
     */
    public static function key_of(int $cmid, int $ordinal): string {
        return $cmid . '.' . $ordinal;
    }

    /**
     * The name of this slot.
     *
     * @return string
     */
    public function key(): string {
        return self::key_of($this->cmid, $this->ordinal);
    }
}

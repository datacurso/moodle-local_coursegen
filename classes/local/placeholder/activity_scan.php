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

namespace local_coursegen\local\placeholder;

/**
 * What the scan of one activity found: where its placeholders are and what is wrong with its markers.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class activity_scan {
    /**
     * Keep the findings of one activity.
     *
     * @param int $cmid Course module id, e.g. 11342.
     * @param string $modname Module type, e.g. 'page'.
     * @param array[] $fields One entry per field that holds placeholders: {field, recordid, placeholders}.
     * @param string[] $problems Malformed markers found in any field of the activity.
     * @param int $hidden Markers inside a comment, a script or a style.
     */
    public function __construct(
        /** @var int Course module id. */
        public readonly int $cmid,
        /** @var string Module type. */
        public readonly string $modname,
        /** @var array[] One entry per field that holds placeholders. */
        public readonly array $fields,
        /** @var string[] Malformed markers found in any field of the activity. */
        public readonly array $problems,
        /** @var int Markers inside a comment, a script or a style. */
        public readonly int $hidden
    ) {
    }

    /**
     * How many placeholders the whole activity holds.
     *
     * @return int
     */
    public function placeholders(): int {
        $total = 0;
        foreach ($this->fields as $field) {
            $total += $field['placeholders'];
        }
        return $total;
    }

    /**
     * Whether any field of the activity holds a well formed placeholder.
     *
     * @return bool
     */
    public function has_placeholders(): bool {
        $total = $this->placeholders();
        return $total > 0;
    }
}

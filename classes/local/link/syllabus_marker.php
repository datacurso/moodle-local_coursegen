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

namespace local_coursegen\local\link;

/**
 * The marker a template author writes as the address of a link that must open the syllabus the professor uploads.
 *
 * The author writes it like the other markers: `<a href="[[coursegen:syllabus]]">Programa</a>`. Before the template
 * is sent to the AI service, the link becomes the one syllabus_link knows (`href="#" data-coursegen-syllabus="1"`),
 * so the service never sees a marker it does not read, and once the course exists the link opens the uploaded
 * syllabus (or is taken out when none was uploaded).
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class syllabus_marker {
    /** @var string The address of a link written as the marker, plain or as an editor encodes the brackets. */
    private const HREF_PATTERN = '~\bhref=(["\'])\s*(?:\[\[|%5B%5B)\s*coursegen:syllabus\s*(?:\]\]|%5D%5D)\s*\1~i';

    /**
     * Turn every link written with the marker into a link marked for the syllabus, in a text or a whole structure.
     *
     * @param mixed $value A text, or an activity structure (arrays of texts and values, at any depth).
     * @return mixed The same value with its links converted.
     */
    public static function to_link_mark(mixed $value): mixed {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::to_link_mark($item);
            }
            return $value;
        }
        if (!is_string($value) || stripos($value, 'coursegen:syllabus') === false) {
            return $value;
        }
        return preg_replace(self::HREF_PATTERN, 'href="#" data-coursegen-syllabus="1"', $value) ?? $value;
    }
}

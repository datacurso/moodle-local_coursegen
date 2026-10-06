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
 * Tells whether a course can be generated from a template yet.
 *
 * The templates now say only what to keep and what the AI modifies, and the AI service
 * that acts on them is being rebuilt. Until it is, starting a generation is refused
 * with a clear message instead of a failure of the service.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_generation_gate {
    /** @var bool Whether the AI service can run a generation from a template. */
    public const OPEN = false;

    /**
     * Refuse the start of a generation while the service cannot run it.
     *
     * @throws \moodle_exception While the gate is closed.
     */
    public static function assert_open(): void {
        if (!self::OPEN) {
            throw new \moodle_exception('templategenerationrebuilding', 'local_coursegen');
        }
    }
}

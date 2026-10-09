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

namespace local_coursegen\local\streaming;

/**
 * Kinds of generation stream the plugin relays to the browser.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class stream_type {
    /** @var string Course planning and generation stream. */
    public const COURSE = 'course';

    /** @var string Activity generation stream. */
    public const ACTIVITY = 'activity';

    /** @var string Course-from-template generation stream. */
    public const TEMPLATE = 'template';

    /**
     * Whether a value names a relayable stream.
     *
     * @param string $streamtype Value to check.
     * @return bool
     */
    public static function is_valid(string $streamtype): bool {
        return $streamtype === self::COURSE || $streamtype === self::ACTIVITY || $streamtype === self::TEMPLATE;
    }
}

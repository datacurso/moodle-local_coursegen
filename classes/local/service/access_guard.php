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
 * Capability checks that need more than one capability.
 *
 * Moodle has require_capability() for one capability and
 * require_all_capabilities() for several that are all needed, but nothing for
 * "any one of these is enough", which is what a screen that serves two actions
 * needs.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class access_guard {
    /**
     * Require at least one of the capabilities.
     *
     * The exception names the first capability of the list, which is the one
     * to grant to get in.
     *
     * @param string[] $capabilities The capabilities, any of which is enough.
     * @param \context $context Where they are checked.
     * @throws \required_capability_exception When the user has none of them.
     */
    public static function require_any(array $capabilities, \context $context): void {
        if (has_any_capability($capabilities, $context)) {
            return;
        }
        $first = reset($capabilities);
        throw new \required_capability_exception($context, $first, 'nopermissions', '');
    }
}

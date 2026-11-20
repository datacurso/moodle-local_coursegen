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

namespace local_coursegen;

/**
 * Skips a test that needs the flexsections course format when the site does not have it.
 *
 * The plain Moodle sites the CI builds have no format_flexsections: asking the course generator for that format
 * there silently gives a topics course, which has no parent for its sections, so the test could only fail.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait flexsections_required_trait {
    /**
     * Skip the running test when format_flexsections is not installed.
     */
    protected function require_flexsections(): void {
        if (!array_key_exists('flexsections', \core_component::get_plugin_list('format'))) {
            $this->markTestSkipped('format_flexsections is not installed.');
        }
    }
}

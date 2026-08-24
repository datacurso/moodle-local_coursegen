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

use local_coursegen\local\h5p_core_api;

/**
 * Tests for the site H5P framework (core API) version resolution.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\h5p_core_api
 */
final class h5p_core_api_test extends \advanced_testcase {
    /**
     * MDL-UNIT-002: The site H5P framework version resolves as major.minor for
     * the course payload.
     */
    public function test_version_resolves_as_major_and_minor(): void {
        $this->resetAfterTest();

        // Resolve the expected version the same way production code does. The
        // property is public static on the H5P library class.
        (new \core_h5p\factory())->get_core();
        $coreapi = \core_h5p\core::$coreApi; // phpcs:ignore moodle.NamingConventions.ValidVariableName
        $expected = $coreapi['majorVersion'] . '.' . $coreapi['minorVersion'];

        $resolved = h5p_core_api::resolve();

        $this->assertSame($expected, $resolved);
        $this->assertMatchesRegularExpression('/^\d+\.\d+$/', $resolved);
    }

    /**
     * MDL-UNIT-002: When the version cannot be resolved the helper reports null,
     * so the payload field is omitted instead of travelling empty.
     */
    public function test_unresolvable_version_reported_as_null(): void {
        $this->resetAfterTest();

        (new \core_h5p\factory())->get_core();
        $original = \core_h5p\core::$coreApi; // phpcs:ignore moodle.NamingConventions.ValidVariableName

        try {
            \core_h5p\core::$coreApi = []; // phpcs:ignore moodle.NamingConventions.ValidVariableName
            $this->assertNull(h5p_core_api::resolve());
        } finally {
            \core_h5p\core::$coreApi = $original; // phpcs:ignore moodle.NamingConventions.ValidVariableName
        }
    }
}

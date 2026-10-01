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
use local_coursegen\local\service\filetype_catalog_service;
use local_coursegen\local\warning_collector;

/**
 * The payload lookups (H5P core API version, file-type groups) fall back to null and record a
 * warning when they fail, instead of swallowing the failure into developer debugging.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\h5p_core_api
 * @covers     \local_coursegen\local\service\filetype_catalog_service
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\local\h5p_core_api::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\local\service\filetype_catalog_service::class)]
final class lookup_warnings_test extends \advanced_testcase {
    /**
     * Start clean.
     */
    protected function setUp(): void {
        parent::setUp();
        warning_collector::reset();
        warning_collector::clear_test_failures();
    }

    /**
     * Leave nothing behind.
     */
    protected function tearDown(): void {
        warning_collector::reset();
        warning_collector::clear_test_failures();
        parent::tearDown();
    }

    /**
     * Both lookups resolve on a stock site without warnings.
     */
    public function test_lookups_resolve_without_warnings(): void {
        $this->resetAfterTest();

        $this->assertMatchesRegularExpression('/^\d+\.\d+$/', (string) h5p_core_api::resolve());
        $groups = filetype_catalog_service::get_groups();
        $this->assertIsArray($groups);
        $this->assertArrayHasKey('image', $groups);

        $this->assertSame([], warning_collector::drain());
        $this->assertDebuggingNotCalled();
    }

    /**
     * A failing H5P lookup returns null and records the h5p_version warning.
     */
    public function test_h5p_core_api_failure_records_warning(): void {
        $this->resetAfterTest();
        warning_collector::set_test_failure(warning_collector::STEP_H5P_VERSION, new \RuntimeException('no handler'));

        $this->assertNull(h5p_core_api::resolve());
        $this->assertDebuggingCalled(null, DEBUG_NORMAL);

        $warnings = warning_collector::drain();
        $this->assertSame([['step' => warning_collector::STEP_H5P_VERSION, 'subject' => '', 'reason' => 'no handler']], $warnings);
        $this->assertSame(
            [get_string('generationwarning_h5p_version', 'local_coursegen')],
            warning_collector::to_messages($warnings)
        );
    }

    /**
     * A failing file-type lookup returns null and records the filetype_catalog warning.
     */
    public function test_filetype_catalog_failure_records_warning(): void {
        $this->resetAfterTest();
        warning_collector::set_test_failure(warning_collector::STEP_FILETYPE_CATALOG, new \RuntimeException('broken'));

        $this->assertNull(filetype_catalog_service::get_groups());
        $this->assertDebuggingCalled(null, DEBUG_NORMAL);

        $warnings = warning_collector::drain();
        $this->assertCount(1, $warnings);
        $this->assertSame(warning_collector::STEP_FILETYPE_CATALOG, $warnings[0]['step']);
        $this->assertSame('broken', $warnings[0]['reason']);
    }
}

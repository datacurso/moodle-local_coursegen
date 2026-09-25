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

namespace local_coursegen\mod_parameters;

use local_coursegen\event\package_download_skipped;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/aiprovider_datacurso_stub.php');

/**
 * Unit tests for folder_parameters — filepath normalisation, the empty-files no-op
 * and graceful degradation when the AI provider client is unavailable.
 *
 * The actual download + ingest path depends on the external AI service and is covered by E2E.
 * For the degradation test exactly one failure path runs per site: with the provider plugin
 * installed the real client constructor throws instance_disabled (no enabled instance on a
 * test site); without it the stub client throws on download.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\mod_parameters\folder_parameters
 * @covers \local_coursegen\mod_parameters\base_parameters
 */
final class folder_parameters_test extends \advanced_testcase {
    /**
     * An AI folder_path is normalised to a Moodle filearea filepath.
     *
     * @dataProvider filepath_provider
     * @param string $input
     * @param string $expected
     */
    public function test_normalize_filepath(string $input, string $expected): void {
        $this->assertSame($expected, folder_parameters::normalize_filepath($input));
    }

    /**
     * Provides test cases for filepath normalisation.
     *
     * @return array<string, array{0:string,1:string}>
     */
    public static function filepath_provider(): array {
        return [
            'root empty'       => ['', '/'],
            'root slash'       => ['/', '/'],
            'single'           => ['Anexos', '/Anexos/'],
            'already slashed'  => ['/Anexos/', '/Anexos/'],
            'nested'           => ['Datos/Tablas', '/Datos/Tablas/'],
            'empty segments'   => ['a//b/', '/a/b/'],
            'spaces trimmed'   => ['  Conceptos  ', '/Conceptos/'],
        ];
    }

    /**
     * With no files in the payload the parameters are returned unchanged (folder stays empty).
     */
    public function test_no_files_is_noop(): void {
        $this->resetAfterTest();
        $params = (object) ['mod_settings' => ['files' => []]];
        $out = (new folder_parameters($params))->get_parameters();
        $this->assertFalse(isset($out->files));

        $params2 = (object) ['mod_settings' => []];
        $out2 = (new folder_parameters($params2))->get_parameters();
        $this->assertFalse(isset($out2->files));
    }

    /**
     * A provider failure (unavailable client or failed download) is reported per file
     * (event + debugging) and the folder is still created instead of aborting.
     */
    public function test_provider_failure_degrades_gracefully(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $params = (object) [
            'name' => 'AI folder',
            'mod_settings' => ['files' => [
                ['file_path' => '/tmp/generated/a.pdf', 'file_name' => 'a.pdf', 'folder_path' => 'Docs'],
                ['file_path' => '/tmp/generated/b.pdf', 'file_name' => 'b.pdf', 'folder_path' => ''],
            ]],
        ];

        $sink = $this->redirectEvents();
        $out = (new folder_parameters($params))->get_parameters();
        $events = $sink->get_events();
        $sink->close();

        $this->assertDebuggingCalledCount(2);
        $this->assertSame('AI folder', $out->name);
        // The (empty) draft area is still attached so the folder gets created.
        $this->assertTrue(isset($out->files));

        $skipped = array_values(array_filter($events, static function ($event) {
            return $event instanceof package_download_skipped;
        }));
        $this->assertCount(2, $skipped);
        $this->assertSame(['a.pdf', 'b.pdf'], array_map(static function ($event) {
            return $event->other['filename'];
        }, $skipped));
        $this->assertSame('folder', $skipped[0]->other['modname']);
    }
}

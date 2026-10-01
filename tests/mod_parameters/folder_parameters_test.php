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

use aiprovider_datacurso\httpclient\ai_course_api;
use core\exception\moodle_exception;
use local_coursegen\local\api_client_factory;
use local_coursegen\local\warning_collector;
use local_coursegen\tests\api_testcase;

/**
 * Unit tests for folder_parameters — filepath normalisation, the empty-files no-op and the
 * download request built for each generated file.
 *
 * The AI HTTP client is injected through api_client_factory, so no network request is performed.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\mod_parameters\folder_parameters
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\mod_parameters\folder_parameters::class)]
final class folder_parameters_test extends api_testcase {
    /**
     * An AI folder_path is normalised to a Moodle filearea filepath.
     *
     * @dataProvider filepath_provider
     * @param string $input
     * @param string $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('filepath_provider')]
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
     * The remote path travels percent-encoded as a single query value and the file name is
     * reduced to a valid Moodle file name, like the single-package downloads do.
     */
    public function test_download_endpoint_is_encoded_and_filename_cleaned(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $calls = [];
        $this->inject_download_client($calls, 'pdf bytes');

        $params = (object) ['mod_settings' => ['files' => [
            [
                'file_path' => '/tmp/out/report v1&final#2.pdf',
                'file_name' => '../report v1&final#2.pdf',
                'folder_path' => 'Anexos',
            ],
        ]]];

        $out = (new folder_parameters($params))->get_parameters();
        $this->assertDebuggingNotCalled();

        $this->assertCount(1, $calls);
        $this->assertSame('/files/download?path=%2Ftmp%2Fout%2Freport%20v1%26final%232.pdf', $calls[0]['endpoint']);
        $this->assertSame(clean_param('report v1&final#2.pdf', PARAM_FILE), $calls[0]['filename']);
        $this->assertStringNotContainsString('..', $calls[0]['filename']);
        $this->assertSame('/Anexos/', $calls[0]['filerecord']['filepath']);
        $this->assertSame($out->files, $calls[0]['filerecord']['itemid']);
    }

    /**
     * A file that cannot be downloaded is skipped with a warning (debugging at the normal level
     * and an entry in the warning collector) and the remaining files are still downloaded.
     */
    public function test_download_failure_is_recorded_as_warning(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        warning_collector::reset();

        $calls = 0;
        $mock = $this->getMockBuilder(ai_course_api::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['download_file'])
            ->getMock();
        $mock->method('download_file')->willReturnCallback(
            function (string $endpoint, string $filename, array $filerecord = []) use (&$calls): ?\stored_file {
                $calls++;
                if ($calls === 1) {
                    throw new moodle_exception('curlerror', 'aiprovider_datacurso', '', 'Connection refused');
                }
                return $this->create_draft_file($filename, 'pdf bytes', (int) ($filerecord['itemid'] ?? 0));
            }
        );
        api_client_factory::set_test_client($mock);

        $params = (object) ['mod_settings' => ['files' => [
            ['file_path' => '/tmp/out/first.pdf', 'file_name' => 'first.pdf'],
            ['file_path' => '/tmp/out/second.pdf', 'file_name' => 'second.pdf'],
        ]]];

        $out = (new folder_parameters($params))->get_parameters();
        $this->assertDebuggingCalled(null, DEBUG_NORMAL);

        $this->assertSame(2, $calls);
        $this->assertGreaterThan(0, $out->files);

        $warnings = warning_collector::drain();
        $this->assertCount(1, $warnings);
        $this->assertSame(warning_collector::STEP_FOLDER_FILE, $warnings[0]['step']);
        $this->assertSame('first.pdf', $warnings[0]['subject']);
        $this->assertStringContainsString('Connection refused', $warnings[0]['reason']);
    }

    /**
     * A download that returns no file is not a silent skip: it is recorded as a warning too.
     */
    public function test_download_without_file_is_recorded_as_warning(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        warning_collector::reset();

        $calls = [];
        $this->inject_download_client($calls, null, false);

        $params = (object) ['mod_settings' => ['files' => [
            ['file_path' => '/tmp/out/missing.pdf', 'file_name' => 'missing.pdf'],
        ]]];

        $out = (new folder_parameters($params))->get_parameters();
        $this->assertDebuggingCalled(null, DEBUG_NORMAL);

        $this->assertCount(1, $calls);
        $this->assertGreaterThan(0, $out->files);

        $warnings = warning_collector::drain();
        $this->assertCount(1, $warnings);
        $this->assertSame(warning_collector::STEP_FOLDER_FILE, $warnings[0]['step']);
        $this->assertSame('missing.pdf', $warnings[0]['subject']);
        $this->assertSame(
            [get_string('generationwarning_folder_file', 'local_coursegen', 'missing.pdf')],
            warning_collector::to_messages($warnings)
        );
    }

    /**
     * A file whose name cleans down to nothing is skipped without aborting the folder.
     */
    public function test_file_with_invalid_name_is_skipped(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $calls = [];
        $this->inject_download_client($calls, 'pdf bytes');

        $params = (object) ['mod_settings' => ['files' => [
            ['file_path' => '/tmp/out/first.pdf', 'file_name' => '..'],
            ['file_path' => '/tmp/out/second.pdf', 'file_name' => 'second.pdf'],
        ]]];

        $out = (new folder_parameters($params))->get_parameters();
        $this->assertDebuggingCalledCount(1);

        $this->assertCount(1, $calls);
        $this->assertSame('second.pdf', $calls[0]['filename']);
        $this->assertGreaterThan(0, $out->files);
    }
}

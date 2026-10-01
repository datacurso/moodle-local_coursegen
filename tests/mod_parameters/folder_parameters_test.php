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
        $this->inject_download_client($calls, null, false);

        $params = (object) ['mod_settings' => ['files' => [
            [
                'file_path' => '/tmp/out/report v1&final#2.pdf',
                'file_name' => '../report v1&final#2.pdf',
                'folder_path' => 'Anexos',
            ],
        ]]];

        $out = (new folder_parameters($params))->get_parameters();

        $this->assertCount(1, $calls);
        $this->assertSame('/files/download?path=%2Ftmp%2Fout%2Freport%20v1%26final%232.pdf', $calls[0]['endpoint']);
        $this->assertSame(clean_param('report v1&final#2.pdf', PARAM_FILE), $calls[0]['filename']);
        $this->assertStringNotContainsString('..', $calls[0]['filename']);
        $this->assertSame('/Anexos/', $calls[0]['filerecord']['filepath']);
        $this->assertSame($out->files, $calls[0]['filerecord']['itemid']);
    }

    /**
     * A file whose name cleans down to nothing is skipped without aborting the folder.
     */
    public function test_file_with_invalid_name_is_skipped(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $calls = [];
        $this->inject_download_client($calls, null, false);

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

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

use core\context\user;
use core\exception\moodle_exception;
use local_coursegen\tests\api_testcase;

/**
 * Shared behaviour of the single-package parameter handlers (imscp, resource, scorm).
 *
 * The three handlers only differ in the form field that receives the draft item id of the
 * downloaded package; everything else (endpoint, file name cleaning, validation) is the
 * base_parameters implementation. The AI HTTP client is injected through api_client_factory,
 * so no network request is performed.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\mod_parameters\base_parameters
 * @covers \local_coursegen\mod_parameters\imscp_parameters
 * @covers \local_coursegen\mod_parameters\resource_parameters
 * @covers \local_coursegen\mod_parameters\scorm_parameters
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\mod_parameters\base_parameters::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\mod_parameters\imscp_parameters::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\mod_parameters\resource_parameters::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\mod_parameters\scorm_parameters::class)]
final class package_parameters_test extends api_testcase {
    /**
     * Handler class and the form field that receives the downloaded package, per module.
     *
     * @return array<string, array{0:class-string<base_parameters>,1:string}>
     */
    public static function handler_provider(): array {
        return [
            'imscp' => [imscp_parameters::class, 'package'],
            'resource' => [resource_parameters::class, 'files'],
            'scorm' => [scorm_parameters::class, 'packagefile'],
        ];
    }

    /**
     * The package is requested through the encoded download endpoint with a cleaned file name,
     * and the draft item id of the stored file lands in the module-specific field.
     *
     * @dataProvider handler_provider
     * @param string $class Handler class name.
     * @param string $field Form field that receives the draft item id.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('handler_provider')]
    public function test_package_is_downloaded_into_target_field(string $class, string $field): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $calls = [];
        $this->inject_download_client($calls, 'package bytes');

        $params = (object) [
            'name' => 'Generated package',
            'mod_settings' => [
                'file_path' => '/tmp/out/my pkg&v2.zip',
                'file_name' => '../my pkg&v2.zip',
            ],
        ];

        /** @var base_parameters $handler */
        $handler = new $class($params);
        $out = $handler->get_parameters();

        $this->assertCount(1, $calls);
        $this->assertSame('/files/download?path=%2Ftmp%2Fout%2Fmy%20pkg%26v2.zip', $calls[0]['endpoint']);
        $this->assertSame(clean_param('my pkg&v2.zip', PARAM_FILE), $calls[0]['filename']);
        $this->assertStringNotContainsString('..', $calls[0]['filename']);

        // The field carries the draft item id of the stored package, nothing else changed.
        $this->assertObjectHasProperty($field, $out);
        $this->assertGreaterThan(0, $out->{$field});
        $this->assertSame('Generated package', $out->name);
        $files = get_file_storage()->get_area_files(
            user::instance($USER->id)->id,
            'user',
            'draft',
            $out->{$field},
            'id',
            false
        );
        $this->assertCount(1, $files);
        $this->assertSame($calls[0]['filename'], reset($files)->get_filename());
    }

    /**
     * Only the module-specific field is written: the other handlers' fields stay untouched.
     *
     * @dataProvider handler_provider
     * @param string $class Handler class name.
     * @param string $field Form field that receives the draft item id.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('handler_provider')]
    public function test_other_package_fields_are_not_written(string $class, string $field): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $calls = [];
        $this->inject_download_client($calls);

        $params = (object) ['mod_settings' => ['file_path' => '/tmp/out/pkg.zip', 'file_name' => 'pkg.zip']];
        $out = (new $class($params))->get_parameters();

        foreach (['package', 'files', 'packagefile'] as $candidate) {
            if ($candidate === $field) {
                $this->assertTrue(isset($out->{$candidate}), "{$candidate} must be set");
            } else {
                $this->assertFalse(isset($out->{$candidate}), "{$candidate} must not be set");
            }
        }
    }

    /**
     * Missing package information is rejected before any download is attempted.
     *
     * @dataProvider handler_provider
     * @param string $class Handler class name.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('handler_provider')]
    public function test_missing_package_info_throws_before_download(string $class): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $calls = [];
        $this->inject_download_client($calls);

        $params = (object) ['mod_settings' => ['file_name' => 'pkg.zip']];

        try {
            (new $class($params))->get_parameters();
            $this->fail('A result without file_path must be rejected.');
        } catch (moodle_exception $e) {
            $this->assertSame('error_missing_package_info', $e->errorcode);
        }

        $this->assertCount(0, $calls);
    }

    /**
     * A file name that cleans down to nothing is rejected as an invalid package.
     *
     * @dataProvider handler_provider
     * @param string $class Handler class name.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('handler_provider')]
    public function test_invalid_file_name_is_rejected(string $class): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $calls = [];
        $this->inject_download_client($calls);

        $params = (object) ['mod_settings' => ['file_path' => '/tmp/out/pkg.zip', 'file_name' => '..']];

        try {
            (new $class($params))->get_parameters();
            $this->fail('A file name that cleans down to nothing must be rejected.');
        } catch (moodle_exception $e) {
            $this->assertSame('error_invalid_package', $e->errorcode);
        }

        $this->assertCount(0, $calls);
    }
}

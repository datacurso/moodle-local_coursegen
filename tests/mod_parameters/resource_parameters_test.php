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
 * Unit tests for resource_parameters: the downloaded package lands in the 'files' form field.
 *
 * The shared download behaviour (endpoint, file name cleaning, validation) is covered by
 * package_parameters_test; this test pins the module-specific field.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\mod_parameters\resource_parameters
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\mod_parameters\resource_parameters::class)]
final class resource_parameters_test extends api_testcase {
    /**
     * The handler extends base_parameters (so create_mod_service resolves it) and writes 'files'.
     */
    public function test_download_sets_files_field(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $calls = [];
        $this->inject_download_client($calls, 'package bytes');

        $params = (object) ['mod_settings' => ['file_path' => '/tmp/out/pkg.zip', 'file_name' => 'pkg.zip']];
        $handler = new resource_parameters($params);
        $this->assertInstanceOf(base_parameters::class, $handler);

        $out = $handler->get_parameters();

        $this->assertCount(1, $calls);
        $this->assertSame('/files/download?path=%2Ftmp%2Fout%2Fpkg.zip', $calls[0]['endpoint']);
        $this->assertSame('pkg.zip', $calls[0]['filename']);
        $this->assertGreaterThan(0, $out->files);
        // The same object is returned, now carrying the draft item id.
        $this->assertSame($params, $out);
    }
}

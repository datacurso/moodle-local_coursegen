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

namespace local_coursegen\utils;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/aiprovider_datacurso_stub.php');

/**
 * Unit tests for text_editor_parameter_cleaner — generated images with no usable provider client.
 *
 * On a test site no DataCurso provider instance is enabled: the Moodle 5.0
 * provider constructor throws instance_disabled, while the stub client throws
 * on download. Either way the editor text must survive untouched.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\utils\text_editor_parameter_cleaner
 */
final class text_editor_parameter_cleaner_test extends \advanced_testcase {
    /**
     * A generated image reference is kept as-is (and the failure logged) when the
     * provider client cannot be built or the download fails.
     */
    public function test_generated_image_is_kept_when_provider_unavailable(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $markdown = 'Intro ![diagram](/tmp/generated_images/diagram.png) outro';
        $parameters = ['intro' => ['text' => $markdown, 'format' => 0]];

        $cleaned = text_editor_parameter_cleaner::clean_text_editor_objects($parameters);

        $this->assertDebuggingCalledCount(1);
        $this->assertSame($markdown, $cleaned['intro']['text']);
        $this->assertSame(1, $cleaned['intro']['format']);
        $this->assertGreaterThan(0, $cleaned['intro']['itemid']);
    }
}

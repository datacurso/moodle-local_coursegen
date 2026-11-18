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

use local_coursegen\utils\ai_text_sanitiser;

/**
 * A frame that shows a file of this site survives the cleaning of an AI text, any other frame does not.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\utils\ai_text_sanitiser
 */
final class ai_text_sanitiser_frame_test extends \advanced_testcase {
    /**
     * The frame of a file of this site is kept, with only the attributes a frame needs.
     */
    public function test_a_frame_of_a_file_of_this_site_is_kept_without_its_other_attributes(): void {
        global $CFG;
        $this->resetAfterTest();
        $address = $CFG->wwwroot . '/pluginfile.php/5/mod_resource/content/1/guide.pdf';

        $text = '<p>Read</p><p><iframe src="' . $address . '" width="100%" onload="alert(1)" style="x"></iframe></p>';
        $clean = ai_text_sanitiser::sanitise_string($text);

        $this->assertSame('<p>Read</p><p><iframe src="' . $address . '" width="100%"></iframe></p>', $clean);
    }

    /**
     * A frame that points anywhere else is removed.
     */
    public function test_a_frame_of_another_address_is_removed(): void {
        $this->resetAfterTest();

        $clean = ai_text_sanitiser::sanitise_string('<p>Read</p><iframe src="https://example.com/x"></iframe>');

        $this->assertStringNotContainsString('iframe', $clean);
        $this->assertStringContainsString('Read', $clean);
    }
}

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

namespace local_coursegen\local\files;

use local_coursegen\utils\text_editor_parameter_cleaner;

/**
 * Which payloads need the pass over the texts of a new activity, and the texts without a draft area.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\files\activity_file_pass
 * @covers     \local_coursegen\utils\text_editor_parameter_cleaner
 */
final class activity_file_pass_payload_test extends \advanced_testcase {
    /**
     * A payload with plain texts has nothing for the pass to do.
     */
    public function test_a_payload_without_signs_needs_no_pass(): void {
        $payload = [
            'resource_type' => 'quiz',
            'parameters' => ['name' => 'Quiz', 'mod_settings' => ['questions' => [['questiontext' => ['text' => '<p>1 < 2?</p>']]]]],
        ];
        $this->assertFalse(activity_file_pass::payload_may_hold_files($payload));
    }

    /**
     * A file reference, an image or a marker anywhere in the payload keeps the pass.
     *
     * @dataProvider signs_provider
     * @param string $text A text with a sign.
     */
    public function test_a_payload_with_a_sign_needs_the_pass(string $text): void {
        $payload = ['resource_type' => 'page', 'parameters' => ['page' => ['text' => $text, 'format' => 1]]];
        $this->assertTrue(activity_file_pass::payload_may_hold_files($payload));
    }

    /**
     * The signs the pass acts on.
     *
     * @return array
     */
    public static function signs_provider(): array {
        return [
            'plugin file' => ['<p><img src="@@PLUGINFILE@@/a.png"></p>'],
            'pluginfile url' => ['<a href="https://x.test/pluginfile.php/1/mod_page/content/1/a.pdf">a</a>'],
            'image tag' => ['<img src="https://x.test/a.png">'],
            'markdown image' => ['![alt](a.png)'],
            'image marker' => ['coursegen:image: a cat'],
        ];
    }

    /**
     * Texts cleaned without draft areas keep their text and format and open no area.
     */
    public function test_texts_cleaned_without_draft_areas_have_no_itemid(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cleaned = text_editor_parameter_cleaner::clean_without_draft_areas([
            'questiontext' => ['text' => '<p>Q</p>', 'format' => 1],
            'answer' => [['text' => 'A', 'format' => 1], ['text' => 'B', 'format' => 1]],
        ]);

        $this->assertSame(0, $cleaned['questiontext']['itemid']);
        $this->assertSame('<p>Q</p>', $cleaned['questiontext']['text']);
        $this->assertSame(0, $cleaned['answer'][1]['itemid']);

        $normal = text_editor_parameter_cleaner::clean_text_editor_objects(['intro' => ['text' => 'x', 'format' => 1]]);
        $this->assertGreaterThan(0, $normal['intro']['itemid']);
    }
}

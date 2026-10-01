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

/**
 * Generated-image references are localized only when they point at a local generated file.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\utils\generated_image_localizer
 */
final class generated_image_localizer_test extends \advanced_testcase {
    /**
     * Texts that must come back exactly as they were.
     *
     * @return array
     */
    public static function untouched_provider(): array {
        return [
            'empty text' => [''],
            'plain text' => ['<p>No images here</p>'],
            'remote html image' => ['<img src="https://example.org/a.png" alt="a">'],
            'remote markdown image' => ['![alt](https://example.org/a.png)'],
            'data uri image' => ['<img src="data:image/png;base64,AAAA">'],
            'already rewritten image' => ['<img src="@@PLUGINFILE@@/a.png">'],
            'climbing path' => ['<img src="/tmp/../../etc/passwd">'],
            'image without src' => ['<img alt="no source">'],
            'markdown image outside known roots' => ['![alt](/etc/passwd)'],
        ];
    }

    /**
     * @dataProvider untouched_provider
     * @param string $text
     */
    public function test_text_without_a_local_generated_image_is_untouched(string $text): void {
        $localizer = new generated_image_localizer(1);

        $this->assertSame($text, $localizer->localize($text));
    }

    /**
     * Unresolved image placeholders are removed, alone on a line or inline.
     */
    public function test_unresolved_image_placeholders_are_removed(): void {
        $localizer = new generated_image_localizer(1);
        $text = "<p>A {{image: a banner}} B</p>\n{{image: lonely}}\n<p>C</p>";

        $this->assertSame("<p>A  B</p>\n\n<p>C</p>", $localizer->localize($text));
    }
}

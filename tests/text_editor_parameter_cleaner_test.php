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

use local_coursegen\utils\text_editor_parameter_cleaner;

/**
 * What is removed from the HTML the AI writes before it is stored, and what stays.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\utils\text_editor_parameter_cleaner
 */
final class text_editor_parameter_cleaner_test extends \advanced_testcase {
    /**
     * Hostile pieces of HTML and what must not survive of each.
     *
     * @return array
     */
    public static function hostile_provider(): array {
        return [
            'script element' => ['<p>Hi</p><script>alert(1)</script>', '<script'],
            'event attribute' => ['<img src="x.png" onerror="alert(1)">', 'onerror'],
            'script url in a link' => ['<a href="javascript:alert(1)">go</a>', 'javascript:'],
            'script url disguised' => ['<a href="  JaVa&#x09;ScRiPt:alert(1)">go</a>', 'script:'],
            'data url in a link' => ['<a href="data:text/html;base64,PHNjcmlwdD4=">go</a>', 'data:text'],
            'iframe' => ['<iframe src="https://evil.example"></iframe>', '<iframe'],
            'form' => ['<form action="https://evil.example"><input name="p"></form>', '<form'],
            'style with expression' => ['<p style="width:expression(alert(1))">x</p>', 'expression'],
        ];
    }

    /**
     * The text of an editor field of the parameters after the cleaning.
     *
     * @param string $html
     * @return string
     */
    private function cleaned(string $html): string {
        $this->resetAfterTest();
        $this->setAdminUser();
        $result = text_editor_parameter_cleaner::clean_text_editor_objects([
            'content' => ['text' => $html, 'format' => 1, 'itemid' => 0],
        ]);
        return $result['content']['text'];
    }

    /**
     * Nothing dangerous is left after cleaning.
     *
     * @dataProvider hostile_provider
     * @param string $html
     * @param string $forbidden
     */
    public function test_removes_what_runs_code(string $html, string $forbidden): void {
        $this->resetAfterTest();

        $result = $this->cleaned($html);

        $this->assertStringNotContainsStringIgnoringCase($forbidden, $result);
    }

    /**
     * Layout and inline styles of an ordinary page stay.
     */
    public function test_keeps_layout_and_inline_styles(): void {
        $this->resetAfterTest();
        $html = '<section style="max-width:1000px;background:#f4f7fa;"><h2 style="color:#203442;">Title</h2>'
            . '<ol><li>One</li></ol><table><tr><td>A</td></tr></table></section>';

        $result = $this->cleaned($html);

        $this->assertStringContainsString('<h2', $result);
        $this->assertStringContainsString('<ol>', $result);
        $this->assertStringContainsString('<table', $result);
        $this->assertStringContainsString('color', $result);
    }

    /**
     * A text with no markup is returned as it came.
     */
    public function test_a_plain_text_is_untouched(): void {
        $this->assertSame('Just a sentence.', $this->cleaned('Just a sentence.'));
        $this->assertSame('', $this->cleaned(''));
    }

    /**
     * The text of every editor field the AI fills goes through the cleaning.
     */
    public function test_cleans_the_text_editor_fields_of_the_parameters(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $parameters = [
            'name' => 'A page',
            'content' => ['text' => '<p>Ok</p><script>alert(1)</script>', 'format' => 1, 'itemid' => 0],
            'intro' => ['text' => '<img src="x.png" onerror="alert(1)">', 'format' => 1],
        ];

        $result = text_editor_parameter_cleaner::clean_text_editor_objects($parameters);

        $this->assertStringNotContainsString('<script', $result['content']['text']);
        $this->assertStringContainsString('<p>Ok</p>', $result['content']['text']);
        $this->assertStringNotContainsStringIgnoringCase('onerror', $result['intro']['text']);
    }
}

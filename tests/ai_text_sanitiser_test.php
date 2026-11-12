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
 * Tests for the single point where the text the AI writes is made safe.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\utils\ai_text_sanitiser
 */
final class ai_text_sanitiser_test extends \advanced_testcase {
    /**
     * Markup loses scripts, event handlers and javascript: links and keeps its layout.
     */
    public function test_markup_is_cleaned_and_keeps_its_layout(): void {
        $clean = ai_text_sanitiser::sanitise_string(
            '<p>Text <strong>bold</strong></p><script>alert(1)</script>'
            . '<img src="https://example.com/a.png" onerror="alert(2)"><a href="javascript:alert(3)">link</a>'
        );

        $this->assertStringContainsString('<p>Text <strong>bold</strong></p>', $clean);
        $this->assertStringNotContainsStringIgnoringCase('<script', $clean);
        $this->assertStringNotContainsStringIgnoringCase('onerror', $clean);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $clean);
    }

    /**
     * Quotes escaped by the JSON of the service are restored before cleaning, so attributes are seen.
     */
    public function test_escaped_quotes_do_not_hide_an_attribute(): void {
        $clean = ai_text_sanitiser::sanitise_string('<img src=\"x.png\" onerror=\"alert(1)\">');

        $this->assertStringNotContainsStringIgnoringCase('onerror', $clean);
    }

    /**
     * Strings without markup are kept exactly as the AI wrote them.
     *
     * @dataProvider plain_strings_provider
     * @param string $text
     */
    public function test_plain_strings_are_kept(string $text): void {
        $this->assertSame($text, ai_text_sanitiser::sanitise_string($text));
    }

    /**
     * Plain strings an AI result carries.
     *
     * @return array
     */
    public static function plain_strings_provider(): array {
        return [
            'title' => ['Water cycle & climate'],
            'comparison' => ['x < 5 and y > 2'],
            'number' => ['42'],
            'choice key' => ['multichoice'],
            'file path' => ['generated/packages/sample-activity.h5p'],
            'url' => ['https://example.com/page?a=1&b=2'],
            'feedback presentation' => ['r>>>>>One|Two'],
        ];
    }

    /**
     * A title that starts with the word JavaScript stays readable, and is no longer a URL.
     */
    public function test_a_title_starting_with_javascript_stays_readable(): void {
        $this->assertSame('JavaScript : the basics', ai_text_sanitiser::sanitise_string('JavaScript: the basics'));
    }

    /**
     * A script URL is no longer one, however it is written.
     *
     * @dataProvider script_urls_provider
     * @param string $url
     */
    public function test_script_urls_are_neutralised(string $url): void {
        $clean = ai_text_sanitiser::sanitise_string($url);

        // What a browser parses: entities decoded, tabs and newlines removed anywhere, and control
        // characters and spaces trimmed at both ends; a space inside is kept and breaks the scheme.
        $parsed = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parsed = trim(str_replace(["\t", "\n", "\r"], '', $parsed), "\x00..\x20");
        $this->assertDoesNotMatchRegularExpression('/^(?:java|vb)script:/i', $parsed);
    }

    /**
     * Script URLs, plain and obfuscated.
     *
     * @return array
     */
    public static function script_urls_provider(): array {
        return [
            'plain' => ['javascript:alert(document.cookie)'],
            'upper case' => ['JAVASCRIPT:alert(1)'],
            'leading space' => ['  javascript:alert(1)'],
            'space after colon' => ['javascript: alert(1)'],
            'tab inside' => ["java\tscript:alert(1)"],
            'entity' => ['&#106;avascript:alert(1)'],
            'vbscript' => ['vbscript:msgbox(1)'],
        ];
    }

    /**
     * Every string of a nested result is cleaned, arrays and objects alike; other values are kept.
     */
    public function test_nested_results_are_cleaned_and_other_values_kept(): void {
        $result = ai_text_sanitiser::sanitise([
            'name' => 'Plain',
            'grade' => 10,
            'visible' => true,
            'ratio' => 0.5,
            'nothing' => null,
            'mod_settings' => [
                'pages' => [['content_html' => '<p>Ok</p><script>alert(1)</script>']],
                'entry' => (object)['definition' => '<b onclick="alert(1)">Term</b>'],
            ],
        ]);

        $this->assertSame('Plain', $result['name']);
        $this->assertSame(10, $result['grade']);
        $this->assertTrue($result['visible']);
        $this->assertSame(0.5, $result['ratio']);
        $this->assertNull($result['nothing']);
        $this->assertSame('<p>Ok</p>', $result['mod_settings']['pages'][0]['content_html']);
        $this->assertSame('<b>Term</b>', $result['mod_settings']['entry']->definition);
    }
}

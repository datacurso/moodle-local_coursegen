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
 * Tests for template_mold_context.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\utils\template_mold_context
 */
final class template_mold_context_test extends \basic_testcase {
    /**
     * The context of a slot depends on what comes before it.
     *
     * @dataProvider contexts
     * @param string $before The mold up to the slot.
     * @param string $expected
     */
    public function test_context_of_a_slot(string $before, string $expected): void {
        $this->assertSame($expected, template_mold_context::at($before . '[[x]]', strlen($before)));
    }

    /**
     * Mold beginnings and the context of a slot right after them.
     *
     * @return array
     */
    public static function contexts(): array {
        return [
            'start of the text' => ['', 'text'],
            'after a tag' => ['<p>', 'text'],
            'inside a textarea' => ['<textarea id="a">', 'text'],
            'double quoted value' => ['<input placeholder="', 'attr:placeholder'],
            'single quoted value with text before' => ["<div data-Keys='a, ", 'attr:data-keys'],
            'unquoted value' => ['<div title=', 'unsafe'],
            'attribute name' => ['<div ', 'unsafe'],
            'script' => ['<script type="text/javascript">var a=', 'unsafe'],
            'style' => ['<style>', 'unsafe'],
            'comment' => ['<!-- note ', 'unsafe'],
            'after a closed script' => ['<script>a()</script><p>', 'text'],
        ];
    }

    /**
     * What the AI wrote is kept in a slot only when it is safe there.
     *
     * @dataProvider values
     * @param string $context
     * @param string $value
     * @param bool $expected
     */
    public function test_accepted_values(string $context, string $value, bool $expected): void {
        $this->assertSame($expected, template_mold_context::accepts($context, $value));
    }

    /**
     * Contexts, values and whether they are accepted.
     *
     * @return array
     */
    public static function values(): array {
        return [
            'text, even markup, is cleaned later' => ['text', '<script>x</script>', true],
            'plain attribute value' => ['attr:data-keys', 'media, mediana, moda', true],
            'empty attribute value' => ['attr:placeholder', '', true],
            'double quote' => ['attr:title', 'a" onclick="x', false],
            'single quote' => ['attr:title', "a' onclick='x", false],
            'angle bracket' => ['attr:title', 'a><script>', false],
            'backtick' => ['attr:title', "a\x60b", false],
            'event attribute' => ['attr:onclick', 'go()', false],
            'embedded document' => ['attr:srcdoc', '&lt;script&gt;alert(1)&lt;/script&gt;', false],
            'refresh content' => ['attr:content', '0;url=https://example.org', false],
            'poster address' => ['attr:poster', 'https://example.org/a.png', false],
            'namespace' => ['attr:xmlns:xlink', 'https://example.org', false],
            'style attribute' => ['attr:style', 'color:red', false],
            'srcset attribute' => ['attr:srcset', 'a.png', false],
            'form action' => ['attr:action', 'https://x', false],
            'web address link' => ['attr:href', 'https://example.org/a?b=c', true],
            'activity link token' => ['attr:href', '$@COURSEGENLINK*u-1.a_b@$', true],
            'script link' => ['attr:href', 'javascript:alert(1)', false],
            'relative link' => ['attr:href', '/mod/page/view.php', false],
            'video player source' => ['attr:src', 'https://www.youtube-nocookie.com/embed/abc_D-1?start=5', true],
            'other source' => ['attr:src', 'https://evil.example/x', false],
            'unsafe context' => ['unsafe', 'anything', false],
            'unknown context' => ['other', 'anything', false],
        ];
    }

    /**
     * Only text gaps are cleaned rather than kept.
     */
    public function test_only_text_is_cleaned(): void {
        $this->assertTrue(template_mold_context::is_text('text'));
        $this->assertFalse(template_mold_context::is_text('attr:title'));
    }
}

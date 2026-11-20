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

use local_coursegen\local\service\create_mod_service;

/**
 * Tests for template_molds and the cleaning of the texts of a template run.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\utils\template_molds
 * @covers     \local_coursegen\utils\ai_text_sanitiser
 * @covers     \local_coursegen\local\service\create_mod_service
 */
final class template_molds_test extends \advanced_testcase {
    /** @var string A reflection page of a template: an answer box, a button, hidden messages, ids and a script. */
    private const MOLD = '<div class="reflexion"><h3>[[coursegen:aiprompt: a question]]</h3>'
        . '<textarea id="cg-answer" placeholder="[[coursegen:aiprompt: a hint]]"></textarea>'
        . '<button type="button" id="cg-send">Enviar</button>'
        . '<p id="cg-error" style="display:none">Escribe tu respuesta.</p>'
        . '<p id="cg-sent" style="display:none">Respuesta enviada.</p>'
        . '<script>document.getElementById("cg-send").addEventListener("click", send);</script></div>';

    /** @var string[] The interactive parts the author built, each of which must be stored as it is. */
    private const AUTHOR_PARTS = [
        '<textarea id="cg-answer"',
        '<button type="button" id="cg-send">Enviar</button>',
        '<p id="cg-error" style="display:none">',
        '<script>document.getElementById("cg-send").addEventListener("click", send);</script>',
    ];

    /**
     * The mold filled in as the AI service returns it.
     *
     * @param string $question What the AI wrote for the question.
     * @param string $hint What the AI wrote for the hint.
     * @return string
     */
    private static function filled(string $question, string $hint): string {
        $text = str_replace('[[coursegen:aiprompt: a question]]', $question, self::MOLD);
        return str_replace('[[coursegen:aiprompt: a hint]]', $hint, $text);
    }

    protected function tearDown(): void {
        template_molds::reset();
        parent::tearDown();
    }

    /**
     * A text that matches a mold keeps every part of the author and the AI's text.
     */
    public function test_matching_text_keeps_the_author_markup(): void {
        template_molds::use_programs([template_mold_parser::parse(self::MOLD)]);
        $text = self::filled('¿Qué es la media?', 'Escribe dos ideas');
        $this->assertSame($text, ai_text_sanitiser::sanitise_string($text));
        $this->assertSame($text, ai_text_sanitiser::clean_html($text));
    }

    /**
     * What the AI wrote between tags is still cleaned.
     */
    public function test_ai_text_is_still_cleaned(): void {
        template_molds::use_programs([template_mold_parser::parse(self::MOLD)]);
        $text = self::filled('Pregunta<script>alert(1)</script><img src="x" onerror="alert(2)">', 'pista');
        $cleaned = ai_text_sanitiser::sanitise_string($text);
        $this->assertStringNotContainsString('alert(1)', $cleaned);
        $this->assertStringNotContainsString('onerror', $cleaned);
        $this->assert_author_parts_kept($cleaned);
    }

    /**
     * A bracket the AI leaves at the end of its text cannot start a tag with the author's text after it.
     */
    public function test_open_bracket_cannot_join_the_author_text(): void {
        $mold = '<p>[[coursegen:aiprompt: a]]script>keep</p>';
        template_molds::use_programs([template_mold_parser::parse($mold)]);
        $cleaned = ai_text_sanitiser::sanitise_string('<p>Hola <script>keep</p>');
        $this->assertSame('<p>Hola &lt;script>keep</p>', $cleaned);
    }

    /**
     * An attribute value that could leave its quotes makes the text match no mold, so it is cleaned whole.
     */
    public function test_unsafe_attribute_value_cleans_the_whole_text(): void {
        template_molds::use_programs([template_mold_parser::parse(self::MOLD)]);
        $text = self::filled('Pregunta', 'x" onfocus="alert(1)');
        $cleaned = ai_text_sanitiser::sanitise_string($text);
        $this->assertStringNotContainsString('onfocus', $cleaned);
        $this->assertStringNotContainsString('<textarea', $cleaned);
    }

    /**
     * Author markup added where the mold has none is not kept: the text matches no mold.
     *
     * @dataProvider altered_texts
     * @param string $text
     */
    public function test_text_that_matches_no_mold_is_cleaned_whole(string $text): void {
        template_molds::use_programs([template_mold_parser::parse(self::MOLD)]);
        $this->assertNull(template_molds::rebuild($text, [ai_text_sanitiser::class, 'clean_untrusted']));
        $cleaned = ai_text_sanitiser::sanitise_string($text);
        $this->assertStringNotContainsString('<script', $cleaned);
        $this->assertStringNotContainsString('<textarea', $cleaned);
    }

    /**
     * Texts the AI service could return that are not the mold filled in.
     *
     * @return array
     */
    public static function altered_texts(): array {
        $filled = self::filled('Pregunta', 'pista');
        return [
            'script appended' => [$filled . '<script>alert(1)</script>'],
            'script prepended' => ['<script>alert(1)</script>' . $filled],
            'author script changed' => [str_replace('send);', 'steal);', $filled)],
            'a second text box' => [str_replace('</div>', '<textarea></textarea></div>', $filled)],
        ];
    }

    /**
     * Outside a template run, and after a reset, every text is cleaned whole as before.
     */
    public function test_no_template_means_the_usual_cleaning(): void {
        $text = self::filled('Pregunta', 'pista');
        $this->assertNull(template_molds::rebuild($text, 'strval'));
        template_molds::use_programs([template_mold_parser::parse(self::MOLD)]);
        template_molds::use_course(null);
        $this->assertStringNotContainsString('<textarea', ai_text_sanitiser::sanitise_string($text));
        template_molds::use_programs([template_mold_parser::parse(self::MOLD)]);
        template_molds::reset();
        $this->assertStringNotContainsString('<textarea', ai_text_sanitiser::clean_html($text));
    }

    /**
     * A lesson made in a template run stores the template's interactive page; without the template it is cleaned.
     */
    public function test_lesson_of_a_template_run_keeps_its_interactive_page(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $template = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('page', ['course' => $template->id, 'content' => self::MOLD]);
        $course = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $PAGE->set_course($course);
        $page = self::filled('¿Qué mide la varianza?<script>alert(1)</script>', 'Usa un ejemplo');

        $this->create_lesson($course, $page, (int) $template->id);
        $stored = $DB->get_field('lesson_pages', 'contents', ['title' => 'Reflexión']);
        $this->assert_author_parts_kept($stored);
        $this->assertStringContainsString('¿Qué mide la varianza?', $stored);
        $this->assertStringNotContainsString('alert(1)', $stored);

        $DB->delete_records('lesson_pages');
        template_molds::reset();
        $this->create_lesson($course, $page, null);
        $stored = $DB->get_field('lesson_pages', 'contents', ['title' => 'Reflexión']);
        $this->assertStringNotContainsString('<textarea', $stored);
        $this->assertStringNotContainsString('<script', $stored);
    }

    /**
     * Create a lesson with one content page from an AI result.
     *
     * @param \stdClass $course
     * @param string $content The page as the AI service returned it.
     * @param int|null $templatecourseid The template's course, or null outside a template run.
     */
    private function create_lesson(\stdClass $course, string $content, ?int $templatecourseid): void {
        $editor = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];
        $parameters = [
            'modulename' => 'lesson',
            'name' => 'Lesson',
            'introeditor' => $editor,
            'visible' => 1,
            'cmidnumber' => '',
            'mediafile' => 0,
            'available' => 0,
            'deadline' => 0,
            'practice' => 0,
            'grade' => 100,
            'mod_settings' => ['pages' => [[
                'page_type' => 'content',
                'title' => 'Reflexión',
                'content_html' => $content,
                'button_text' => 'Siguiente',
            ]]],
        ];
        $result = ['resource_type' => 'lesson', 'parameters' => $parameters];
        create_mod_service::create_from_ai_result($result, $course, 1, null, $templatecourseid);
    }

    /**
     * Assert that every interactive part of the author is in a text.
     *
     * @param string $text
     */
    private function assert_author_parts_kept(string $text): void {
        foreach (self::AUTHOR_PARTS as $part) {
            $this->assertStringContainsString($part, $text);
        }
    }
}

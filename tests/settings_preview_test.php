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

use local_coursegen\local\preview\settings_preview;
use local_coursegen\local\preview\settings_preview_declarations;

/**
 * Unit tests for settings_preview and its declarations.
 *
 * The parameters in the data are the shapes the generators of each type answer: the lists of parts travel in
 * mod_settings, and a part holds its title and its text under the names its type gives them.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\settings_preview
 * @covers     \local_coursegen\local\preview\settings_preview_declarations
 */
final class settings_preview_test extends \advanced_testcase {
    /**
     * The preview of one type, drawn.
     *
     * @param string $modname
     * @param array $parameters
     * @return string
     */
    private function drawn(string $modname, array $parameters): string {
        $preview = new settings_preview($modname, $parameters);
        return $preview->render();
    }

    /**
     * An editor field the way a generator answers it.
     *
     * @param string $text
     * @return array
     */
    private static function editor(string $text): array {
        return ['text' => $text, 'format' => 1, 'itemid' => 0];
    }

    /**
     * What each type shows of what its generator wrote.
     *
     * @return array
     */
    public static function written_types_provider(): array {
        $editor1 = self::editor('<p>Water falls.</p>');
        $editor2 = self::editor('<p>Water runs.</p>');
        $editor3 = self::editor('<p>Liquid to gas.</p>');
        $editor4 = self::editor('<p>Welcome here.</p>');
        $editor5 = self::editor('<p>What is rain?</p>');

        return [
            'book' => ['book', ['mod_settings' => ['chapters' => [
                ['title' => 'Rain', 'subchapter' => 0, 'content_editor' => $editor1],
                ['title' => 'Rivers', 'subchapter' => 1, 'content_editor' => $editor2],
            ]]], ['Chapters', 'Rain', 'Water falls.', 'Rivers', 'Water runs.']],
            'glossary' => ['glossary', ['mod_settings' => ['entries' => [
                ['concept' => 'Evaporation', 'definition_editor' => $editor3],
            ]]], ['Entries', 'Evaporation', 'Liquid to gas.']],
            'wiki' => ['wiki', ['mod_settings' => ['pages' => [
                ['title' => 'Home', 'newcontent_editor' => $editor4],
            ]]], ['Pages', 'Home', 'Welcome here.']],
            'forum' => ['forum', ['mod_settings' => ['discussions' => [
                ['subject' => 'Introduce yourself', 'message' => '<p>Tell us who you are.</p>'],
            ]]], ['Discussions', 'Introduce yourself', 'Tell us who you are.']],
            'lesson' => ['lesson', ['mod_settings' => ['pages' => [
                ['page_type' => 'content', 'title' => 'First page', 'content_html' => '<p>Start here.</p>'],
            ]]], ['Pages', 'First page', 'Start here.']],
            'quiz' => ['quiz', ['mod_settings' => ['questions' => [
                ['qtype' => 'multichoice', 'name' => 'Cycle', 'questiontext' => $editor5],
            ]]], ['Questions', 'Cycle', 'What is rain?']],
            'workshop' => ['workshop', ['mod_settings' => ['criteria' => [
                ['description' => 'Clarity of the argument', 'max_points' => 5],
            ]]], ['Assessment criteria', 'Clarity of the argument']],
            'feedback' => ['feedback', ['mod_settings' => ['questions' => [
                ['typ' => 'textarea', 'name' => 'How was the course?'],
            ]]], ['Questions', 'How was the course?']],
            'assign' => ['assign', ['mod_settings' => ['rubric' => ['criteria' => [
                ['description' => 'Use of sources', 'levels' => []],
            ]]]], ['Rubric criteria', 'Use of sources']],
            'data' => ['data', ['mod_settings' => ['fields' => [
                ['type' => 'text', 'name' => 'Author', 'description' => 'Who wrote it'],
            ]]], ['Fields', 'Author', 'Who wrote it']],
            'choice' => ['choice', ['option' => ['Yes', 'No', 'Maybe']], ['Options', 'Yes', 'No', 'Maybe']],
            'url' => ['url', ['externalurl' => 'https://example.org/water'], ['Address:', 'https://example.org/water']],
        ];
    }

    /**
     * Every type that has parts shows them, with their titles and their texts.
     *
     * @dataProvider written_types_provider
     * @param string $modname
     * @param array $parameters
     * @param string[] $expected
     */
    public function test_each_type_shows_what_its_generator_wrote(string $modname, array $parameters, array $expected): void {
        $html = $this->drawn($modname, $parameters);

        foreach ($expected as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    /**
     * A type with no parts to list shows its description alone.
     *
     * @dataProvider description_only_provider
     * @param string $modname
     */
    public function test_a_type_without_lists_shows_its_description(string $modname): void {
        $parameters = ['introeditor' => ['text' => '<p>About this activity.</p>', 'format' => 1, 'itemid' => 0]];

        $html = $this->drawn($modname, $parameters);

        $this->assertStringContainsString('About this activity.', $html);
    }

    /**
     * The types that show their description alone.
     *
     * @return array
     */
    public static function description_only_provider(): array {
        return [
            'label' => ['label'],
            'resource' => ['resource'],
            'folder' => ['folder'],
            'scorm' => ['scorm'],
            'imscp' => ['imscp'],
            'h5pactivity' => ['h5pactivity'],
        ];
    }

    /**
     * The description is shown above the parts.
     */
    public function test_the_description_comes_before_the_parts(): void {
        $parameters = [
            'introeditor' => ['text' => '<p>The water cycle.</p>', 'format' => 1, 'itemid' => 0],
            'mod_settings' => ['chapters' => [['title' => 'Rain']]],
        ];

        $html = $this->drawn('book', $parameters);

        $introat = strpos($html, 'The water cycle.');
        $partat = strpos($html, 'Rain');

        $this->assertLessThan($partat, $introat);
    }

    /**
     * An activity with nothing to show says so, instead of drawing an empty page.
     */
    public function test_an_activity_with_nothing_to_show_says_so(): void {
        $html = $this->drawn('book', []);
        $message = get_string('courseai_preview_empty', 'local_coursegen');

        $this->assertStringContainsString($message, $html);
    }

    /**
     * A list that is empty, missing or not a list is left out.
     *
     * @dataProvider broken_lists_provider
     * @param array $modsettings
     */
    public function test_a_list_that_is_not_there_is_left_out(array $modsettings): void {
        $parameters = ['introeditor' => ['text' => 'Intro text', 'format' => 1, 'itemid' => 0], 'mod_settings' => $modsettings];

        $html = $this->drawn('book', $parameters);

        $this->assertStringContainsString('Intro text', $html);
        $this->assertStringNotContainsString('Chapters', $html);
    }

    /**
     * The shapes a list can have when there is nothing to list.
     *
     * @return array
     */
    public static function broken_lists_provider(): array {
        return [
            'empty list' => [['chapters' => []]],
            'missing list' => [[]],
            'a text instead of a list' => [['chapters' => 'none']],
            'a number instead of a list' => [['chapters' => 4]],
        ];
    }

    /**
     * A part with no title and no text is still counted; one with only a title shows just the title.
     */
    public function test_a_part_with_only_a_title_shows_the_title(): void {
        $parameters = ['mod_settings' => ['chapters' => [['title' => 'Only a title']]]];

        $html = $this->drawn('book', $parameters);

        $this->assertStringContainsString('Only a title', $html);
    }

    /**
     * The text a model wrote is cleaned before it is shown, and a title is shown as plain text.
     */
    public function test_what_the_model_wrote_is_cleaned(): void {
        $parameters = ['mod_settings' => ['chapters' => [[
            'title' => '<b>Bold</b><script>alert(1)</script> title',
            'content_editor' => ['text' => '<p>Safe</p><script>alert(2)</script>', 'format' => 1, 'itemid' => 0],
        ]]]];

        $html = $this->drawn('book', $parameters);

        $this->assertStringContainsString('Safe', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    /**
     * A list inside another (the criteria of a rubric) is found by its path, and a path that breaks says nothing.
     */
    public function test_a_nested_list_is_found_by_its_path(): void {
        $nested = ['mod_settings' => ['rubric' => ['criteria' => [['description' => 'Depth']]]]];
        $found = $this->drawn('assign', $nested);
        $broken = $this->drawn('assign', ['mod_settings' => ['rubric' => 'none']]);
        $message = get_string('courseai_preview_empty', 'local_coursegen');

        $this->assertStringContainsString('Depth', $found);
        $this->assertStringContainsString($message, $broken);
    }

    /**
     * A type nobody declared shows its description and nothing else, without failing.
     */
    public function test_an_undeclared_type_shows_its_description(): void {
        $parameters = ['introeditor' => ['text' => 'Plain intro', 'format' => 1, 'itemid' => 0], 'mod_settings' => ['x' => [1]]];

        $html = $this->drawn('bigbluebuttonbn', $parameters);

        $this->assertStringContainsString('Plain intro', $html);
    }

    /**
     * An address with no value is not listed as a fact.
     */
    public function test_a_fact_without_a_value_is_left_out(): void {
        $html = $this->drawn('url', ['externalurl' => '   ']);

        $this->assertStringNotContainsString('Address:', $html);
    }

    /**
     * The declarations name the types that show lists, and none shows the same list twice.
     */
    public function test_the_declarations_name_their_types(): void {
        $names = settings_preview_declarations::modnames();

        $this->assertContains('book', $names);
        $this->assertContains('url', $names);
        $unique = array_unique($names);
        $labelgroups = settings_preview_declarations::groups_of('label');
        $bookfacts = settings_preview_declarations::facts_of('book');

        $this->assertSame(count($names), count($unique));
        $this->assertSame([], $labelgroups);
        $this->assertSame([], $bookfacts);
    }

    /**
     * Every declared group names a language string that exists.
     */
    public function test_every_declared_label_is_a_language_string(): void {
        $stringmanager = get_string_manager();
        $modnames = settings_preview_declarations::modnames();
        foreach ($modnames as $modname) {
            $this->assert_labels_exist($stringmanager, $modname);
        }
    }

    /**
     * The labels of one type exist.
     *
     * @param \core_string_manager $stringmanager
     * @param string $modname
     */
    private function assert_labels_exist(\core_string_manager $stringmanager, string $modname): void {
        $groups = settings_preview_declarations::groups_of($modname);
        $facts = settings_preview_declarations::facts_of($modname);
        $labels = array_column($groups, 'label');
        foreach ($facts as [, $factlabel]) {
            $labels[] = $factlabel;
        }
        $this->assert_strings_exist($stringmanager, $labels);
    }

    /**
     * Every language string of a list exists.
     *
     * @param \core_string_manager $stringmanager
     * @param string[] $labels
     */
    private function assert_strings_exist(\core_string_manager $stringmanager, array $labels): void {
        foreach ($labels as $label) {
            $exists = $stringmanager->string_exists($label, 'local_coursegen');
            $this->assertTrue($exists, $label);
        }
    }
}

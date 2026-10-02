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

namespace local_coursegen\local\backup;

/**
 * Tests for structure_node_resolver: a placeholder is named after the file area that holds the file.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\backup\structure_node_resolver
 */
final class structure_node_resolver_test extends \advanced_testcase {
    /**
     * Module types whose elements annotate more than one file area, each text field with its own.
     *
     * Each case: node field => [component, filearea, itemid the file is stored under, item id
     * segment its address carries], plus the annotations the module declares (component,
     * filearea, itemid or null for none). The "intro" area is served by core with no item id;
     * every other area is addressed with the item id its file is stored under.
     *
     * @return array
     */
    public static function multi_area_modules(): array {
        return [
            'page: intro and content' => [
                ['intro' => ['mod_page', 'intro', 0, ''], 'content' => ['mod_page', 'content', 0, '0/']],
                [['mod_page', 'intro', null], ['mod_page', 'content', null]],
            ],
            'lesson: page text and answers' => [
                ['contents' => ['mod_lesson', 'page_contents', 7, '7/'], 'answer' => ['mod_lesson', 'page_answers', 8, '8/']],
                [['mod_lesson', 'page_contents', 7], ['mod_lesson', 'page_answers', 8]],
            ],
            'assign: intro and activity' => [
                ['intro' => ['mod_assign', 'intro', 0, ''], 'activity' => ['mod_assign', 'activity', 0, '0/']],
                [['mod_assign', 'intro', null], ['mod_assign', 'activity', null]],
            ],
            'feedback: intro and thank-you page' => [
                ['intro' => ['mod_feedback', 'intro', 0, ''], 'pageaftersub' => ['mod_feedback', 'page_after_submit', 0, '0/']],
                [['mod_feedback', 'intro', null], ['mod_feedback', 'page_after_submit', null]],
            ],
            'forum: intro only' => [
                ['intro' => ['mod_forum', 'intro', 0, '']],
                [['mod_forum', 'intro', null]],
            ],
            'label: intro only' => [
                ['intro' => ['mod_label', 'intro', 0, '']],
                [['mod_label', 'intro', null]],
            ],
        ];
    }

    /**
     * Every field points at the area its own file is in, whatever the module and however many areas it declares.
     *
     * @dataProvider multi_area_modules
     * @param array $fields node field => [component, filearea, stored itemid, address item id segment]
     * @param array $declared [component, filearea, annotated itemid or null]
     */
    public function test_each_field_is_resolved_to_the_area_holding_its_file(array $fields, array $declared): void {
        $this->resetAfterTest();
        $contextid = \context_system::instance()->id;

        $node = [];
        foreach ($fields as $field => [$component, $filearea, $itemid, $segment]) {
            $this->store($contextid, $component, $filearea, $itemid, $field . '.png');
            $node[$field] = '<img src="@@PLUGINFILE@@/' . $field . '.png">';
        }

        $resolved = structure_node_resolver::with_file_addresses($node, $this->annotations($declared), $contextid);

        foreach ($fields as $field => [$component, $filearea, $itemid, $segment]) {
            $this->assertStringNotContainsString('@@PLUGINFILE@@', $resolved[$field]);
            $this->assertStringContainsString("/$contextid/$component/$filearea/$segment$field.png", $resolved[$field]);
        }
    }

    /**
     * A file name with characters a URL encodes is still found in its area.
     */
    public function test_an_encoded_file_name_is_found_in_its_area(): void {
        $this->resetAfterTest();
        $contextid = \context_system::instance()->id;
        $this->store($contextid, 'mod_page', 'content', 0, 'my image.png');

        $node = ['content' => '<img src="@@PLUGINFILE@@/my%20image.png">'];
        $declared = [['mod_page', 'intro', null], ['mod_page', 'content', null]];
        $resolved = structure_node_resolver::with_file_addresses($node, $this->annotations($declared), $contextid);

        $this->assertStringContainsString("/$contextid/mod_page/content/0/my%20image.png", $resolved['content']);
    }

    /**
     * Two placeholders in one text can live in different areas.
     */
    public function test_placeholders_of_one_text_are_resolved_one_by_one(): void {
        $this->resetAfterTest();
        $contextid = \context_system::instance()->id;
        $this->store($contextid, 'mod_page', 'intro', 0, 'a.png');
        $this->store($contextid, 'mod_page', 'content', 0, 'b.png');

        $node = ['content' => '<img src="@@PLUGINFILE@@/a.png"><img src="@@PLUGINFILE@@/b.png">'];
        $declared = [['mod_page', 'intro', null], ['mod_page', 'content', null]];
        $resolved = structure_node_resolver::with_file_addresses($node, $this->annotations($declared), $contextid);

        $this->assertStringContainsString("/$contextid/mod_page/intro/a.png", $resolved['content']);
        $this->assertStringContainsString("/$contextid/mod_page/content/0/b.png", $resolved['content']);
    }

    /**
     * A file stored nowhere keeps the first declared area, as before.
     */
    public function test_a_file_that_is_stored_nowhere_keeps_the_first_declared_area(): void {
        $this->resetAfterTest();
        $contextid = \context_system::instance()->id;

        $node = ['content' => '<img src="@@PLUGINFILE@@/missing.png">'];
        $declared = [['mod_page', 'intro', null], ['mod_page', 'content', null]];
        $resolved = structure_node_resolver::with_file_addresses($node, $this->annotations($declared), $contextid);

        $this->assertStringContainsString("/$contextid/mod_page/intro/missing.png", $resolved['content']);
    }

    /**
     * Text with no placeholder, and an element with no annotation, are returned untouched.
     */
    public function test_text_without_placeholders_or_annotations_is_untouched(): void {
        $this->resetAfterTest();
        $contextid = \context_system::instance()->id;
        $node = ['intro' => '<p>Plain</p>', 'content' => '<img src="@@PLUGINFILE@@/a.png">'];

        $this->assertSame($node, structure_node_resolver::with_file_addresses($node, [], $contextid));
        $declared = [['mod_page', 'intro', null]];
        $resolved = structure_node_resolver::with_file_addresses($node, $this->annotations($declared), $contextid);
        $this->assertSame('<p>Plain</p>', $resolved['intro']);
    }

    /**
     * Store one file.
     *
     * @param int $contextid
     * @param string $component
     * @param string $filearea
     * @param int $itemid
     * @param string $filename
     */
    private function store(int $contextid, string $component, string $filearea, int $itemid, string $filename): void {
        get_file_storage()->create_file_from_string([
            'contextid' => $contextid,
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
        ], 'x');
    }

    /**
     * Annotations in the shape backup_nested_element::get_file_annotations() returns.
     *
     * @param array $declared [component, filearea, itemid or null]
     * @return array component => filearea => info
     */
    private function annotations(array $declared): array {
        $annotations = [];
        foreach ($declared as [$component, $filearea, $itemid]) {
            $annotations[$component][$filearea] = $this->annotation($itemid);
        }
        return $annotations;
    }

    /**
     * One annotation: the element holding the item id, or none.
     *
     * @param int|null $itemid
     * @return \stdClass
     */
    private function annotation(?int $itemid): \stdClass {
        if ($itemid === null) {
            return (object) ['element' => null];
        }
        $final = new \backup_final_element('itemid');
        $final->set_value($itemid);
        return (object) ['element' => $final];
    }
}

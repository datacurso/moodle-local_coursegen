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

use local_coursegen\local\backup\activity_reader;
use local_coursegen\local\service\activity_from_structure;
use local_coursegen\local\service\create_mod_service;
use local_coursegen\local\structure\row_locator;
use local_coursegen\local\structure\tree_changes;

/**
 * Tests for activity_from_structure: an activity created from the rewritten tree of its template activity.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\activity_from_structure
 * @covers     \local_coursegen\local\service\new_activity_files
 * @covers     \local_coursegen\local\service\template_activity_duplicator
 */
final class activity_from_structure_test extends \advanced_testcase {
    /** @var string[] The keys whose text the agent rewrites. */
    private const TEXT_KEYS = ['name', 'intro', 'content', 'title', 'description', 'message', 'subject', 'text', 'answer'];

    /**
     * The types of activity whose generator needs no package or file to make an instance.
     *
     * H5P and IMS content packages need a package file to exist and are covered by the check on a real site.
     *
     * @return array[] Type => [type].
     */
    public static function types_provider(): array {
        $names = [
            'assign', 'book', 'choice', 'data', 'feedback', 'folder', 'forum', 'glossary', 'label', 'lesson', 'page',
            'quiz', 'resource', 'scorm', 'url', 'wiki', 'workshop',
        ];
        $cases = [];
        foreach ($names as $name) {
            $cases[$name] = [$name];
        }
        return $cases;
    }

    /**
     * A template course with one activity of a type, and an empty course for the new one.
     *
     * @param string $modname The type of activity.
     * @return array {template, target, record}
     */
    private function template_and_target(string $modname): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $template = $generator->create_course(['enablecompletion' => 1, 'numsections' => 2]);
        $target = $generator->create_course(['enablecompletion' => 1, 'numsections' => 2]);
        $record = $generator->create_module($modname, [
            'course' => $template->id,
            'name' => 'Template ' . $modname,
            'intro' => '<p>Template intro</p>',
        ]);
        return ['template' => $template, 'target' => $target, 'record' => $record];
    }

    /**
     * The result row the agent sends for an activity it rewrote.
     *
     * @param string $modname The type of activity.
     * @param int $cmid The template activity.
     * @param array $rewritten The rewritten tree.
     * @return array
     */
    private function result_row(string $modname, int $cmid, array $rewritten): array {
        return [
            'resource_type' => $modname,
            'cmid' => $cmid,
            'parameters' => [
                'name' => 'Rewritten ' . $modname,
                'structure' => $rewritten,
                'source_cmid' => $cmid,
                'from_structure' => true,
            ],
        ];
    }

    /**
     * The tree with a suffix on every text of the keys the agent rewrites.
     *
     * @param array $tree A tree or one level of it.
     * @return array
     */
    private function rewritten(array $tree): array {
        foreach ($tree as $key => $value) {
            if (is_array($value)) {
                $tree[$key] = $this->rewritten($value);
                continue;
            }
            if (is_string($value) && in_array((string) $key, self::TEXT_KEYS, true) && !is_numeric($value)) {
                $tree[$key] = $value . ' (rewritten)';
            }
        }
        return $tree;
    }

    /**
     * How many rows every list of a tree has, by the path of the list.
     *
     * @param array $tree A tree or one level of it.
     * @param string $path The path of the level.
     * @return array Path => number of rows.
     */
    private function list_sizes(array $tree, string $path = ''): array {
        $sizes = [];
        foreach ($tree as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            $label = $key;
            if (is_int($key)) {
                $label = '#';
            }
            $here = $path . '/' . $label;
            $sizes[$here] = count($value);
            $inner = $this->list_sizes($value, $here);
            $sizes = array_merge($sizes, $inner);
        }
        return $sizes;
    }

    /**
     * The creation reads the template, copies it and writes the rewritten texts: the structure, the rows and the
     * settings of the template activity are the ones of the copy.
     *
     * @dataProvider types_provider
     * @param string $modname The type of activity.
     */
    public function test_the_copy_keeps_the_structure_and_gets_the_rewritten_texts(string $modname): void {
        global $DB;

        $made = $this->template_and_target($modname);
        $record = $made['record'];
        $modinfo = get_fast_modinfo($made['template']);
        $templatecm = $modinfo->get_cm($record->cmid);
        $source = activity_reader::read_with_sources($templatecm);
        $rewritten = $this->rewritten($source['tree']);
        $changes = tree_changes::between($source['tree'], $rewritten);
        $row = $this->result_row($modname, (int) $record->cmid, $rewritten);

        $actual = activity_from_structure::applies($row);
        $this->assertTrue($actual);
        $newcm = activity_from_structure::create($row, $made['target'], 1, (int) $made['template']->id);

        $created = $DB->get_record('course_modules', ['id' => $newcm->coursemodule], '*', MUST_EXIST);
        $this->assertEquals($made['target']->id, $created->course);
        $targetinfo = get_fast_modinfo($made['target']);
        $copycm = $targetinfo->get_cm($newcm->coursemodule);
        $this->assertSame($modname, $copycm->modname);
        $this->assertEquals(1, $copycm->sectionnum);
        $copy = activity_reader::read_with_sources($copycm);
        $actual = $this->list_sizes($source['tree']);
        $second = $this->list_sizes($copy['tree']);
        $this->assertSame($actual, $second);
        $this->assertNotEmpty($changes, 'the type has texts the agent rewrites');
        foreach ($changes as $change) {
            $place = row_locator::locate($copy['tree'], $change->path, $copy['tables'], $copy['aliases']);
            $actual = $change->describe();
            $this->assertNotNull($place, $actual);
            $stored = $DB->get_field($place->table, $place->column, ['id' => $place->id]);
            $actual = $change->describe();
            $this->assertSame($change->value, $stored, $actual);
        }
    }

    /**
     * The template activity is not touched by the creation.
     *
     * @dataProvider types_provider
     * @param string $modname The type of activity.
     */
    public function test_the_template_activity_is_not_touched(string $modname): void {
        $made = $this->template_and_target($modname);
        $record = $made['record'];
        $modinfo = get_fast_modinfo($made['template']);
        $templatecm = $modinfo->get_cm($record->cmid);
        $before = activity_reader::read_with_sources($templatecm);
        $rewritten = $this->rewritten($before['tree']);
        $row = $this->result_row($modname, (int) $record->cmid, $rewritten);

        activity_from_structure::create($row, $made['target'], 1, (int) $made['template']->id);

        $aftercm = $modinfo->get_cm($record->cmid);
        $after = activity_reader::read_with_sources($aftercm);
        $this->assertSame($before['tree'], $after['tree']);
    }

    /**
     * The completion and the visibility of the template activity are the ones of the copy.
     */
    public function test_the_settings_of_the_template_activity_survive(): void {
        global $DB;

        $made = $this->template_and_target('forum');
        $cmid = (int) $made['record']->cmid;
        $DB->set_field('course_modules', 'completion', COMPLETION_TRACKING_MANUAL, ['id' => $cmid]);
        $DB->set_field('course_modules', 'visible', 0, ['id' => $cmid]);
        rebuild_course_cache((int) $made['template']->id, true);
        $modinfo = get_fast_modinfo($made['template']);
        $templatecm = $modinfo->get_cm($cmid);
        $source = activity_reader::read_with_sources($templatecm);
        $rewritten = $this->rewritten($source['tree']);
        $row = $this->result_row('forum', $cmid, $rewritten);

        $newcm = activity_from_structure::create($row, $made['target'], 1, (int) $made['template']->id);

        $created = $DB->get_record('course_modules', ['id' => $newcm->coursemodule], '*', MUST_EXIST);
        $this->assertEquals(COMPLETION_TRACKING_MANUAL, $created->completion);
        $this->assertEquals(0, $created->visible);
    }

    /**
     * The creation goes through the service that every other activity goes through.
     */
    public function test_the_creation_service_uses_the_tree_when_the_agent_says_so(): void {
        $made = $this->template_and_target('page');
        $record = $made['record'];
        $modinfo = get_fast_modinfo($made['template']);
        $templatecm = $modinfo->get_cm($record->cmid);
        $source = activity_reader::read_with_sources($templatecm);
        $rewritten = $this->rewritten($source['tree']);
        $row = $this->result_row('page', (int) $record->cmid, $rewritten);

        $newcm = create_mod_service::create_from_ai_result($row, $made['target'], 1, null, (int) $made['template']->id);

        $targetinfo = get_fast_modinfo($made['target']);
        $copycm = $targetinfo->get_cm($newcm->coursemodule);
        $this->assertSame('page', $copycm->modname);
        $this->assertSame('Template page (rewritten)', $copycm->name);
    }

    /**
     * An activity is created from the tree only when the agent says so and sends a tree and a template activity.
     */
    public function test_it_applies_only_to_an_activity_made_from_its_tree(): void {
        $tree = ['book' => [['id' => '1']]];
        $madefromtree = ['cmid' => 5, 'parameters' => ['from_structure' => true, 'structure' => $tree]];
        $notstated = ['cmid' => 5, 'parameters' => ['structure' => $tree]];
        $notmade = ['cmid' => 5, 'parameters' => ['from_structure' => false, 'structure' => $tree]];
        $notree = ['cmid' => 5, 'parameters' => ['from_structure' => true, 'structure' => []]];
        $notemplate = ['parameters' => ['from_structure' => true, 'structure' => $tree]];
        $fromparameter = ['parameters' => ['from_structure' => true, 'structure' => $tree, 'source_cmid' => 7]];

        $actual = activity_from_structure::applies($madefromtree);
        $this->assertTrue($actual);
        $actual = activity_from_structure::applies($fromparameter);
        $this->assertTrue($actual);
        $actual = activity_from_structure::applies($notstated);
        $this->assertFalse($actual);
        $actual = activity_from_structure::applies($notmade);
        $this->assertFalse($actual);
        $actual = activity_from_structure::applies($notree);
        $this->assertFalse($actual);
        $actual = activity_from_structure::applies($notemplate);
        $this->assertFalse($actual);
        $actual = activity_from_structure::applies([]);
        $this->assertFalse($actual);
    }

    /**
     * A template activity that no longer exists is an error of that activity, not a created copy.
     */
    public function test_a_missing_template_activity_is_an_error(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $target = $generator->create_course();
        $row = [
            'resource_type' => 'forum',
            'cmid' => 987654,
            'parameters' => ['structure' => ['forum' => [['id' => '1']]], 'source_cmid' => 987654, 'from_structure' => true],
        ];

        $this->expectException(\moodle_exception::class);
        activity_from_structure::create($row, $target, 1, null);
    }

    /**
     * A section the new course does not have yet is created for the copy.
     */
    public function test_a_missing_section_is_created(): void {
        $made = $this->template_and_target('label');
        $record = $made['record'];
        $modinfo = get_fast_modinfo($made['template']);
        $templatecm = $modinfo->get_cm($record->cmid);
        $source = activity_reader::read_with_sources($templatecm);
        $rewritten = $this->rewritten($source['tree']);
        $row = $this->result_row('label', (int) $record->cmid, $rewritten);

        $newcm = activity_from_structure::create($row, $made['target'], 5, (int) $made['template']->id);

        $targetinfo = get_fast_modinfo($made['target']);
        $copycm = $targetinfo->get_cm($newcm->coursemodule);
        $actual = $copycm->sectionnum;
        $this->assertEquals(5, $actual);
    }
}

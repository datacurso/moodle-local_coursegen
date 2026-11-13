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

use local_coursegen\local\space\file_space;
use local_coursegen\local\space\space_scope;
use local_coursegen\local\space\space_selection;
use local_coursegen\local\service\create_mod_service;
use local_coursegen\tests\fixtures\file_scenarios;
use local_coursegen\utils\generated_file_cache;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../fixtures/file_scenarios.php');
require_once($CFG->libdir . '/testing/generator/lib.php');

/**
 * Every file a text of a new activity references reaches the activity, whatever the module and the field.
 *
 * Each module that is created from a result of the AI service is built through the service, with a source activity
 * that really owns the files in those fields, once for each place a file comes from: the template, the AI
 * service and the teacher.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\files\activity_file_pass
 * @covers     \local_coursegen\local\files\text_carrier_collector
 * @covers     \local_coursegen\local\files\quiz_question_carriers
 */
final class activity_file_pass_test extends \advanced_testcase {
    /** @var int Counts the files brought by the teacher, so each has its own place and session. */
    private int $brought = 0;

    /** @var file_space[] The spaces whose files the texts point at. */
    private array $spaces = [];

    /** @var \stored_file[] The teacher's file of each space, by cmid. */
    private array $teacherfiles = [];

    protected function tearDown(): void {
        space_scope::leave();
        parent::tearDown();
    }

    /**
     * Every module created from a result, once for each source of files.
     *
     * @return array
     */
    public static function matrix_provider(): array {
        $modules = [
            'page', 'label', 'forum', 'lesson', 'book', 'glossary', 'wiki', 'quiz',
            'assign', 'feedback', 'workshop', 'choice', 'data',
        ];
        $cases = [];
        foreach ($modules as $module) {
            $cases = array_merge($cases, self::kinds_of($module));
        }
        return $cases;
    }

    /**
     * The three sources of files for one module.
     *
     * @param string $module
     * @return array
     */
    private static function kinds_of(string $module): array {
        return [
            $module . ' with template files' => [$module, 'template'],
            $module . ' with generated files' => [$module, 'generated'],
            $module . ' with teacher files' => [$module, 'teacher'],
        ];
    }

    /**
     * The scenario of a module.
     *
     * @param string $module
     * @return array
     */
    private function scenario(string $module): array {
        foreach (file_scenarios::all() as $scenario) {
            if ($scenario['module'] === $module) {
                return $scenario;
            }
        }
        $this->fail('No scenario for ' . $module);
    }

    /**
     * A file of the teacher for a space of the template, as the address of the template's file the text points at.
     *
     * The space stays in scope until the test leaves it.
     *
     * @param string $name The teacher's file name.
     * @param string $content
     * @return string
     */
    private function brought_address(string $name, string $content): string {
        global $USER;
        $this->brought++;
        $course = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $resourcecontext = \context_module::instance($resource->cmid);
        $fs = get_file_storage();
        $fs->delete_area_files($resourcecontext->id, 'mod_resource', 'content');
        $templatefile = $fs->create_file_from_string([
            'contextid' => $resourcecontext->id, 'component' => 'mod_resource', 'filearea' => 'content', 'itemid' => 0,
            'filepath' => '/', 'filename' => 'template' . $this->brought . '.png',
        ], 'TEMPLATE');
        $usercontext = \context_user::instance($USER->id);
        $teacherfile = $fs->create_file_from_string([
            'contextid' => $usercontext->id, 'component' => 'local_coursegen', 'filearea' => 'spacefile',
            'itemid' => 5000 + $this->brought, 'filepath' => '/' . $resource->cmid . '/', 'filename' => $name,
        ], $content);
        $this->spaces[] = new file_space((int) $resource->cmid, 'Guide', '', false, [$templatefile]);
        $this->teacherfiles[(int) $resource->cmid] = $teacherfile;
        $url = \moodle_url::make_pluginfile_url(
            $resourcecontext->id,
            'mod_resource',
            'content',
            1,
            '/',
            $templatefile->get_filename()
        );
        return $url->out(false);
    }

    /**
     * A file the AI service made, stored where the creation finds it.
     *
     * @param string $name
     * @param string $content
     * @return array The entry of the result's generated_files.
     */
    private function generated_entry(string $name, string $content): array {
        $entry = [
            'filename' => $name,
            'mimetype' => 'image/png',
            'size' => strlen($content),
            'thread_id' => 'thread-1',
            'file_id' => md5($name) . '.png',
        ];
        get_file_storage()->create_file_from_string(generated_file_cache::file_record($entry), $content);
        return $entry;
    }

    /**
     * The item id a slot's files are stored under for a row.
     *
     * @param mixed $item 0, 'row', 'qid' or 'sub'.
     * @param \stdClass|null $row
     * @return int
     */
    private function item_of($item, ?\stdClass $row): int {
        global $DB;
        if ($item === 'row') {
            return (int) $row->id;
        }
        if ($item === 'qid') {
            return (int) $row->questionid;
        }
        if ($item === 'sub') {
            return (int) $DB->get_field('wiki_pages', 'subwikiid', ['id' => $row->pageid]);
        }
        return 0;
    }

    /**
     * The nth row of a slot's table for an activity.
     *
     * @param array $slot
     * @param int $instance
     * @return \stdClass|null
     */
    private function row_of(array $slot, int $instance): ?\stdClass {
        $rows = array_values($slot['rows']($instance));
        return $rows[$slot['n']] ?? null;
    }

    /**
     * The source activity with a file in each slot, and the text that points at it.
     *
     * @param array $scenario
     * @param \stdClass $course The template's course.
     * @return array{0: array<string,string>, 1: array<string,string>} The text of each slot and the file name of each.
     */
    private function template_texts(array $scenario, \stdClass $course): array {
        $plain = array_map(static fn($slot) => '<p>plain</p>', $scenario['slots']);
        $result = $scenario['build']($plain, []);
        $result['parameters']['name'] = 'Source';
        $source = create_mod_service::create_from_ai_result($result, $course, 1);
        $context = \context_module::instance($source->coursemodule);
        $texts = [];
        $names = [];
        foreach ($scenario['slots'] as $label => $slot) {
            $names[$label] = $scenario['module'] . $label . '.png';
            $row = $this->row_of($slot, (int) $source->instance);
            get_file_storage()->create_file_from_string([
                'contextid' => $context->id, 'component' => $slot['component'], 'filearea' => $slot['area'],
                'itemid' => $this->item_of($slot['item'], $row), 'filepath' => '/', 'filename' => $names[$label],
                'author' => 'Template Author', 'license' => 'cc',
            ], 'CONTENT-' . $label);
            $address = \moodle_url::make_pluginfile_url(
                $context->id, $slot['component'], $slot['area'], $this->item_of($slot['item'], $row), '/', $names[$label]
            );
            $texts[$label] = '<p><img src="' . $address->out(false) . '" alt="a"></p>';
        }
        return [$texts, $names];
    }

    /**
     * Every field of the module receives its file from the template, the AI service or the teacher.
     *
     * @dataProvider matrix_provider
     * @param string $module
     * @param string $kind
     */
    public function test_every_text_of_a_new_activity_receives_its_file(string $module, string $kind): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $scenario = $this->scenario($module);
        $sourcecourse = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $PAGE->set_course($course);
        $texts = [];
        $names = [];
        $entries = [];
        if ($kind === 'template') {
            [$texts, $names] = $this->template_texts($scenario, $sourcecourse);
        } else {
            [$texts, $names, $entries] = $this->other_texts($scenario, $kind);
        }

        $result = $scenario['build']($texts, $names);
        $result['generated_files'] = $entries;
        $source = null;
        if ($kind === 'template') {
            $source = (int) $sourcecourse->id;
        }
        if ($kind === 'teacher') {
            space_scope::enter(new space_selection($this->spaces, $this->teacherfiles));
        }
        $cm = create_mod_service::create_from_ai_result($result, $course, 1, null, $source);

        $this->assert_every_slot($scenario, $cm, $names, $kind);
    }

    /**
     * The texts that point at the AI service's or the teacher's files.
     *
     * @param array $scenario
     * @param string $kind 'generated' or 'teacher'.
     * @return array{0: array, 1: array, 2: array}
     */
    private function other_texts(array $scenario, string $kind): array {
        $texts = [];
        $names = [];
        $entries = [];
        foreach ($scenario['slots'] as $label => $slot) {
            $names[$label] = $scenario['module'] . $label . '.png';
            $texts[$label] = '<p>plain</p>';
            if (!empty($slot['asis'])) {
                continue;
            }
            if ($kind === 'generated') {
                $entries[] = $this->generated_entry($names[$label], 'CONTENT-' . $label);
                $source = '@@PLUGINFILE@@/' . $names[$label];
            } else {
                $source = $this->brought_address($names[$label], 'CONTENT-' . $label);
            }
            $texts[$label] = '<p><img src="' . $source . '" alt="a"></p>';
        }
        return [$texts, $names, $entries];
    }

    /**
     * Each slot of the new activity holds its file, with the content it had, and its text names it.
     *
     * @param array $scenario
     * @param \stdClass $cm
     * @param array $names
     * @param string $kind
     */
    private function assert_every_slot(array $scenario, $cm, array $names, string $kind): void {
        $context = \context_module::instance($cm->coursemodule);
        foreach ($scenario['slots'] as $label => $slot) {
            $row = $this->row_of($slot, (int) $cm->instance);
            $this->assertNotNull($row, "$label: the row exists");
            $text = (string) $row->{$slot['column']};
            if (!empty($slot['asis'])) {
                $this->assertStringNotContainsString('@@PLUGINFILE@@', $text, "$label: shown as written");
                continue;
            }
            $file = get_file_storage()->get_file(
                $context->id, $slot['component'], $slot['area'], $this->item_of($slot['item'], $row), '/', $names[$label]
            );
            $this->assertNotFalse($file, "$label: the file is in {$slot['component']}/{$slot['area']}");
            $this->assertSame('CONTENT-' . $label, $file->get_content(), "$label: same content");
            $this->assertStringContainsString('@@PLUGINFILE@@/' . $names[$label], $text, "$label: the text names it");
            $this->assertStringNotContainsString('pluginfile.php', $text, "$label: no address left");
            if ($kind === 'template') {
                $this->assertSame('Template Author', $file->get_author(), "$label: author kept");
                $this->assertSame('cc', $file->get_license(), "$label: license kept");
            }
        }
    }
}

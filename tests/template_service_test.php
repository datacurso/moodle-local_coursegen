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

use local_coursegen\local\template\plain_text;
use local_coursegen\local\template\template_input;
use local_coursegen\local\template\template_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/template_test_helper.php');

/**
 * Tests for saving, loading and deleting templates.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\template\template_service
 */
final class template_service_test extends \advanced_testcase {
    use template_test_helper;

    /** @var template_service Service under test. */
    private template_service $service;

    /**
     * Create the service.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->service = new template_service();
    }

    /**
     * A new template stores the course, the name and one row per activity.
     */
    public function test_creates_a_template_with_its_activities(): void {
        global $DB;
        [$course, $page, $quiz, $label] = $this->make_course();
        $items = [
            ['cmid' => $page, 'action' => 'ai', 'instruction' => 'Write it for beginners'],
            ['cmid' => $quiz, 'action' => 'keep'],
            ['cmid' => $label, 'action' => 'ai', 'instruction' => ''],
        ];

        $id = $this->save_items($course, $items);

        $template = $DB->get_record('local_coursegen_template', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame((int) $course->id, (int) $template->courseid);
        $this->assertSame('Marketing', $template->name);
        $this->assertSame('Base course', $template->description);
        $this->assertSame(2, (int) $template->usermodified);
        $rows = $DB->get_records('local_coursegen_tpl_item', ['templateid' => $id], '', 'cmid, action, instruction');
        $this->assertCount(3, $rows);
        $this->assertSame('Write it for beginners', $rows[$page]->instruction);
        $this->assertNull($rows[$quiz]->instruction);
        $this->assertSame('ai', $rows[$label]->action);
        $this->assertNull($rows[$label]->instruction, 'an empty instruction is stored as null');
    }

    /**
     * A kept activity never stores an instruction, even if one is sent.
     */
    public function test_a_kept_activity_drops_its_instruction(): void {
        global $DB;
        [$course, $page] = $this->make_course();

        $id = $this->save_items($course, [['cmid' => $page, 'action' => 'keep', 'instruction' => 'ignored']]);

        $field = $DB->get_field('local_coursegen_tpl_item', 'instruction', ['templateid' => $id]);
        $this->assertNull($field);
    }

    /**
     * An instruction made of blanks is stored as null.
     */
    public function test_blank_instruction_is_stored_as_null(): void {
        global $DB;
        [$course, $page] = $this->make_course();

        $id = $this->save_items($course, [['cmid' => $page, 'action' => 'ai', 'instruction' => " \n\t "]]);

        $field = $DB->get_field('local_coursegen_tpl_item', 'instruction', ['templateid' => $id]);
        $this->assertNull($field);
    }

    /**
     * Hostile, long and unusual instructions are stored as text without changing it.
     */
    public function test_unusual_instructions_are_stored_as_typed(): void {
        global $DB;
        [$course, $page, $quiz, $label] = $this->make_course();
        $longtext = str_repeat('x', plain_text::MAX_LENGTH);
        $longitem = ['cmid' => $label, 'action' => 'ai', 'instruction' => $longtext];
        $items = [
            ['cmid' => $page, 'action' => 'ai', 'instruction' => '<script>alert("x")</script> <b>bold</b>'],
            ['cmid' => $quiz, 'action' => 'ai', 'instruction' => 'Tono amable 😀 áéíóú ñ مرحبا'],
            $longitem,
        ];

        $id = $this->save_items($course, $items);

        $rows = $DB->get_records('local_coursegen_tpl_item', ['templateid' => $id], '', 'cmid, instruction');
        $this->assertSame('<script>alert("x")</script> <b>bold</b>', $rows[$page]->instruction);
        $this->assertSame('Tono amable 😀 áéíóú ñ مرحبا', $rows[$quiz]->instruction);
        $length = \core_text::strlen($rows[$label]->instruction);
        $this->assertSame(plain_text::MAX_LENGTH, $length);
    }

    /**
     * An instruction over the limit rejects the whole save.
     */
    public function test_too_long_instruction_saves_nothing(): void {
        global $DB;
        [$course, $page] = $this->make_course();

        try {
            $toolong = str_repeat('x', plain_text::MAX_LENGTH + 1);
            $item = ['cmid' => $page, 'action' => 'ai', 'instruction' => $toolong];
            $this->save_items($course, [$item]);
            $this->fail('A too long instruction must be rejected.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('error_text_too_long', $exception->errorcode);
        }

        $count = $DB->count_records('local_coursegen_template');
        $this->assertSame(0, $count);
    }

    /**
     * Names are cleaned: trimmed, with their blanks collapsed and without control characters.
     */
    public function test_name_is_cleaned(): void {
        global $DB;
        [$course] = $this->make_course();

        $id = $this->service->save(0, (int) $course->id, "  Digital \n  marketing\x00 \t 2026 ", null, [], 2);

        $field = $DB->get_field('local_coursegen_template', 'name', ['id' => $id]);
        $this->assertSame('Digital marketing 2026', $field);
        $description = $DB->get_field('local_coursegen_template', 'description', ['id' => $id]);
        $this->assertNull($description);
    }

    /**
     * Names of exactly the limit and with emoji are accepted.
     */
    public function test_name_of_the_limit_and_emoji_are_accepted(): void {
        [$course] = $this->make_course();
        $longname = str_repeat('a', template_input::MAX_NAME_LENGTH);
        $emojiname = str_repeat('😀', template_input::MAX_NAME_LENGTH);

        $savedid = $this->service->save(0, (int) $course->id, $longname, null, [], 2);
        $this->assertGreaterThan(0, $savedid);
        $savedid2 = $this->service->save(0, (int) $course->id, $emojiname, null, [], 2);
        $this->assertGreaterThan(0, $savedid2);
    }

    /**
     * Names that are empty or too long are rejected.
     *
     * @return array[]
     */
    public static function invalid_name_provider(): array {
        $toolongname = str_repeat('a', template_input::MAX_NAME_LENGTH + 1);

        return [
            'empty' => ['', 'error_template_name_required'],
            'blanks' => ["  \t\n ", 'error_template_name_required'],
            'only control characters' => ["\x00\x01", 'error_template_name_required'],
            'one over the limit' => [$toolongname, 'error_template_name_too_long'],
        ];
    }

    /**
     * An invalid name saves nothing.
     *
     * @dataProvider invalid_name_provider
     * @param string $name Name to try.
     * @param string $errorcode Error expected.
     */
    public function test_invalid_name_is_rejected(string $name, string $errorcode): void {
        global $DB;
        [$course] = $this->make_course();

        try {
            $this->service->save(0, (int) $course->id, $name, null, [], 2);
            $this->fail('The name must be rejected.');
        } catch (\moodle_exception $exception) {
            $this->assertSame($errorcode, $exception->errorcode);
        }

        $count = $DB->count_records('local_coursegen_template');
        $this->assertSame(0, $count);
    }

    /**
     * The front page, id 0, a negative id and a missing course are rejected.
     */
    public function test_invalid_courses_are_rejected(): void {
        foreach ([0, -5, SITEID, 99999] as $courseid) {
            try {
                $this->service->save(0, $courseid, 'Name', null, [], 2);
                $this->fail('Course ' . $courseid . ' must be rejected.');
            } catch (\moodle_exception $exception) {
                $this->assertSame('error_template_course_invalid', $exception->errorcode);
            }
        }
    }

    /**
     * Replacing a template that does not exist is rejected, and so is a negative id.
     */
    public function test_unknown_template_is_rejected(): void {
        [$course] = $this->make_course();

        foreach ([99999, -1] as $templateid) {
            try {
                $this->service->save($templateid, (int) $course->id, 'Name', null, [], 2);
                $this->fail('Template ' . $templateid . ' must be rejected.');
            } catch (\moodle_exception $exception) {
                $this->assertSame('error_template_not_found', $exception->errorcode);
            }
        }
    }

    /**
     * An activity of another course, a missing one and a repeated one are rejected and nothing is saved.
     */
    public function test_invalid_activities_save_nothing(): void {
        global $DB;
        [$course, $page] = $this->make_course();
        [, $otherpage] = $this->make_course();
        $cases = [
            'another course' => [[['cmid' => $otherpage, 'action' => 'keep']], 'error_template_cm_not_in_course'],
            'missing activity' => [[['cmid' => 987654, 'action' => 'keep']], 'error_template_cm_not_in_course'],
            'no cmid' => [[['action' => 'keep']], 'error_template_cm_not_in_course'],
            'repeated activity' => [
                [['cmid' => $page, 'action' => 'keep'], ['cmid' => $page, 'action' => 'ai']],
                'error_template_duplicate_cm',
            ],
            'invalid action' => [[['cmid' => $page, 'action' => 'delete']], 'error_template_action_invalid'],
            'empty action' => [[['cmid' => $page, 'action' => '']], 'error_template_action_invalid'],
            'not an entry' => [['text'], 'error_template_item_invalid'],
            'array instruction' => [[['cmid' => $page, 'action' => 'ai', 'instruction' => ['x']]], 'error_template_item_invalid'],
        ];

        foreach ($cases as $label => [$items, $errorcode]) {
            try {
                $this->save_items($course, $items);
                $this->fail($label . ' must be rejected.');
            } catch (\moodle_exception $exception) {
                $this->assertSame($errorcode, $exception->errorcode, $label);
            }
        }

        $count = $DB->count_records('local_coursegen_template');
        $this->assertSame(0, $count);
        $itemcount = $DB->count_records('local_coursegen_tpl_item');
        $this->assertSame(0, $itemcount);
    }

    /**
     * An activity that is being deleted cannot be saved.
     */
    public function test_activity_being_deleted_is_rejected(): void {
        global $DB;
        [$course, $page] = $this->make_course();
        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $page]);
        rebuild_course_cache((int) $course->id, true);

        $this->expectException(\moodle_exception::class);
        $message = get_string('error_template_cm_not_in_course', 'local_coursegen');
        $this->expectExceptionMessage($message);

        $this->save_items($course, [['cmid' => $page, 'action' => 'keep']]);
    }

    /**
     * A template can be saved without activities.
     */
    public function test_template_without_activities_is_valid(): void {
        global $DB;
        [$course] = $this->make_course();

        $id = $this->save_items($course, []);

        $count = $DB->count_records('local_coursegen_tpl_item', ['templateid' => $id]);
        $this->assertSame(0, $count);
    }

    /**
     * Saving again replaces the activities exactly and keeps the creation time.
     */
    public function test_resave_replaces_the_activities_exactly(): void {
        global $DB;
        [$course, $page, $quiz, $label] = $this->make_course();
        $id = $this->save_items($course, [
            ['cmid' => $page, 'action' => 'ai', 'instruction' => 'first'],
            ['cmid' => $quiz, 'action' => 'ai', 'instruction' => 'second'],
        ]);
        $created = (int) $DB->get_field('local_coursegen_template', 'timecreated', ['id' => $id]);

        $sameid = $this->service->save($id, (int) $course->id, 'Renamed', 'New description', [
            ['cmid' => $label, 'action' => 'ai', 'instruction' => 'third'],
            ['cmid' => $page, 'action' => 'keep'],
        ], 3);

        $this->assertSame($id, $sameid);
        $count = $DB->count_records('local_coursegen_template');
        $this->assertSame(1, $count);
        $rows = $DB->get_records('local_coursegen_tpl_item', ['templateid' => $id], '', 'cmid, action, instruction');
        $keys = array_keys($rows);
        $this->assertEqualsCanonicalizing([$label, $page], $keys);
        $this->assertSame('third', $rows[$label]->instruction);
        $this->assertNull($rows[$page]->instruction);
        $template = $DB->get_record('local_coursegen_template', ['id' => $id]);
        $this->assertSame('Renamed', $template->name);
        $this->assertSame(3, (int) $template->usermodified);
        $this->assertSame($created, (int) $template->timecreated);
    }

    /**
     * Two saves in a row leave exactly what the last one sent.
     */
    public function test_last_of_two_saves_wins(): void {
        global $DB;
        [$course, $page, $quiz] = $this->make_course();
        $id = $this->save_items($course, [['cmid' => $page, 'action' => 'ai', 'instruction' => 'A']]);

        $this->save_items($course, [['cmid' => $quiz, 'action' => 'ai', 'instruction' => 'B']], $id);
        $this->save_items($course, [['cmid' => $page, 'action' => 'ai', 'instruction' => 'C']], $id);

        $rows = $DB->get_records('local_coursegen_tpl_item', ['templateid' => $id], '', 'cmid, instruction');
        $keys = array_keys($rows);
        $this->assertSame([$page], $keys);
        $this->assertSame('C', $rows[$page]->instruction);
    }
}

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

use local_coursegen\local\template\template_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/template_test_helper.php');
require_once(__DIR__ . '/fixtures/failing_template_repository.php');

/**
 * Tests for the rollback of a failed save and for deleting templates.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\template\template_service
 * @covers     \local_coursegen\local\template\template_repository
 */
final class template_service_delete_test extends \advanced_testcase {
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
     * A failure while writing the activities rolls everything back, for a new template and for an update.
     */
    public function test_failure_rolls_the_save_back(): void {
        global $DB;
        [$course, $page, $quiz] = $this->make_course();
        $id = $this->save_items($course, [['cmid' => $page, 'action' => 'ai', 'instruction' => 'kept']]);
        $failing = new template_service(new failing_template_repository());

        foreach ([0, $id] as $templateid) {
            try {
                $failing->save($templateid, (int) $course->id, 'Changed name', null, [['cmid' => $quiz, 'action' => 'keep']], 2);
                $this->fail('The failure must reach the caller.');
            } catch (\dml_write_exception $exception) {
                $this->assertSame('boom', $exception->error);
            }
        }

        $count = $DB->count_records('local_coursegen_template');
        $this->assertSame(1, $count);
        $field = $DB->get_field('local_coursegen_template', 'name', ['id' => $id]);
        $this->assertSame('Marketing', $field);
        $instruction = $DB->get_field('local_coursegen_tpl_item', 'instruction', ['templateid' => $id]);
        $this->assertSame('kept', $instruction);
    }

    /**
     * Deleting removes the template and its activities and nothing else.
     */
    public function test_delete_removes_the_template_and_its_activities(): void {
        global $DB;
        [$course, $page, $quiz] = $this->make_course();
        $first = $this->save_items($course, [['cmid' => $page, 'action' => 'ai', 'instruction' => 'A']]);
        $second = $this->save_items($course, [['cmid' => $quiz, 'action' => 'ai', 'instruction' => 'B']]);

        $this->service->delete($first);

        $exists = $DB->record_exists('local_coursegen_template', ['id' => $first]);
        $this->assertFalse($exists);
        $count = $DB->count_records('local_coursegen_tpl_item', ['templateid' => $first]);
        $this->assertSame(0, $count);
        $exists2 = $DB->record_exists('local_coursegen_template', ['id' => $second]);
        $this->assertTrue($exists2);
        $itemcount = $DB->count_records('local_coursegen_tpl_item', ['templateid' => $second]);
        $this->assertSame(1, $itemcount);
    }

    /**
     * Deleting a template that does not exist, or the id 0, is rejected.
     */
    public function test_delete_of_an_unknown_template_is_rejected(): void {
        foreach ([0, 99999, -3] as $templateid) {
            try {
                $this->service->delete($templateid);
                $this->fail('Template ' . $templateid . ' must be rejected.');
            } catch (\moodle_exception $exception) {
                $this->assertSame('error_template_not_found', $exception->errorcode);
            }
        }
    }
}

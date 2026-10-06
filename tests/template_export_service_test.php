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

use local_coursegen\local\service\template_export_service;
use local_coursegen\local\template\template_actions;
use local_coursegen\local\template\template_service;

/**
 * The init payload of a template: the course, and what the AI does with each of its activities.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_export_service
 */
final class template_export_service_test extends \advanced_testcase {
    use template_test_helper;

    /** @var template_service Service used to save the templates of the tests. */
    private template_service $service;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->service = new template_service();
    }

    public function test_the_payload_says_which_contract_it_follows(): void {
        [$course] = $this->make_course();
        $templateid = $this->save_items($course, []);

        $payload = template_export_service::build_init_payload($templateid);

        $this->assertSame(2, $payload['contract_version']);
        $this->assertSame($course->fullname, $payload['course_configuration']['fullname']);
        $this->assertTrue($payload['defer_start']);
    }

    public function test_an_activity_with_nothing_saved_is_kept(): void {
        [$course, $page] = $this->make_course();
        $templateid = $this->save_items($course, []);

        $payload = template_export_service::build_init_payload($templateid);

        $entry = $this->find_activity($payload, $page);
        $this->assertSame(['action' => 'keep'], $entry['template_behavior']);
    }

    public function test_an_activity_the_ai_modifies_travels_as_modify_with_its_instruction(): void {
        [$course, $page] = $this->make_course();
        $items = [['cmid' => $page, 'action' => template_actions::AI, 'instruction' => '  Update the dates  ']];
        $templateid = $this->save_items($course, $items);

        $payload = template_export_service::build_init_payload($templateid);

        $entry = $this->find_activity($payload, $page);
        $this->assertSame('modify', $entry['template_behavior']['action']);
        $this->assertSame('Update the dates', $entry['template_behavior']['instruction']);
    }

    public function test_a_modified_activity_without_instruction_carries_a_null_one(): void {
        [$course, $page] = $this->make_course();
        $items = [['cmid' => $page, 'action' => template_actions::AI, 'instruction' => '   ']];
        $templateid = $this->save_items($course, $items);

        $payload = template_export_service::build_init_payload($templateid);

        $entry = $this->find_activity($payload, $page);
        $this->assertSame('modify', $entry['template_behavior']['action']);
        $this->assertNull($entry['template_behavior']['instruction']);
    }

    public function test_only_the_activities_the_user_can_see_are_exported(): void {
        [$course, $page, $quiz] = $this->make_course();
        $templateid = $this->save_items($course, []);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($teacher);

        $payload = template_export_service::build_init_payload($templateid);

        $pageentry = $this->find_activity($payload, $page);
        $quizentry = $this->find_activity($payload, $quiz);
        $this->assertNotNull($pageentry);
        $this->assertNull($quizentry, 'The hidden quiz is not exported to a student.');
    }

    public function test_a_section_with_a_modified_activity_is_modified_and_the_others_are_kept(): void {
        [$course, $page] = $this->make_course();
        $items = [['cmid' => $page, 'action' => template_actions::AI, 'instruction' => '']];
        $templateid = $this->save_items($course, $items);

        $payload = template_export_service::build_init_payload($templateid);

        $templatebehaviors = array_column($payload['sections_info'], 'template_behavior');
        $behaviors = array_column($templatebehaviors, 'behavior');
        $this->assertContains('aimodify', $behaviors);
        $this->assertContains('keep', $behaviors);
    }

    public function test_the_uid_of_every_activity_is_its_course_module_id(): void {
        [$course] = $this->make_course();
        $templateid = $this->save_items($course, []);

        $payload = template_export_service::build_init_payload($templateid);

        $uids = array_column($payload['activities'], 'uid');
        $unique = array_unique($uids);
        $expected = count($uids);
        $this->assertCount($expected, $unique);
        $cmids = array_map('strval', array_column($payload['activities'], 'cmid'));
        $this->assertSame($cmids, $uids);
        $this->assertMatchesRegularExpression('/^[0-9]+$/', $uids[0]);
    }

    public function test_the_entry_of_an_activity_names_its_type_section_and_name(): void {
        [$course, $page] = $this->make_course();
        $templateid = $this->save_items($course, []);

        $payload = template_export_service::build_init_payload($templateid);

        $entry = $this->find_activity($payload, $page);
        $this->assertSame('page', $entry['modname']);
        $this->assertSame('page', $entry['resource_type']);
        $this->assertSame('Welcome page', $entry['name']);
        $this->assertSame(1, $entry['section']);
    }

    public function test_the_payload_of_an_unknown_template_is_refused(): void {
        $this->expectException(\moodle_exception::class);

        template_export_service::build_init_payload(987654);
    }

    public function test_the_payload_of_a_template_whose_course_is_gone_is_refused(): void {
        [$course] = $this->make_course();
        $templateid = $this->save_items($course, []);
        delete_course($course, false);

        $this->expectException(\dml_missing_record_exception::class);

        template_export_service::build_init_payload($templateid);
    }

    /**
     * The entry of an activity in a payload.
     *
     * @param array $payload
     * @param int $cmid
     * @return array|null
     */
    private function find_activity(array $payload, int $cmid): ?array {
        foreach ($payload['activities'] as $activity) {
            if ($activity['cmid'] === $cmid) {
                return $activity;
            }
        }
        return null;
    }
}

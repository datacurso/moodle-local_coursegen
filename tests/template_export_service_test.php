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

use local_coursegen\local\models\template;
use local_coursegen\local\models\template_activity;
use local_coursegen\local\models\template_instance;
use local_coursegen\local\service\template_export_service;

/**
 * Unit tests for template_export_service::build_init_payload().
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_export_service
 *
 * @runTestsInSeparateProcesses
 */
final class template_export_service_test extends \advanced_testcase {
    /**
     * A real activity's own entry carries a random UUID uid, not the old
     * "cm-{templateid}-{cmid}" derived key.
     */
    public function test_real_activity_entry_carries_a_random_uuid_uid(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id]);
        $template = $this->create_template($course->id);
        $templateid = $template->get('id');

        $payload = template_export_service::build_init_payload($templateid);
        $entry = $this->find_activity($payload, (int) $page->cmid);

        $this->assertNotNull($entry);
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        $matched = preg_match($pattern, $entry['uid']);
        $this->assertSame(1, $matched);
    }

    /**
     * An activity saved with action "exclude" never reaches the payload.
     */
    public function test_excluded_activity_is_left_out_of_the_payload(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id]);
        $template = $this->create_template($course->id);
        $templateid = $template->get('id');
        $this->mark_excluded($templateid, (int) $page->cmid);

        $payload = template_export_service::build_init_payload($templateid);
        $entry = $this->find_activity($payload, (int) $page->cmid);

        $this->assertNull($entry);
    }

    /**
     * An activity saved with action "space" is for the professor to provide,
     * so it never reaches the payload the AI service is asked about, while
     * a neighbouring activity that is kept still does.
     */
    public function test_space_activity_is_left_out_of_the_payload(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id]);
        $forum = $generator->create_module('forum', ['course' => $course->id]);
        $template = $this->create_template($course->id);
        $templateid = $template->get('id');
        $this->mark_with_action($templateid, (int) $page->cmid, 'space');

        $payload = template_export_service::build_init_payload($templateid);

        $pageentry = $this->find_activity($payload, (int) $page->cmid);
        $forumentry = $this->find_activity($payload, (int) $forum->cmid);
        $this->assertNull($pageentry);
        $this->assertNotNull($forumentry);
    }

    /**
     * No two entries of the same payload - activities or sections - ever
     * share a uid.
     */
    public function test_every_uid_in_the_payload_is_unique(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 2]);
        $generator->create_module('page', ['course' => $course->id]);
        $generator->create_module('forum', ['course' => $course->id]);
        $template = $this->create_template($course->id);
        $templateid = $template->get('id');

        $payload = template_export_service::build_init_payload($templateid);

        $activityuids = array_column($payload['activities'], 'uid');
        $sectionuids = array_column($payload['sections_info'], 'uid');
        $uids = array_merge($activityuids, $sectionuids);

        $uniqueuids = array_unique($uids);
        $expectedcount = count($uids);
        $this->assertCount($expectedcount, $uniqueuids);
    }

    /**
     * The wire action of a template instance is the literal the AI service
     * contracts on. Pinned here so a rename of the constant cannot change
     * what travels.
     */
    public function test_instance_wire_action_is_the_literal_of_the_service_contract(): void {
        $this->assertSame('instance', template_export_service::WIRE_ACTION_INSTANCE);
    }

    /**
     * A virtual instance travels in the payload with the instance wire
     * action, driven by the mold it was created from, and never with the
     * action the service no longer knows.
     */
    public function test_instance_entry_carries_the_instance_wire_action(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id]);
        $template = $this->create_template($course->id);
        $templateid = $template->get('id');
        $this->mark_with_action($templateid, (int) $page->cmid, 'template');
        $instance = new template_instance(0, (object) [
            'uid' => 'instance-uid',
            'templateid' => $templateid,
            'sectionid' => 0,
            'sourcecmid' => (int) $page->cmid,
            'sourcename' => 'Page mold',
            'name' => 'Generated page',
            'typelabel' => 'Page',
            'modname' => 'page',
        ]);
        $instance->create();

        $payload = template_export_service::build_init_payload($templateid);

        $entry = $this->find_entry_by_uid($payload, 'instance-uid');
        $this->assertNotNull($entry);
        $behavior = $entry['template_behavior'];
        $this->assertSame('instance', $behavior['action']);
        $this->assertNotSame('modify', $behavior['action']);
        $this->assertSame((int) $page->cmid, $behavior['template_source_cmid']);
    }

    /**
     * No real activity ever travels with the instance wire action, whatever
     * action the user saved for it.
     *
     * @dataProvider saved_action_provider
     * @param string $action
     */
    public function test_real_activity_never_carries_the_instance_wire_action(string $action): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id]);
        $template = $this->create_template($course->id);
        $templateid = $template->get('id');
        $this->mark_with_action($templateid, (int) $page->cmid, $action);

        $payload = template_export_service::build_init_payload($templateid);

        $entry = $this->find_activity($payload, (int) $page->cmid);
        $this->assertNotNull($entry);
        $this->assertSame($action, $entry['template_behavior']['action']);
        $this->assertNotSame('instance', $entry['template_behavior']['action']);
    }

    /**
     * The actions a real activity can be sent with.
     *
     * @return array
     */
    public static function saved_action_provider(): array {
        return [
            'keep' => ['keep'],
            'reference' => ['reference'],
            'template' => ['template'],
        ];
    }

    /**
     * The payload's own entry for one uid, or null if it is not there.
     *
     * @param array $payload
     * @param string $uid
     * @return array|null
     */
    private function find_entry_by_uid(array $payload, string $uid): ?array {
        $activities = $payload['activities'] ?? [];
        foreach ($activities as $activity) {
            $activityuid = $activity['uid'] ?? '';
            if ($activityuid === $uid) {
                return $activity;
            }
        }
        return null;
    }

    /**
     * A throwaway template pointing at the given course, with no saved
     * activity/section rows - every activity defaults to "keep".
     *
     * @param int $courseid
     * @return template
     */
    private function create_template(int $courseid): template {
        $template = new template(0, (object) [
            'name' => 'Test template',
            'courseid' => $courseid,
        ]);
        $template->create();
        return $template;
    }

    /**
     * Save an "exclude" action for one cmid of this template.
     *
     * @param int $templateid
     * @param int $cmid
     */
    private function mark_excluded(int $templateid, int $cmid): void {
        $this->mark_with_action($templateid, $cmid, 'exclude');
    }

    /**
     * Save an action for one cmid of this template.
     *
     * @param int $templateid
     * @param int $cmid
     * @param string $action
     */
    private function mark_with_action(int $templateid, int $cmid, string $action): void {
        $activity = new template_activity(0, (object) [
            'templateid' => $templateid,
            'sectionid' => 0,
            'cmid' => $cmid,
            'action' => $action,
        ]);
        $activity->create();
    }

    /**
     * The payload's own entry for one cmid, or null if it is not there.
     *
     * @param array $payload
     * @param int $cmid
     * @return array|null
     */
    private function find_activity(array $payload, int $cmid): ?array {
        $activities = $payload['activities'] ?? [];
        foreach ($activities as $activity) {
            $activitycmid = $activity['cmid'] ?? 0;
            $activitycmid = (int) $activitycmid;
            if ($activitycmid === $cmid) {
                return $activity;
            }
        }
        return null;
    }
}

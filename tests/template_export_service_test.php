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
     * The payload carries the template course's format, its language and the
     * course settings the new course takes as they are.
     */
    public function test_course_configuration_carries_the_format_and_settings(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course([
            'format' => 'topics',
            'lang' => 'es',
            'newsitems' => 0,
            'showreports' => 1,
            'enablecompletion' => 1,
        ]);
        $template = $this->create_template($course->id);

        $payload = template_export_service::build_init_payload($template->get('id'));
        $configuration = $payload['course_configuration'];

        $this->assertSame('topics', $configuration['format']);
        $this->assertSame(1, $configuration['enablecompletion']);
        $this->assertSame('es', $configuration['course_settings']['lang']);
        $this->assertEquals(0, $configuration['course_settings']['newsitems']);
        $this->assertEquals(1, $configuration['course_settings']['showreports']);
    }

    /**
     * A language the template leaves open travels as open, not as the language of the person exporting.
     */
    public function test_course_settings_keep_an_open_language_open(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['lang' => '']);
        $template = $this->create_template($course->id);

        $payload = template_export_service::build_init_payload($template->get('id'));

        $this->assertSame('', $payload['course_configuration']['course_settings']['lang']);
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
     * A template instance travels with action "instance", the cmid of the
     * source it was made from and its own prompt, and never with the retired
     * "modify" action.
     */
    public function test_instance_entry_carries_the_instance_action_and_its_source(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id]);
        $template = $this->create_template($course->id);
        $templateid = $template->get('id');
        $this->mark_with_action($templateid, (int) $page->cmid, 'template');
        $instance = $this->create_instance($templateid, (int) $page->cmid);
        $instanceuid = $instance->get('uid');

        $payload = template_export_service::build_init_payload($templateid);

        $entry = $this->find_activity_by_uid($payload, $instanceuid);
        $this->assertNotNull($entry);
        $this->assertSame('instance', $entry['template_behavior']['action']);
        $this->assertSame((int) $page->cmid, $entry['template_behavior']['template_source_cmid']);
        $this->assertSame('Write the intro', $entry['template_behavior']['prompt']);
        $actions = array_column(array_column($payload['activities'], 'template_behavior'), 'action');
        $this->assertNotContains('modify', $actions);
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
     * Save one virtual instance of the given source for this template.
     *
     * @param int $templateid
     * @param int $sourcecmid
     * @return template_instance
     */
    private function create_instance(int $templateid, int $sourcecmid): template_instance {
        $instance = new template_instance(0, (object) [
            'uid' => 'instance-test-uid',
            'templateid' => $templateid,
            'sectionid' => 0,
            'sourcecmid' => $sourcecmid,
            'sourcename' => 'Source page',
            'name' => 'Instance page',
            'typelabel' => 'Page',
            'modname' => 'page',
            'prompt' => 'Write the intro',
        ]);
        $instance->create();
        return $instance;
    }

    /**
     * The payload's own entry for one uid, or null if it is not there.
     *
     * @param array $payload
     * @param string $uid
     * @return array|null
     */
    private function find_activity_by_uid(array $payload, string $uid): ?array {
        $activities = $payload['activities'] ?? [];
        foreach ($activities as $activity) {
            if (($activity['uid'] ?? '') === $uid) {
                return $activity;
            }
        }
        return null;
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

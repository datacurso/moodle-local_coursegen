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

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $template = $this->create_template($course->id);

        $payload = template_export_service::build_init_payload($template->get('id'));
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

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $template = $this->create_template($course->id);
        $this->mark_excluded($template->get('id'), (int) $page->cmid);

        $payload = template_export_service::build_init_payload($template->get('id'));
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

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $template = $this->create_template($course->id);
        $this->mark_with_action($template->get('id'), (int) $page->cmid, 'space');

        $payload = template_export_service::build_init_payload($template->get('id'));

        $this->assertNull($this->find_activity($payload, (int) $page->cmid));
        $this->assertNotNull($this->find_activity($payload, (int) $forum->cmid));
    }

    /**
     * No two entries of the same payload - activities or sections - ever
     * share a uid.
     */
    public function test_every_uid_in_the_payload_is_unique(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $template = $this->create_template($course->id);

        $payload = template_export_service::build_init_payload($template->get('id'));

        $activityuids = array_column($payload['activities'], 'uid');
        $sectionuids = array_column($payload['sections_info'], 'uid');
        $uids = array_merge($activityuids, $sectionuids);

        $this->assertCount(count($uids), array_unique($uids));
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

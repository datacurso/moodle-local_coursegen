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

use local_coursegen\local\models\course_session;
use local_coursegen\local\service\create_course_service;

/**
 * Every generated activity is tracked under its payload cmid, including the
 * negative ids that virtual template instances travel under.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\generated_activities_builder
 * @covers     \local_coursegen\local\service\create_course_service
 *
 * @runTestsInSeparateProcesses
 */
final class generated_cmids_tracking_test extends \advanced_testcase {
    /**
     * An AI result for a label with the given payload cmid.
     *
     * @param int $payloadcmid
     * @param string $name
     * @return array
     */
    private function label_result(int $payloadcmid, string $name): array {
        return [
            'resource_type' => 'label',
            'cmid' => $payloadcmid,
            'parameters' => [
                'modulename' => 'label',
                'name' => $name,
                'section' => 1,
                'introeditor' => ['text' => '<p>' . $name . '</p>', 'format' => FORMAT_HTML],
                'visible' => 1,
                'showdescription' => 1,
                'groupmode' => 0,
                'groupingid' => 0,
                'cmidnumber' => '',
                'mod_settings' => [],
            ],
        ];
    }

    /**
     * Build a course from the given generated activities.
     *
     * @param array $activities
     * @return array Result of create_course().
     */
    private function create_course_from(array $activities): array {
        global $USER, $PAGE;
        $PAGE->set_course($this->getDataGenerator()->create_course());

        $session = new course_session(0, (object) [
            'userid' => $USER->id,
            'session_id' => 'sess-tracking',
            'status' => course_session::STATUS_PENDING,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $session->create();

        return create_course_service::create_course($session, [
            'course_configuration' => ['fullname' => 'Tracking course', 'shortname' => 'tracking-course'],
            'sections_info' => [['section' => 0, 'name' => 'General'], ['section' => 1, 'name' => 'Unit 1']],
            'generated_activities' => $activities,
        ]);
    }

    /**
     * A virtual instance travels under a negative cmid and is tracked under it.
     */
    public function test_negative_instance_cmid_is_tracked(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = $this->create_course_from([$this->label_result(-7, 'Instance label')]);

        $this->assertTrue($result['success'], $result['message']);
        $cms = $DB->get_records('course_modules', ['course' => $result['courseid']]);
        $cm = reset($cms);
        $this->assertSame([-7 => (int) $cm->id], $result['generatedcms']);
    }

    /**
     * An activity with no cmid in the payload is not tracked.
     */
    public function test_activity_without_a_cmid_is_not_tracked(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = $this->create_course_from([$this->label_result(0, 'Untracked label')]);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame([], $result['generatedcms']);
    }
}

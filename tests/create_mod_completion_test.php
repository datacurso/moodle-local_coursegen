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
use local_coursegen\local\service\create_mod_service;

/**
 * The completion settings a mold sends must reach the generated course module.
 *
 * add_moduleinfo() only writes completion columns when completion is enabled
 * on the course, so the course the plugin creates has to enable it the way a
 * course created through the UI does (site default for new courses).
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\create_mod_service
 * @covers     \local_coursegen\local\service\create_course_service
 */
final class create_mod_completion_test extends \advanced_testcase {
    /**
     * An AI result for a label carrying the mold's completion columns.
     *
     * @param string $name
     * @return array
     */
    private function label_result(string $name): array {
        return [
            'resource_type' => 'label',
            'cmid' => 900001,
            'parameters' => [
                'modulename' => 'label',
                'name' => $name,
                'section' => 1,
                'introeditor' => ['text' => '<p>' . $name . '</p>', 'format' => FORMAT_HTML],
                'visible' => 1,
                'showdescription' => 1,
                'completion' => COMPLETION_TRACKING_AUTOMATIC,
                'completionview' => 1,
                'completionexpected' => 0,
                'completionunlocked' => 1,
                'groupmode' => 0,
                'groupingid' => 0,
                'cmidnumber' => '',
                'mod_settings' => [],
            ],
        ];
    }

    /**
     * Building a module through the service stores completion=2 / completionview=1 on the cm row.
     */
    public function test_completion_settings_reach_course_module_row(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1, 'numsections' => 1]);
        $PAGE->set_course($course);

        $newcm = create_mod_service::create_from_ai_result($this->label_result('Tracked label'), $course, 1);

        $cm = $DB->get_record('course_modules', ['id' => $newcm->coursemodule], '*', MUST_EXIST);
        $this->assertSame(COMPLETION_TRACKING_AUTOMATIC, (int) $cm->completion);
        $this->assertSame(1, (int) $cm->completionview);
    }

    /**
     * The course the plugin creates enables completion by site default, so the
     * generated activities keep the completion the payload gave them.
     */
    public function test_created_course_enables_completion_so_modules_keep_theirs(): void {
        global $DB, $USER, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enablecompletion', 1);
        set_config('enablecompletion', 1, 'moodlecourse');
        $PAGE->set_course($this->getDataGenerator()->create_course());

        $session = new course_session(0, (object) [
            'userid' => $USER->id,
            'session_id' => 'sess-completion',
            'status' => course_session::STATUS_PENDING,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $session->create();

        $result = create_course_service::create_course($session, [
            'course_configuration' => ['fullname' => 'Completion course', 'shortname' => 'completion-course'],
            'sections_info' => [['section' => 0, 'name' => 'General'], ['section' => 1, 'name' => 'Unit 1']],
            'generated_activities' => [$this->label_result('Tracked label')],
        ]);

        $this->assertTrue($result['success'], $result['message']);
        $course = $DB->get_record('course', ['id' => $result['courseid']], '*', MUST_EXIST);
        $this->assertSame(1, (int) $course->enablecompletion);

        $cms = $DB->get_records('course_modules', ['course' => $course->id]);
        $this->assertCount(1, $cms);
        $cm = reset($cms);
        $this->assertSame(COMPLETION_TRACKING_AUTOMATIC, (int) $cm->completion);
        $this->assertSame(1, (int) $cm->completionview);
        $this->assertSame([900001 => (int) $cm->id], $result['generatedcms']);
    }

    /**
     * With completion disabled site-wide the course stays off and modules get completion 0.
     */
    public function test_site_wide_disabled_completion_leaves_course_and_modules_off(): void {
        global $DB, $USER, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enablecompletion', 0);
        set_config('enablecompletion', 1, 'moodlecourse');
        $PAGE->set_course($this->getDataGenerator()->create_course());

        $session = new course_session(0, (object) [
            'userid' => $USER->id,
            'session_id' => 'sess-siteoff',
            'status' => course_session::STATUS_PENDING,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $session->create();

        $result = create_course_service::create_course($session, [
            'course_configuration' => ['fullname' => 'Site off', 'shortname' => 'site-off'],
            'sections_info' => [['section' => 0, 'name' => 'General'], ['section' => 1, 'name' => 'Unit 1']],
            'generated_activities' => [$this->label_result('Tracked label')],
        ]);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(0, (int) $DB->get_field('course', 'enablecompletion', ['id' => $result['courseid']]));
        $cms = $DB->get_records('course_modules', ['course' => $result['courseid']]);
        $this->assertCount(1, $cms);
        $this->assertSame(0, (int) reset($cms)->completion);
    }

    /**
     * The payload's own enablecompletion wins over the site default.
     */
    public function test_payload_can_disable_course_completion(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enablecompletion', 1, 'moodlecourse');

        $session = new course_session(0, (object) [
            'userid' => $USER->id,
            'session_id' => 'sess-nocompletion',
            'status' => course_session::STATUS_PENDING,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $session->create();

        $result = create_course_service::create_course($session, [
            'course_configuration' => [
                'fullname' => 'No completion', 'shortname' => 'no-completion', 'enablecompletion' => 0,
            ],
        ]);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(0, (int) $DB->get_field('course', 'enablecompletion', ['id' => $result['courseid']]));
    }
}

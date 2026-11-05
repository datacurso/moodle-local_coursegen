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

namespace local_coursegen\local\service;

use local_coursegen\local\models\course_session;

/**
 * Tests the course structure created from the service result.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\create_course_service
 */
final class create_course_structure_test extends \advanced_testcase {
    /**
     * A plan numbered from section 0 starts at section 1, so section 0 stays the general section.
     */
    public function test_plan_starting_at_section_zero_keeps_the_general_section(): void {
        $course = $this->create_course_from(['lang' => 'en']);

        $sections = $this->sections_of($course->id);
        $this->assertSame('', (string) $sections[0]->name);
        $this->assertSame('Basics', $sections[1]->name);
        $this->assertSame('Practice', $sections[2]->name);
    }

    /**
     * Every planned section keeps the description the service sent as its summary.
     */
    public function test_section_descriptions_become_section_summaries(): void {
        $course = $this->create_course_from(['lang' => 'en']);

        $sections = $this->sections_of($course->id);
        $this->assertSame('<p>What a spreadsheet is.</p>', $sections[1]->summary);
        $this->assertSame('<p>Exercises &amp; examples.</p>', $sections[2]->summary);
    }

    /**
     * The course description the service sent becomes the course summary.
     */
    public function test_course_description_becomes_course_summary(): void {
        $course = $this->create_course_from(['lang' => 'en']);

        $this->assertSame('<p>A short spreadsheet course.</p>', $course->summary);
        $this->assertEquals(FORMAT_HTML, $course->summaryformat);
    }

    /**
     * The language chosen in the assistant is set on the course when it is installed.
     */
    public function test_installed_language_is_set_on_the_course(): void {
        $course = $this->create_course_from(['lang' => 'en']);

        $this->assertSame('en', $course->lang);
    }

    /**
     * A language that is not installed leaves the course on the site default.
     */
    public function test_missing_language_is_not_forced(): void {
        $course = $this->create_course_from(['lang' => 'xx']);

        $this->assertSame('', (string) $course->lang);
    }

    /**
     * Create a course from a fixed service result with the given session data.
     *
     * @param array $options Session options: lang.
     * @return \stdClass The created course record.
     */
    private function create_course_from(array $options): \stdClass {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $session = new course_session(0, (object) [
            'userid' => (int) $USER->id,
            'session_id' => 'thread-structure',
            'status' => course_session::STATUS_PENDING,
            'coursedata' => json_encode(['local_coursegen_lang' => $options['lang']]),
        ]);
        $session->create();

        $result = [
            'course_configuration' => [
                'fullname' => 'Spreadsheets',
                'shortname' => 'SHEETS-' . random_string(6),
                'description' => 'A short spreadsheet course.',
            ],
            'sections_info' => [
                ['section' => 0, 'name' => 'Basics', 'description' => 'What a spreadsheet is.'],
                ['section' => 1, 'name' => 'Practice', 'description' => 'Exercises & examples.'],
            ],
            'generated_activities' => [],
        ];

        create_course_service::create_course($session, $result);
        $this->resetDebugging();

        $courseid = (int) (new course_session((int) $session->get('id')))->get('courseid');
        return $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    }

    /**
     * Sections of a course indexed by number.
     *
     * @param int $courseid Course id.
     * @return array<int, \stdClass>
     */
    private function sections_of(int $courseid): array {
        global $DB;

        $sections = [];
        foreach ($DB->get_records('course_sections', ['course' => $courseid], 'section') as $section) {
            $sections[(int) $section->section] = $section;
        }
        return $sections;
    }
}

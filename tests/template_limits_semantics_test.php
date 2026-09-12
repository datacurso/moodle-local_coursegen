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

use local_coursegen\external\get_template_structure;
use local_coursegen\local\models\template;
use local_coursegen\local\service\template_course_builder_service;

/**
 * "Extra sections" semantics of the template section limit.
 *
 * A template's stored maxsections is the number of EXTRA sections the
 * professor may add ON TOP of the template's own sections — not a cap on the
 * total section count. With maxsections=2 over a base course that already has
 * 3 sections, the professor may still add exactly 2 more:
 * get_template_structure must report remainingsections=2, and the course
 * builder must create exactly 2 of 3 requested new sections, reporting the
 * third as a per-section error instead of aborting.
 *
 * The build flow loads course/externallib.php, which requires each test to
 * run in an isolated process.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\external\get_template_structure
 * @covers     \local_coursegen\local\service\template_course_builder_service
 *
 * @runTestsInSeparateProcesses
 */
final class template_limits_semantics_test extends \advanced_testcase {
    /**
     * Create a base course with 3 sections and a template with nolimit=0 and maxsections=2.
     *
     * @return template The template persistent.
     */
    private function create_template_fixture(): template {
        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);

        $template = new template(0, (object) [
            'name' => 'Extra sections template',
            'courseid' => (int) $course->id,
            'maxsections' => 2,
            'nolimit' => 0,
            'allowedtypes' => json_encode(['page']),
        ]);
        $template->create();

        return $template;
    }

    /**
     * remainingsections is the stored extra allowance itself, not "stored minus existing".
     */
    public function test_get_template_structure_reports_stored_value_as_remaining(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $template = $this->create_template_fixture();

        $result = get_template_structure::execute((int) $template->get('id'));

        $this->assertFalse($result['nolimit']);
        $this->assertSame(2, $result['maxsections']);
        // The base course already has 3 sections (plus section 0); the
        // professor may still add exactly the 2 EXTRA sections the template
        // allows — the template's own sections never consume the allowance.
        $this->assertSame(2, $result['remainingsections']);
    }

    /**
     * The builder creates exactly maxsections new sections and reports the overflow per-section.
     */
    public function test_builder_creates_exactly_the_extra_allowance(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $template = $this->create_template_fixture();

        $result = template_course_builder_service::create_course_from_template(
            $template,
            [
                ['clientid' => -1, 'name' => 'Extra one'],
                ['clientid' => -2, 'name' => 'Extra two'],
                ['clientid' => -3, 'name' => 'Extra three'],
            ],
            [],
            (int) $USER->id
        );

        $this->assertTrue($result['success']);

        // Exactly 2 of the 3 requested new sections were created.
        [$insql, $inparams] = $DB->get_in_or_equal(['Extra one', 'Extra two', 'Extra three']);
        $creatednames = $DB->get_fieldset_select(
            'course_sections',
            'name',
            "course = ? AND name $insql",
            array_merge([(int) $result['courseid']], $inparams)
        );
        $this->assertEqualsCanonicalizing(['Extra one', 'Extra two'], $creatednames);

        // The third one was reported as a per-section error, not silently dropped.
        $this->assertTrue($result['partial']);
        $this->assertCount(1, $result['activityerrors']);
        $this->assertSame('section', $result['activityerrors'][0]['resource_type']);
        $this->assertSame('Extra three', $result['activityerrors'][0]['title']);
    }
}

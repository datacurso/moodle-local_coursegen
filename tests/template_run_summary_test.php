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
use local_coursegen\local\service\template_run_summary;

/**
 * Unit tests for template_run_summary.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_run_summary
 */
final class template_run_summary_test extends \advanced_testcase {
    /**
     * A session's stored data gives the template's name, the language as the selector names it, the syllabus and the
     * instruction the course was generated with.
     */
    public function test_the_summary_says_what_the_course_was_generated_with(): void {
        $this->resetAfterTest(true);
        $templateid = $this->create_template('Plantilla H5P');

        $summary = template_run_summary::from_coursedata([
            'templateid' => $templateid,
            'payload' => ['lang' => 'en', 'general_instruction' => "  Safety course\n"],
            'syllabus' => 'syllabus.pdf',
        ]);

        $this->assertSame('Plantilla H5P', $summary['template']);
        $this->assertSame('English (EN)', $summary['language']);
        $this->assertTrue($summary['syllabusknown']);
        $this->assertSame('syllabus.pdf', $summary['syllabus']);
        $this->assertSame('Safety course', $summary['instruction']);
    }

    /**
     * A session started before the syllabus's name was kept says it does not know it, while one started without
     * a syllabus says there was none.
     */
    public function test_an_unknown_syllabus_and_a_missing_one_are_told_apart(): void {
        $this->resetAfterTest(true);
        $templateid = $this->create_template('Template');

        $old = template_run_summary::from_coursedata(['templateid' => $templateid, 'payload' => []]);
        $none = template_run_summary::from_coursedata(['templateid' => $templateid, 'payload' => [], 'syllabus' => '']);

        $this->assertFalse($old['syllabusknown']);
        $this->assertTrue($none['syllabusknown']);
        $this->assertSame('', $none['syllabus']);
    }

    /**
     * A template deleted since the course was made leaves its name empty, and an unknown language code is shown as it is.
     */
    public function test_a_deleted_template_and_an_unknown_language_do_not_fail(): void {
        $this->resetAfterTest(true);

        $summary = template_run_summary::from_coursedata(['templateid' => 999999, 'payload' => ['lang' => 'xx']]);

        $this->assertSame('', $summary['template']);
        $this->assertSame('xx', $summary['language']);
    }

    /**
     * Create a template record.
     *
     * @param string $name
     * @return int The template id.
     */
    private function create_template(string $name): int {
        $course = $this->getDataGenerator()->create_course();
        $template = new template(0, (object) ['name' => $name, 'courseid' => $course->id]);
        $template->create();
        return (int) $template->get('id');
    }
}

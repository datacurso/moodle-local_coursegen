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

use local_coursegen\form\template_transfer_import_form;

/**
 * The course chosen in the import form is shown by its name after a submit, never by its id.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\form\template_transfer_import_form
 */
final class template_transfer_import_form_test extends \advanced_testcase {
    public function test_the_chosen_course_is_labelled_with_its_full_and_short_name(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Plantilla DEMO', 'shortname' => 'DEMO1']);
        $this->assertSame('Plantilla DEMO (DEMO1)', template_transfer_import_form::course_label($course->id));
    }

    public function test_a_course_that_does_not_exist_has_no_label(): void {
        $this->resetAfterTest();
        $this->assertFalse(template_transfer_import_form::course_label(999999));
    }

    public function test_the_label_is_shown_again_after_a_submit(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Plantilla DEMO', 'shortname' => 'DEMO1']);
        $form = new template_transfer_import_form(new \moodle_url('/local/coursegen/import_template.php'));
        $form->set_data(['courseid' => $course->id]);
        $this->assertStringContainsString('Plantilla DEMO (DEMO1)', $form->render());
    }
}

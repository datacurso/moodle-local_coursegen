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

/**
 * Unit tests for hiding a template from the creation of a course.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\models\template
 */
final class template_visibility_test extends \advanced_testcase {
    /**
     * A new template is offered, and hiding one keeps it while leaving it out of the templates offered.
     */
    public function test_a_hidden_template_is_kept_but_not_offered(): void {
        $this->resetAfterTest(true);
        $shown = $this->create_template('Shown');
        $hidden = $this->create_template('Hidden');

        $hidden->set('visible', 0);
        $hidden->update();

        $this->assertTrue((bool) (new template($shown->get('id')))->get('visible'));
        $offered = array_map(
            static fn(template $tpl): string => $tpl->get('name'),
            template::get_records(['visible' => 1], 'name', 'ASC')
        );
        $this->assertSame(['Shown'], array_values($offered));
        $this->assertTrue(template::record_exists($hidden->get('id')));
    }

    /**
     * Create a template on a new course.
     *
     * @param string $name
     * @return template
     */
    private function create_template(string $name): template {
        $course = $this->getDataGenerator()->create_course();
        $template = new template(0, (object) ['name' => $name, 'courseid' => $course->id]);
        $template->create();
        return $template;
    }
}

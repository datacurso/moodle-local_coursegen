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

use local_coursegen\local\service\teacher_space_choices;

/**
 * The name a space with several activities to choose from shows to the teacher.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\teacher_space_choices
 */
final class teacher_space_choice_name_test extends \basic_testcase {
    /**
     * When the admin gave every option the same name, the space shows that name.
     */
    public function test_the_name_shared_by_every_option_is_shown(): void {
        $options = [['name' => 'Práctica guiada'], ['name' => ' Práctica guiada '], ['name' => 'Práctica guiada']];

        $this->assertSame('Práctica guiada', teacher_space_choices::display_name($options));
    }

    /**
     * Options with different names, or without a name, leave the generic name of a space to choose.
     */
    public function test_different_or_missing_names_give_the_generic_name(): void {
        $generic = get_string('courseai_template_choice_space_name', 'local_coursegen');

        $this->assertSame($generic, teacher_space_choices::display_name([['name' => 'Caso'], ['name' => 'Reto']]));
        $this->assertSame($generic, teacher_space_choices::display_name([['name' => ''], ['name' => '']]));
        $this->assertSame($generic, teacher_space_choices::display_name([['name' => 'Caso'], []]));
        $this->assertSame($generic, teacher_space_choices::display_name([]));
    }
}

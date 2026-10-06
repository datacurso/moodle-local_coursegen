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

use local_coursegen\local\service\template_export_behavior;
use local_coursegen\local\template\template_actions;

/**
 * What a saved choice is told to the AI service as.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_export_behavior
 */
final class template_export_behavior_test extends \advanced_testcase {
    public function test_nothing_saved_means_keep(): void {
        $behavior = template_export_behavior::for_item(null);

        $this->assertSame(['action' => 'keep'], $behavior);
    }

    public function test_a_kept_item_means_keep_even_with_an_instruction_left_over(): void {
        $item = (object) ['action' => template_actions::KEEP, 'instruction' => 'ignored'];

        $behavior = template_export_behavior::for_item($item);

        $this->assertSame(['action' => 'keep'], $behavior);
    }

    public function test_an_unknown_action_is_never_told_as_modify(): void {
        $item = (object) ['action' => 'template', 'instruction' => 'x'];

        $behavior = template_export_behavior::for_item($item);

        $this->assertSame(['action' => 'keep'], $behavior);
    }

    /**
     * Instructions and what the service reads.
     *
     * @return array
     */
    public static function instruction_provider(): array {
        return [
            'null' => [null, null],
            'empty' => ['', null],
            'only spaces' => ["  \n\t ", null],
            'trimmed' => ["  Update the dates \n", 'Update the dates'],
            'unicode kept' => ['Actualiza la guía 📘', 'Actualiza la guía 📘'],
            'markup kept as text' => ['<b>bold</b>', '<b>bold</b>'],
        ];
    }

    /**
     * The instruction of a modified item is trimmed, and a blank one is told as null.
     *
     * @dataProvider instruction_provider
     * @param string|null $saved
     * @param string|null $expected
     */
    public function test_the_instruction_of_a_modified_item(?string $saved, ?string $expected): void {
        $item = (object) ['action' => template_actions::AI, 'instruction' => $saved];

        $behavior = template_export_behavior::for_item($item);

        $this->assertSame('modify', $behavior['action']);
        $this->assertSame($expected, $behavior['instruction']);
    }
}

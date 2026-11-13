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

use local_coursegen\form\system_instruction_form;
use local_coursegen\local\service\system_instruction_service;
use local_coursegen\local\service\template_unit_resizer;

/**
 * The optional number of units of an institutional guideline.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\system_instruction_service
 * @covers     \local_coursegen\local\models\system_instruction
 * @covers     \local_coursegen\form\system_instruction_form
 */
final class system_instruction_units_test extends \advanced_testcase {
    /**
     * A guideline made without units has none.
     */
    public function test_units_default_to_null(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $instruction = system_instruction_service::create('Plain', 'Text.');
        $this->assertNull($instruction->get('units'));
        $this->assertNull(system_instruction_service::get_by_id((int) $instruction->get('id'))->get('units'));
        $this->assertNull(system_instruction_service::get_units((int) $instruction->get('id')));
    }

    /**
     * The units are saved on create and read back.
     */
    public function test_units_are_saved_and_read(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $instruction = system_instruction_service::create('Eight', 'Text.', 8);
        $id = (int) $instruction->get('id');
        $this->assertSame(8, system_instruction_service::get_by_id($id)->get('units'));
        $this->assertSame(8, system_instruction_service::get_units($id));
    }

    /**
     * An update changes the units and can clear them with null.
     */
    public function test_update_changes_and_clears_units(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = (int) system_instruction_service::create('Change', 'Text.', 4)->get('id');
        system_instruction_service::update($id, 'Change', 'Text.', 6);
        $this->assertSame(6, system_instruction_service::get_units($id));
        system_instruction_service::update($id, 'Change', 'Text.', null);
        $this->assertNull(system_instruction_service::get_units($id));
    }

    /**
     * Values out of 1..MAX_UNITS are refused on create and on update.
     *
     * @dataProvider invalid_units_provider
     * @param int $units
     */
    public function test_out_of_range_units_are_rejected(int $units): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        try {
            system_instruction_service::create('Bad', 'Text.', $units);
            $this->fail('create must reject ' . $units);
        } catch (\core\invalid_persistent_exception $e) {
            $this->assertCount(0, system_instruction_service::get_all());
        }
        $id = (int) system_instruction_service::create('Good', 'Text.', 3)->get('id');
        $this->expectException(\core\invalid_persistent_exception::class);
        system_instruction_service::update($id, 'Good', 'Text.', $units);
    }

    /**
     * Values refused for the units.
     *
     * @return array
     */
    public static function invalid_units_provider(): array {
        return [
            'zero' => [0],
            'negative' => [-1],
            'above the maximum' => [template_unit_resizer::MAX_UNITS + 1],
        ];
    }

    /**
     * The limits themselves are accepted.
     */
    public function test_the_limits_are_accepted(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $one = system_instruction_service::create('One', 'Text.', 1);
        $max = system_instruction_service::create('Max', 'Text.', template_unit_resizer::MAX_UNITS);
        $this->assertSame(1, $one->get('units'));
        $this->assertSame(template_unit_resizer::MAX_UNITS, $max->get('units'));
    }

    /**
     * A deleted guideline has no units to give, and deleting does not touch them.
     */
    public function test_a_deleted_guideline_gives_no_units(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = (int) system_instruction_service::create('Soft', 'Text.', 5)->get('id');
        system_instruction_service::delete($id);
        $this->assertNull(system_instruction_service::get_units($id));
        $this->assertEquals(5, $DB->get_field('local_coursegen_system_instruction', 'units', ['id' => $id]));
        $this->assertNull(system_instruction_service::get_units(0));
        $this->assertNull(system_instruction_service::get_units(99999));
    }

    /**
     * The items the page lists carry the units as an int, or null, next to the id the picker uses.
     */
    public function test_page_items_expose_the_units(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $a = (int) system_instruction_service::create('A with units', 'Alpha.', 7)->get('id');
        $b = (int) system_instruction_service::create('B without', 'Beta.')->get('id');
        $gone = (int) system_instruction_service::create('C gone', 'Gamma.', 2)->get('id');
        system_instruction_service::delete($gone);

        $items = system_instruction_service::page_items();
        $this->assertSame(['si_' . $a, 'si_' . $b], array_column($items, 'id'));
        $this->assertSame(7, $items[0]['units']);
        $this->assertNull($items[1]['units']);
        $this->assertSame('Alpha.', $items[0]['description']);
        $this->assertSame('General', $items[0]['category']);
    }

    /**
     * The form accepts an empty value and the range, and rejects what is outside it.
     *
     * @dataProvider form_units_provider
     * @param mixed $units
     * @param bool $valid
     */
    public function test_form_validates_units($units, bool $valid): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $form = new system_instruction_form();
        $errors = $form->validation(['name' => 'Form guideline', 'id' => 0, 'units' => $units], []);
        $this->assertSame($valid, !isset($errors['units']));
    }

    /**
     * Cases of test_form_validates_units.
     *
     * @return array
     */
    public static function form_units_provider(): array {
        return [
            'empty' => ['', true],
            'null' => [null, true],
            'one' => [1, true],
            'maximum' => [template_unit_resizer::MAX_UNITS, true],
            'zero' => [0, false],
            'negative' => [-2, false],
            'too many' => [template_unit_resizer::MAX_UNITS + 1, false],
        ];
    }
}

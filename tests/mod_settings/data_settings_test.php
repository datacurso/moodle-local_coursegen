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

namespace local_coursegen\mod_settings;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for data_settings — creation of the AI-generated database fields.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_coursegen\mod_settings\data_settings
 */
final class data_settings_test extends \advanced_testcase {

    /**
     * Create a database activity and return a cm-like object shaped as create_mod_service passes it.
     *
     * @return object Object with ->coursemodule (cmid) and ->instance (data id).
     */
    private function make_data_cm(): object {
        $course = $this->getDataGenerator()->create_course();
        $data = $this->getDataGenerator()->create_module('data', ['course' => $course->id]);
        return (object) ['coursemodule' => $data->cmid, 'instance' => $data->id];
    }

    /**
     * Fields created keep their type, name, required flag and (for choice fields) their options.
     */
    public function test_creates_fields_with_types_and_options(): void {
        $this->resetAfterTest();
        global $DB;

        $cm = $this->make_data_cm();
        $modsettings = ['fields' => [
            ['type' => 'text', 'name' => 'Titulo', 'required' => true],
            ['type' => 'textarea', 'name' => 'Resena', 'description' => 'Tu opinion'],
            ['type' => 'menu', 'name' => 'Genero', 'options' => ['Novela', 'Ensayo']],
        ]];

        (new data_settings($cm, $modsettings))->add_settings();

        $fields = $DB->get_records('data_fields', ['dataid' => $cm->instance], 'id ASC');
        $this->assertCount(3, $fields);

        $byname = [];
        foreach ($fields as $field) {
            $byname[$field->name] = $field;
        }
        $this->assertSame('text', $byname['Titulo']->type);
        $this->assertSame(1, (int) $byname['Titulo']->required);
        $this->assertSame('textarea', $byname['Resena']->type);
        $this->assertSame('Tu opinion', $byname['Resena']->description);
        $this->assertSame('menu', $byname['Genero']->type);
        // Choice options are stored one per line in param1.
        $this->assertSame("Novela\nEnsayo", $byname['Genero']->param1);
    }

    /**
     * Per-type defaults are applied so code-created fields render like form-created ones.
     */
    public function test_type_defaults_applied(): void {
        $this->resetAfterTest();
        global $DB;

        $cm = $this->make_data_cm();
        $modsettings = ['fields' => [
            ['type' => 'textarea', 'name' => 'Texto'],
            ['type' => 'url', 'name' => 'Enlace'],
        ]];

        (new data_settings($cm, $modsettings))->add_settings();

        $textarea = $DB->get_record('data_fields', ['dataid' => $cm->instance, 'name' => 'Texto']);
        $this->assertSame('60', $textarea->param2);
        $this->assertSame('35', $textarea->param3);
        $this->assertSame('1', $textarea->param4);

        $url = $DB->get_record('data_fields', ['dataid' => $cm->instance, 'name' => 'Enlace']);
        // param1 = autolink -> the URL becomes a clickable link.
        $this->assertSame('1', $url->param1);
    }

    /**
     * Choice fields with no usable options are skipped (they could not be filled in).
     */
    public function test_choice_without_options_skipped(): void {
        $this->resetAfterTest();
        global $DB;

        $cm = $this->make_data_cm();
        $modsettings = ['fields' => [
            ['type' => 'menu', 'name' => 'Vacio', 'options' => []],
            ['type' => 'checkbox', 'name' => 'Blancos', 'options' => ['  ', '']],
            ['type' => 'text', 'name' => 'Valido'],
        ]];

        (new data_settings($cm, $modsettings))->add_settings();

        $fields = $DB->get_records('data_fields', ['dataid' => $cm->instance]);
        $this->assertCount(1, $fields);
        $this->assertSame('Valido', reset($fields)->name);
    }

    /**
     * Duplicate names (the module requires unique names) and unknown types are skipped.
     */
    public function test_duplicate_and_unknown_type_skipped(): void {
        $this->resetAfterTest();
        global $DB;

        $cm = $this->make_data_cm();
        $modsettings = ['fields' => [
            ['type' => 'text', 'name' => 'Campo'],
            ['type' => 'textarea', 'name' => 'campo'],   // duplicate (case-insensitive) -> skipped
            ['type' => 'bogustype', 'name' => 'Raro'],    // unknown type -> skipped, no crash
            ['type' => 'number', 'name' => 'Cantidad'],
        ]];

        (new data_settings($cm, $modsettings))->add_settings();
        // The unknown type is caught per-field and logged via debugging() (never aborts the rest).
        $this->assertDebuggingCalled();

        $fields = $DB->get_records('data_fields', ['dataid' => $cm->instance]);
        $this->assertCount(2, $fields);
        $names = array_map(static fn($f) => $f->name, $fields);
        $this->assertContains('Campo', $names);
        $this->assertContains('Cantidad', $names);
    }

    /**
     * An empty/absent fields payload creates nothing.
     */
    public function test_no_fields_is_noop(): void {
        $this->resetAfterTest();
        global $DB;

        $cm = $this->make_data_cm();
        (new data_settings($cm, []))->add_settings();
        (new data_settings($cm, ['fields' => []]))->add_settings();

        $this->assertSame(0, $DB->count_records('data_fields', ['dataid' => $cm->instance]));
    }
}

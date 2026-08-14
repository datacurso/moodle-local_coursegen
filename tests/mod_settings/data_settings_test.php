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
        // The param1 = autolink -> the URL becomes a clickable link.
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
            ['type' => 'textarea', 'name' => 'campo'], // Duplicate (case-insensitive) -> skipped.
            ['type' => 'bogustype', 'name' => 'Raro'], // Unknown type -> skipped, no crash.
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

    /**
     * The database is sorted by its first sortable (identifying) field, not by time added.
     */
    public function test_default_sort_is_set_to_primary_field(): void {
        $this->resetAfterTest();
        global $DB;

        $cm = $this->make_data_cm();
        $modsettings = ['fields' => [
            ['type' => 'textarea', 'name' => 'Resena'], // Non-sortable, first -> skipped as primary.
            ['type' => 'text', 'name' => 'Titulo'], // First sortable -> primary.
            ['type' => 'number', 'name' => 'Anio'],
        ]];

        (new data_settings($cm, $modsettings))->add_settings();

        $data = $DB->get_record('data', ['id' => $cm->instance]);
        $titulo = $DB->get_record('data_fields', ['dataid' => $cm->instance, 'name' => 'Titulo']);
        $this->assertEquals($titulo->id, $data->defaultsort);
        $this->assertEquals(0, (int) $data->defaultsortdir);
    }

    /**
     * Custom display templates in the payload are written to the data instance.
     */
    public function test_sets_custom_templates_when_present(): void {
        $this->resetAfterTest();
        global $DB;

        $cm = $this->make_data_cm();
        $modsettings = [
            'fields' => [['type' => 'text', 'name' => 'Titulo']],
            'templates' => [
                'listtemplate' => '<div>[[Titulo]] ##edit## ##delete## ##more##</div>',
                'singletemplate' => '<h2>[[Titulo]]</h2> ##edit## ##delete## ##approve##',
            ],
        ];

        (new data_settings($cm, $modsettings))->add_settings();

        $data = $DB->get_record('data', ['id' => $cm->instance]);
        $this->assertStringContainsString('[[Titulo]]', $data->listtemplate);
        $this->assertStringContainsString('[[Titulo]]', $data->singletemplate);
    }

    /**
     * With no templates in the payload the template columns are left untouched (Moodle defaults).
     */
    public function test_templates_absent_leaves_columns_unchanged(): void {
        $this->resetAfterTest();
        global $DB;

        $cm = $this->make_data_cm();
        $before = $DB->get_record('data', ['id' => $cm->instance]);
        (new data_settings($cm, ['fields' => [['type' => 'text', 'name' => 'X']]]))->add_settings();
        $after = $DB->get_record('data', ['id' => $cm->instance]);

        $this->assertSame($before->listtemplate, $after->listtemplate);
        $this->assertSame($before->singletemplate, $after->singletemplate);
    }

    /**
     * Example entries are seeded as approved records, each field value serialised per type.
     */
    public function test_seeds_example_entries(): void {
        $this->resetAfterTest();
        $this->setAdminUser(); // Function data_add_record approves only with mod/data:approve.
        global $DB;

        $cm = $this->make_data_cm();
        $modsettings = [
            'fields' => [
                ['type' => 'text', 'name' => 'Titulo'],
                ['type' => 'menu', 'name' => 'Genero', 'options' => ['Novela', 'Ensayo']],
                ['type' => 'checkbox', 'name' => 'Tags', 'options' => ['A', 'B', 'C']],
                ['type' => 'date', 'name' => 'Anio'],
            ],
            'example_entries' => [
                ['values' => [
                    ['field_name' => 'Titulo', 'value' => 'Cien años de soledad'],
                    ['field_name' => 'Genero', 'value' => 'Novela'],
                    ['field_name' => 'Tags', 'value' => 'A, C'],
                    ['field_name' => 'Anio', 'value' => '1967-05-30'],
                ]],
            ],
        ];

        (new data_settings($cm, $modsettings))->add_settings();

        $records = $DB->get_records('data_records', ['dataid' => $cm->instance]);
        $this->assertCount(1, $records);
        $record = reset($records);
        $this->assertEquals(1, (int) $record->approved);

        $byfield = [];
        foreach ($DB->get_records('data_fields', ['dataid' => $cm->instance]) as $f) {
            $byfield[$f->name] = $f->id;
        }
        $content = static function (int $fieldid) use ($DB, $record) {
            return $DB->get_field(
                'data_content',
                'content',
                ['recordid' => $record->id, 'fieldid' => $fieldid]
            );
        };

        $this->assertSame('Cien años de soledad', $content($byfield['Titulo']));
        $this->assertSame('Novela', $content($byfield['Genero']));
        $this->assertSame('A##C', $content($byfield['Tags'])); // Multi -> ## delimited.
        $this->assertSame((string) strtotime('1967-05-30'), $content($byfield['Anio'])); // Date -> timestamp.
    }

    /**
     * No example entries in the payload -> no records created.
     */
    public function test_no_example_entries_creates_no_records(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $DB;

        $cm = $this->make_data_cm();
        (new data_settings($cm, ['fields' => [['type' => 'text', 'name' => 'X']]]))->add_settings();

        $this->assertSame(0, $DB->count_records('data_records', ['dataid' => $cm->instance]));
    }

    /**
     * Non-numeric values for a number field are skipped (Moodle stores floats there); numeric
     * values are stored normalised.
     */
    public function test_seed_number_field_requires_numeric(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $DB;

        $cm = $this->make_data_cm();
        $modsettings = [
            'fields' => [['type' => 'text', 'name' => 'T'], ['type' => 'number', 'name' => 'Precio']],
            'example_entries' => [
                ['values' => [['field_name' => 'T', 'value' => 'a'], ['field_name' => 'Precio', 'value' => '1.2 billones']]],
                ['values' => [['field_name' => 'T', 'value' => 'b'], ['field_name' => 'Precio', 'value' => '42.5']]],
            ],
        ];

        (new data_settings($cm, $modsettings))->add_settings();

        $precio = $DB->get_record('data_fields', ['dataid' => $cm->instance, 'name' => 'Precio']);
        $contents = $DB->get_records('data_content', ['fieldid' => $precio->id]);
        $this->assertCount(1, $contents); // The non-numeric one was skipped.
        $this->assertEquals(42.5, (float) reset($contents)->content);
    }

    /**
     * Unseedable types (picture/file) in an entry are skipped; the rest of the entry is stored.
     */
    public function test_seed_skips_unseedable_field(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $DB;

        $cm = $this->make_data_cm();
        $modsettings = [
            'fields' => [['type' => 'text', 'name' => 'T'], ['type' => 'picture', 'name' => 'Foto']],
            'example_entries' => [['values' => [
                ['field_name' => 'T', 'value' => 'hola'],
                ['field_name' => 'Foto', 'value' => 'foto.jpg'],
            ]]],
        ];

        (new data_settings($cm, $modsettings))->add_settings();

        $records = $DB->get_records('data_records', ['dataid' => $cm->instance]);
        $record = reset($records);
        $foto = $DB->get_record('data_fields', ['dataid' => $cm->instance, 'name' => 'Foto']);
        $t = $DB->get_record('data_fields', ['dataid' => $cm->instance, 'name' => 'T']);
        $this->assertSame(0, $DB->count_records('data_content', ['recordid' => $record->id, 'fieldid' => $foto->id]));
        $this->assertSame(1, $DB->count_records('data_content', ['recordid' => $record->id, 'fieldid' => $t->id]));
    }

    /**
     * With only non-sortable field types, the default time-added sort is kept (defaultsort = 0).
     */
    public function test_default_sort_kept_when_no_sortable_field(): void {
        $this->resetAfterTest();
        global $DB;

        $cm = $this->make_data_cm();
        $modsettings = ['fields' => [
            ['type' => 'textarea', 'name' => 'Texto'],
            ['type' => 'picture', 'name' => 'Foto'],
        ]];

        (new data_settings($cm, $modsettings))->add_settings();

        $data = $DB->get_record('data', ['id' => $cm->instance]);
        $this->assertEquals(0, (int) $data->defaultsort);
    }
}

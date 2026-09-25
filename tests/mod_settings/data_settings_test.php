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

use local_coursegen\local\service\template_activity_export;

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
     * Choice values that do not exactly match a defined option are dropped, because mod_data
     * renders stored choice content only on an exact option match (a mismatch shows blank).
     */
    public function test_seed_choice_values_must_match_options(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $DB;

        $cm = $this->make_data_cm();
        $modsettings = [
            'fields' => [
                ['type' => 'menu', 'name' => 'Genero', 'options' => ['Novela', 'Ensayo']],
                ['type' => 'checkbox', 'name' => 'Tags', 'options' => ['A', 'B', 'Si, claro']],
            ],
            'example_entries' => [
                ['values' => [
                    ['field_name' => 'Genero', 'value' => 'novela'], // Case mismatch -> dropped.
                    ['field_name' => 'Tags', 'value' => 'A, bogus, B'], // Unknown token -> filtered.
                ]],
                ['values' => [
                    ['field_name' => 'Tags', 'value' => 'Si, claro'], // Comma inside option -> no match.
                ]],
            ],
        ];

        (new data_settings($cm, $modsettings))->add_settings();

        $byfield = [];
        foreach ($DB->get_records('data_fields', ['dataid' => $cm->instance]) as $f) {
            $byfield[$f->name] = $f->id;
        }

        // The mismatched menu value was not stored at all.
        $this->assertSame(0, $DB->count_records('data_content', ['fieldid' => $byfield['Genero']]));

        // Only the exact-match checkbox tokens survived; the comma-in-option value produced nothing.
        $contents = $DB->get_records('data_content', ['fieldid' => $byfield['Tags']]);
        $this->assertCount(1, $contents);
        $this->assertSame('A##B', reset($contents)->content);
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
     * A mold's own field params are written verbatim, heuristics aside.
     *
     * The params ARE the field definition, so a mold that ships them must rebuild its own
     * columns, not the per-type guesses the model-driven path falls back to.
     */
    public function test_explicit_field_params_are_used_verbatim(): void {
        $this->resetAfterTest();
        global $DB;

        $cm = $this->make_data_cm();
        $modsettings = ['fields' => [
            [
                'type' => 'textarea', 'name' => 'Resumen',
                'param2' => '20', 'param3' => '5', 'param4' => '0', 'param5' => '10',
                'param6' => 'six', 'param7' => null, 'param10' => 'ten',
            ],
            ['type' => 'url', 'name' => 'Enlace', 'param1' => '0'],
            ['type' => 'menu', 'name' => 'Genero', 'param1' => "Novela\nEnsayo"],
        ]];

        (new data_settings($cm, $modsettings))->add_settings();

        $resumen = $DB->get_record('data_fields', ['dataid' => $cm->instance, 'name' => 'Resumen']);
        // The mold's sizes, not the plugin's 60/35/1/0 defaults.
        $this->assertSame('20', $resumen->param2);
        $this->assertSame('5', $resumen->param3);
        $this->assertSame('0', $resumen->param4);
        $this->assertSame('10', $resumen->param5);
        // Function define_field() only reads param1..param5, so the last five are written
        // straight onto the row - otherwise they would be silently dropped.
        $this->assertSame('six', $resumen->param6);
        $this->assertNull($resumen->param7);
        $this->assertSame('ten', $resumen->param10);

        // Autolink deliberately off in the mold: the '1' default must not override it.
        $enlace = $DB->get_record('data_fields', ['dataid' => $cm->instance, 'name' => 'Enlace']);
        $this->assertSame('0', $enlace->param1);

        // Choices taken from param1 itself, no options list needed.
        $genero = $DB->get_record('data_fields', ['dataid' => $cm->instance, 'name' => 'Genero']);
        $this->assertSame("Novela\nEnsayo", $genero->param1);
    }

    /**
     * Every template column mod_data owns is written, the non-empty ones only.
     */
    public function test_sets_every_non_empty_template_column(): void {
        $this->resetAfterTest();
        global $DB;

        $cm = $this->make_data_cm();
        $before = $DB->get_record('data', ['id' => $cm->instance]);
        $modsettings = [
            'fields' => [['type' => 'text', 'name' => 'Titulo']],
            'templates' => [
                'singletemplate' => '<h2>[[Titulo]]</h2>',
                'listtemplate' => '<div>[[Titulo]]</div>',
                'listtemplateheader' => '<table>',
                'listtemplatefooter' => '</table>',
                'addtemplate' => '<div>[[Titulo]] ##edit##</div>',
                'rsstemplate' => '<p>[[Titulo]]</p>',
                'rsstitletemplate' => '[[Titulo]]',
                'csstemplate' => '.c { color: red; }',
                'jstemplate' => 'window.console.log("x");',
                'asearchtemplate' => '',
            ],
        ];

        (new data_settings($cm, $modsettings))->add_settings();

        $data = $DB->get_record('data', ['id' => $cm->instance]);
        $this->assertSame('<h2>[[Titulo]]</h2>', $data->singletemplate);
        $this->assertSame('<div>[[Titulo]]</div>', $data->listtemplate);
        $this->assertSame('<table>', $data->listtemplateheader);
        $this->assertSame('</table>', $data->listtemplatefooter);
        $this->assertSame('<div>[[Titulo]] ##edit##</div>', $data->addtemplate);
        $this->assertSame('<p>[[Titulo]]</p>', $data->rsstemplate);
        $this->assertSame('[[Titulo]]', $data->rsstitletemplate);
        $this->assertSame('.c { color: red; }', $data->csstemplate);
        $this->assertSame('window.console.log("x");', $data->jstemplate);
        // Empty in the payload -> left to Moodle's lazy default.
        $this->assertSame($before->asearchtemplate, $data->asearchtemplate);
    }

    /**
     * The mold's own sort field wins over the "first sortable field" heuristic.
     *
     * The mold ships the NAME of the field it sorts by (its own row id is meaningless
     * here), so the created field carrying that name is the one to point at.
     */
    public function test_default_sort_follows_the_named_field(): void {
        $this->resetAfterTest();
        global $DB;

        $cm = $this->make_data_cm();
        $modsettings = [
            'fields' => [
                ['type' => 'text', 'name' => 'Titulo'],
                ['type' => 'text', 'name' => 'Categoria'],
            ],
            'defaultsortfield' => 'Categoria',
            'defaultsortdir' => 1,
        ];

        (new data_settings($cm, $modsettings))->add_settings();

        $data = $DB->get_record('data', ['id' => $cm->instance]);
        $categoria = $DB->get_record('data_fields', ['dataid' => $cm->instance, 'name' => 'Categoria']);
        $this->assertEquals($categoria->id, $data->defaultsort);
        $this->assertSame(1, (int) $data->defaultsortdir);
    }

    /**
     * A sort field that no created field carries falls back to the old heuristic.
     */
    public function test_unknown_default_sort_field_falls_back_to_heuristic(): void {
        $this->resetAfterTest();
        global $DB;

        $cm = $this->make_data_cm();
        $modsettings = [
            'fields' => [
                ['type' => 'textarea', 'name' => 'Resumen'],
                ['type' => 'text', 'name' => 'Titulo'],
            ],
            'defaultsortfield' => 'Inexistente',
            'defaultsortdir' => 1,
        ];

        (new data_settings($cm, $modsettings))->add_settings();

        $data = $DB->get_record('data', ['id' => $cm->instance]);
        $titulo = $DB->get_record('data_fields', ['dataid' => $cm->instance, 'name' => 'Titulo']);
        $this->assertEquals($titulo->id, $data->defaultsort);
        $this->assertSame(0, (int) $data->defaultsortdir);
    }

    /**
     * A url field's link text (value1) is written into data_content.content1, the
     * column mod_data reads to label the link instead of printing the address.
     */
    public function test_seeds_a_url_link_text_into_content1(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $rows = $this->seed_entry(
            [['type' => 'url', 'name' => 'Enlace']],
            [['field_name' => 'Enlace', 'value' => 'https://www.prueba.com', 'value1' => 'Texto visible del enlace']]
        );

        $this->assertSame('https://www.prueba.com', $rows['Enlace']->content);
        $this->assertSame('Texto visible del enlace', $rows['Enlace']->content1);
    }

    /**
     * A payload with no value1 behaves exactly as it did before the key existed:
     * the address is stored and content1 is left untouched. Every entry produced
     * by the model-driven path is shaped that way, so it must not shift.
     */
    public function test_seed_url_without_link_text_leaves_content1_unset(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $rows = $this->seed_entry(
            [['type' => 'url', 'name' => 'Enlace']],
            [['field_name' => 'Enlace', 'value' => 'https://www.prueba.com']]
        );

        $this->assertSame('https://www.prueba.com', $rows['Enlace']->content);
        $this->assertNull($rows['Enlace']->content1);
    }

    /**
     * The address is cleaned the way mod_data cleans it when a teacher types it.
     *
     * Function data_field_url::update_content() runs the address through
     * PARAM_URL, the link text through PARAM_NOTAGS, and prepends http:// to an
     * address that carries no scheme and is not a relative path. Storing the
     * value verbatim instead produced entries mod_data renders as dead links.
     */
    public function test_seed_url_content_is_cleaned_like_core(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $rows = $this->seed_entry(
            [
                ['type' => 'url', 'name' => 'Sin esquema'],
                ['type' => 'url', 'name' => 'Relativo'],
                ['type' => 'url', 'name' => 'Invalido'],
                ['type' => 'url', 'name' => 'Con etiquetas'],
            ],
            [
                ['field_name' => 'Sin esquema', 'value' => 'www.prueba.com'],
                ['field_name' => 'Relativo', 'value' => '/local/coursegen/index.php'],
                ['field_name' => 'Invalido', 'value' => 'not a url'],
                ['field_name' => 'Con etiquetas', 'value' => 'https://www.prueba.com', 'value1' => '<b>Texto</b> visible'],
            ]
        );

        $this->assertSame('http://www.prueba.com', $rows['Sin esquema']->content);
        $this->assertSame('/local/coursegen/index.php', $rows['Relativo']->content);
        $this->assertArrayNotHasKey('Invalido', $rows);
        $this->assertSame('Texto visible', $rows['Con etiquetas']->content1);
    }

    /**
     * One exported entry's whole value row for a field, keys included.
     *
     * @param array $entry One exported example entry.
     * @param string $fieldname The field whose row is wanted.
     * @return array|null The row, or null when the field shipped no value.
     */
    private function value_row(array $entry, string $fieldname): ?array {
        foreach ($entry['values'] as $pair) {
            if ($pair['field_name'] === $fieldname) {
                return $pair;
            }
        }
        return null;
    }

    /**
     * Seed one entry on a brand new database and read back what was stored.
     *
     * @param array $fields The field definitions.
     * @param array $values The entry's value rows.
     * @return array<string, \stdClass> The data_content row keyed by field name,
     *     absent for a field whose value was skipped.
     */
    private function seed_entry(array $fields, array $values): array {
        global $DB;

        $cm = $this->make_data_cm();
        $modsettings = ['fields' => $fields, 'example_entries' => [['values' => $values]]];
        (new data_settings($cm, $modsettings))->add_settings();

        $rows = [];
        foreach ($DB->get_records('data_fields', ['dataid' => $cm->instance]) as $field) {
            $content = $DB->get_record('data_content', ['fieldid' => $field->id]);
            if ($content) {
                $rows[$field->name] = $content;
            }
        }
        return $rows;
    }

    /**
     * A mold exported by template_activity_export rebuilds itself, without loss.
     *
     * This is the contract the two halves share: whatever the export ships under
     * mod_settings must come back as the same fields, the same template columns and
     * the same entry values on a brand new database.
     */
    public function test_exported_mold_round_trips_into_a_new_database(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$course, $mold] = $this->make_mold_with_content();
        $exported = template_activity_export::parameters_for(
            get_fast_modinfo($course)->get_cm($mold->cmid)
        );

        $copy = $this->getDataGenerator()->create_module('data', ['course' => $course->id]);
        $cm = (object) ['coursemodule' => $copy->cmid, 'instance' => $copy->id];
        (new data_settings($cm, $exported['mod_settings']))->add_settings();

        $rebuilt = template_activity_export::parameters_for(
            get_fast_modinfo($course)->get_cm($copy->cmid)
        );

        $this->assertSame($exported['mod_settings']['fields'], $rebuilt['mod_settings']['fields']);
        $this->assertSame($exported['mod_settings']['templates'], $rebuilt['mod_settings']['templates']);
        $this->assertSame(
            $exported['mod_settings']['example_entries'],
            $rebuilt['mod_settings']['example_entries']
        );
        // Assert what the comparison above must be comparing: a round trip that
        // dropped the link text on BOTH sides would match while losing the label.
        $entries = $rebuilt['mod_settings']['example_entries'];
        $this->assertSame('Sitio oficial ⟦tema⟧', $this->value_row($entries[0], 'Enlace')['value1'] ?? null);
        $this->assertArrayNotHasKey('value1', $this->value_row($entries[1], 'Enlace'));
        $this->assertSame(
            $exported['mod_settings']['defaultsortfield'],
            $rebuilt['mod_settings']['defaultsortfield']
        );
    }

    /**
     * Build a database mold carrying fields, templates and entries.
     *
     * @return array [course, data instance]
     */
    private function make_mold_with_content(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $mold = $this->getDataGenerator()->create_module('data', ['course' => $course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_data');

        $ids = [];
        // Descriptions are stated explicitly: define_field() trims them, and the generator's
        // own default carries a leading space that would never survive the trip.
        $specs = [
            ['type' => 'text', 'name' => 'Titulo del Recurso', 'description' => 'El titulo', 'required' => 1],
            ['type' => 'menu', 'name' => 'Categoria', 'description' => 'Tema', 'param1' => "Articulo\nVideo\nLibro"],
            ['type' => 'multimenu', 'name' => 'Etiquetas', 'description' => 'Claves', 'param1' => "A\nB\nC"],
            ['type' => 'url', 'name' => 'Enlace', 'description' => 'URL'],
            ['type' => 'textarea', 'name' => 'Resumen', 'description' => 'Analisis'],
            ['type' => 'number', 'name' => 'Puntaje', 'description' => 'Nota'],
            ['type' => 'date', 'name' => 'Fecha', 'description' => 'Publicacion'],
            ['type' => 'latlong', 'name' => 'Lugar', 'description' => 'Ubicacion'],
        ];
        foreach ($specs as $spec) {
            $ids[$spec['name']] = (int) $generator->create_field((object) $spec, $mold)->field->id;
        }

        $DB->update_record('data', (object) [
            'id' => $mold->id,
            'listtemplate' => '<div>[[Titulo del Recurso]] — [[Categoria]] ##edit##</div>',
            'singletemplate' => '<h2>[[Titulo del Recurso]] ⟦tema⟧</h2>',
            'addtemplate' => '<div>[[Resumen]]</div>',
            'csstemplate' => '.c { color: red; }',
            'defaultsort' => $ids['Categoria'],
            'defaultsortdir' => 1,
        ]);

        foreach ([['Uno ⟦tema⟧', 'Articulo', ['A', 'C'], '10.5'], ['Dos ⟦tema⟧', 'Libro', ['B'], '3.25']] as $i => $row) {
            $generator->create_entry($mold, [
                $ids['Titulo del Recurso'] => $row[0],
                $ids['Categoria'] => $row[1],
                $ids['Etiquetas'] => $row[2],
                // The first entry labels its link, the second does not: the round
                // trip has to preserve both the link text and its absence.
                $ids['Enlace'] => ['https://example.org/' . $i, $i === 0 ? 'Sitio oficial ⟦tema⟧' : ''],
                $ids['Resumen'] => '<p>Resumen ⟦texto⟧ ' . $i . '</p>',
                $ids['Puntaje'] => $row[3],
                $ids['Fecha'] => '30-05-1967',
                $ids['Lugar'] => ['1.5', '-2.25'],
            ]);
        }

        return [$course, $mold];
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

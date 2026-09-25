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

namespace local_coursegen\mod_export;

/**
 * Class data_export
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class data_export extends base_export {
    /** @var string[] Every template column mod_data really owns (see its install.xml). */
    private const DATA_TEMPLATE_COLUMNS = [
        'singletemplate', 'listtemplate', 'listtemplateheader', 'listtemplatefooter',
        'addtemplate', 'rsstemplate', 'rsstitletemplate', 'csstemplate', 'jstemplate',
        'asearchtemplate',
    ];

    /** @var string[] Field types whose value cannot be seeded back (their content is a file). */
    private const DATA_UNSEEDABLE_TYPES = ['file', 'picture'];

    /**
     * A Database's raw description, every instance setting, and its structure.
     *
     * Three traps of mod_data's schema shape this branch:
     *
     * - defaultsort stores a data_fields.id of THIS database. Reused as is it
     *   would point at a foreign row (or at nothing) in the generated one, so
     *   it never travels: the NAME of that field does, under defaultsortfield,
     *   and data_settings maps it back to the new field id.
     * - data_add_instance zeroes the rating window unless ratingtime says it is
     *   in use, exactly as mod_forum does, so that flag travels with the dates
     *   instead of being inferred on the way back in.
     * - The field definitions live in param1..param10, but only param1..param5
     *   ever reach data_field_base::define_field(). They all travel anyway; the
     *   consumer writes the last five straight onto the row.
     *
     * @return array
     */
    public function parameters(): array {
        global $DB;

        $data = $DB->get_record('data', ['id' => $this->cm->instance]);
        if (!$data) {
            return $this->minimal_parameters();
        }

        $dataid = (int) $data->id;
        $fields = $DB->get_records('data_fields', ['dataid' => $dataid], 'id ASC');

        $parameters = array_merge(
            $this->settings_columns($data),
            [
                'name' => $this->cm->name,
                'section' => (int) $this->cm->sectionnum,
                'intro' => $data->intro ?? '',
                'ratingtime' => (!empty($data->assesstimestart) && !empty($data->assesstimefinish)) ? 1 : 0,
                'defaultsortfield' => $this->default_sort_field($fields, (int) ($data->defaultsort ?? 0)),
            ]
        );

        $collections = [];
        if ($fields) {
            $collections['fields'] = $this->fields($fields);
        }
        $templates = $this->templates($data);
        if ($templates) {
            $collections['templates'] = $templates;
        }
        $entries = $this->example_entries($dataid, $fields);
        if ($entries) {
            $collections['example_entries'] = $entries;
        }
        // The sort field is a top-level setting, but only data_settings can resolve the name
        // into the new field's id - and mod_settings is all create_mod_service hands it - so
        // it travels there too, with the direction it has to apply alongside it.
        if ($parameters['defaultsortfield'] !== '') {
            $collections['defaultsortfield'] = $parameters['defaultsortfield'];
            $collections['defaultsortdir'] = (int) ($data->defaultsortdir ?? 0);
        }
        if ($collections) {
            $parameters['mod_settings'] = $collections;
        }

        return $parameters;
    }

    /**
     * The mod_data settings worth reproducing on the generated activity.
     *
     * The whole scope of the type travels (grading, entries, dates, access,
     * display and completion); identity and placement columns (id, course,
     * name, timemodified, config, defaultsort) are left out on purpose - they
     * describe THIS database, never the new one.
     *
     * @param \stdClass $data
     * @return array
     */
    private function settings_columns($data): array {
        $fields = [
            'approval', 'manageapproved', 'comments',
            'requiredentries', 'requiredentriestoview', 'maxentries',
            'timeavailablefrom', 'timeavailableto', 'timeviewfrom', 'timeviewto',
            'editany', 'notification', 'completionentries',
            'assessed', 'scale', 'assesstimestart', 'assesstimefinish',
            'defaultsortdir', 'rssarticles',
        ];

        return $this->whitelisted_settings($data, $fields);
    }

    /**
     * The NAME of the field the mold sorts by, or '' when it sorts by time added.
     *
     * @param array $fields data_fields rows, keyed by id.
     * @param int $defaultsort The mold's data.defaultsort (a data_fields.id).
     * @return string
     */
    private function default_sort_field(array $fields, int $defaultsort): string {
        if ($defaultsort <= 0 || !isset($fields[$defaultsort])) {
            return '';
        }
        return (string) $fields[$defaultsort]->name;
    }

    /**
     * Every field of one database, in creation order, definition included.
     *
     * The params are the definition itself (choices, sizes, autolink, ...) and
     * travel raw - they are nullable columns, and guessing them per type would
     * rebuild a different column than the one the author authored.
     *
     * @param array $fields data_fields rows, keyed by id and already ordered.
     * @return array
     */
    private function fields(array $fields): array {
        $exported = [];
        foreach ($fields as $field) {
            $spec = [
                'type' => $field->type,
                'name' => $field->name,
                'description' => $field->description ?? '',
                'required' => (int) $field->required,
            ];
            for ($param = 1; $param <= 10; $param++) {
                $key = 'param' . $param;
                $spec[$key] = $field->$key ?? null;
            }
            $exported[] = $spec;
        }
        return $exported;
    }

    /**
     * The mold's authored template columns, the non-empty ones only.
     *
     * They travel raw: a template carries both mod_data's own [[Field name]]
     * references and the service's markers, so any escaping or filtering here
     * would leave the generated database rendering nothing. An absent column
     * lets Moodle generate its own default, as it does for a hand-built one.
     *
     * @param \stdClass $data
     * @return array
     */
    private function templates($data): array {
        $templates = [];
        foreach (self::DATA_TEMPLATE_COLUMNS as $column) {
            $value = (string) ($data->$column ?? '');
            if (trim($value) !== '') {
                $templates[$column] = $value;
            }
        }
        return $templates;
    }

    /**
     * The mold's entries, in authoring order, re-encoded for the consumer.
     *
     * The shape is the one data_settings::seed_example_entries() already reads,
     * so an exported mold flows back in unchanged. Values of a file/picture
     * field are dropped: the consumer cannot seed them, and copying a mold's
     * embedded files is not implemented anywhere in the plugin yet.
     *
     * @param int $dataid
     * @param array $fields data_fields rows, keyed by id and already ordered.
     * @return array
     */
    private function example_entries(int $dataid, array $fields): array {
        global $DB;

        if (!$fields) {
            return [];
        }

        $records = $DB->get_records('data_records', ['dataid' => $dataid], 'id ASC', 'id');
        $entries = [];
        foreach ($records as $record) {
            $contents = $DB->get_records(
                'data_content',
                ['recordid' => $record->id],
                '',
                'fieldid, content, content1'
            );
            $values = [];
            foreach ($fields as $field) {
                $content = $contents[$field->id] ?? null;
                if ($content === null) {
                    continue;
                }
                $row = $this->entry_row((string) $field->type, $content);
                if ($row !== null) {
                    $values[] = ['field_name' => $field->name] + $row;
                }
            }
            if ($values) {
                $entries[] = ['values' => $values];
            }
        }
        return $entries;
    }

    /**
     * One stored value, back in the form data_settings reads.
     *
     * The row is always ['value' => ...], plus an optional 'value1' for the
     * types that really own a second stored column. Each type is the exact
     * inverse of data_settings::insert_content(), which is what makes the round
     * trip lossless:
     *
     * - A date is stored as a unix timestamp, but insert_content() parses its
     *   input with strtotime(), and strtotime('1700000000') is false - so the
     *   day travels as a date string, not as the raw timestamp.
     * - multimenu/checkbox are stored '##' delimited, while insert_content()
     *   splits its input on commas, so the options travel comma separated.
     * - latlong keeps its pair in content/content1 but travels as ONE comma
     *   separated value, the way insert_content() has always read it back: the
     *   pair is meaningless split in two, that encoding already round trips
     *   exactly, and it is the shape the model-driven path emits.
     * - A url owns a real second authored string - the visible link text that
     *   mod_data stores in content1 - so it travels as value1, and ONLY when
     *   the author wrote one. An absent value1 is meaningful: it tells the
     *   consumer to leave content1 alone and keeps the payload identical to
     *   the one every other type ships.
     *
     * @param string $type The field type.
     * @param \stdClass $content The data_content row (content, content1).
     * @return array|null ['value' => string, 'value1' => string (optional)], or
     *     null when this type cannot be seeded back.
     */
    private function entry_row(string $type, $content): ?array {
        if (in_array($type, self::DATA_UNSEEDABLE_TYPES, true)) {
            return null;
        }

        $raw = (string) ($content->content ?? '');
        if ($raw === '') {
            return null;
        }

        switch ($type) {
            case 'date':
                return ['value' => date('Y-m-d', (int) $raw)];
            case 'multimenu':
            case 'checkbox':
                return ['value' => implode(', ', explode('##', $raw))];
            case 'latlong':
                $longitude = (string) ($content->content1 ?? '');
                return $longitude === '' ? null : ['value' => $raw . ', ' . $longitude];
            case 'url':
                $linktext = (string) ($content->content1 ?? '');
                return $linktext === '' ? ['value' => $raw] : ['value' => $raw, 'value1' => $linktext];
            default:
                return ['value' => $raw];
        }
    }
}

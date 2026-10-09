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

namespace local_coursegen\reportbuilder\local\entities;

use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use lang_string;
use local_coursegen\local\template\template_actions;

/**
 * Template entity for Report Builder: a saved template, its course and how many activities it carries.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template extends base {
    /** @var string Table of the templates. */
    private const TEMPLATE_TABLE = 'local_coursegen_template';

    /** @var string Table with the choice saved for each activity. */
    private const ITEM_TABLE = 'local_coursegen_tpl_item';

    /**
     * Database tables this entity uses.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return [self::TEMPLATE_TABLE, 'course', self::ITEM_TABLE];
    }

    /**
     * Entity title.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('managetemplates', 'local_coursegen');
    }

    /**
     * Initialise the entity: its columns and its filters.
     *
     * @return base
     */
    public function initialise(): base {
        $this->add_all_columns();
        $this->add_all_filters();

        return $this;
    }

    /**
     * Format the name of a template.
     *
     * @param string|null $value Name as saved, for example "Marketing base".
     * @return string
     */
    public static function format_name(?string $value): string {
        return format_string((string) $value);
    }

    /**
     * Format the course of a template, saying so when the course no longer exists.
     *
     * @param string|null $value Full name of the course, null when the course is gone.
     * @return string
     */
    public static function format_course(?string $value): string {
        if ($value === null) {
            return get_string('template_course_missing', 'local_coursegen');
        }

        return format_string($value);
    }

    /**
     * Register every column of the entity.
     */
    private function add_all_columns(): void {
        $columns = $this->get_all_columns();
        foreach ($columns as $column) {
            $this->add_column($column);
        }
    }

    /**
     * Register every filter of the entity, as a filter and as a condition.
     */
    private function add_all_filters(): void {
        $filters = $this->get_all_filters();
        foreach ($filters as $filter) {
            $this->add_filter($filter)->add_condition($filter);
        }
    }

    /**
     * Define all the columns.
     *
     * @return column[]
     */
    protected function get_all_columns(): array {
        return [
            $this->name_column(),
            $this->course_column(),
            $this->activities_column(),
            $this->ai_column(),
            $this->modified_column(),
        ];
    }

    /**
     * Name of the template.
     *
     * @return column
     */
    private function name_column(): column {
        $alias = $this->get_table_alias(self::TEMPLATE_TABLE);
        $joins = $this->get_joins();
        $title = new lang_string('template_col_name', 'local_coursegen');
        $entityname = $this->get_entity_name();
        $column = new column('name', $title, $entityname);

        return $column
            ->add_joins($joins)
            ->set_type(column::TYPE_TEXT)
            ->add_fields("{$alias}.name")
            ->set_is_sortable(true)
            ->add_callback([self::class, 'format_name']);
    }

    /**
     * Full name of the course the template was made from.
     *
     * @return column
     */
    private function course_column(): column {
        $alias = $this->get_table_alias(self::TEMPLATE_TABLE);
        $coursealias = $this->get_table_alias('course');
        $joins = $this->get_joins();
        $title = new lang_string('template_col_course', 'local_coursegen');
        $entityname = $this->get_entity_name();
        $column = new column('coursefullname', $title, $entityname);

        return $column
            ->add_joins($joins)
            ->add_join("LEFT JOIN {course} {$coursealias} ON {$coursealias}.id = {$alias}.courseid")
            ->set_type(column::TYPE_TEXT)
            ->add_fields("{$coursealias}.fullname")
            ->set_is_sortable(true)
            ->add_callback([self::class, 'format_course']);
    }

    /**
     * How many activities the template carries.
     *
     * @return column
     */
    private function activities_column(): column {
        $alias = $this->get_table_alias(self::TEMPLATE_TABLE);
        $itemalias = $this->get_table_alias(self::ITEM_TABLE);
        $sql = "(SELECT COUNT(1) FROM {" . self::ITEM_TABLE . "} {$itemalias} WHERE {$itemalias}.templateid = {$alias}.id)";
        $joins = $this->get_joins();
        $title = new lang_string('template_col_activities', 'local_coursegen');
        $entityname = $this->get_entity_name();
        $column = new column('activitycount', $title, $entityname);

        return $column
            ->add_joins($joins)
            ->set_type(column::TYPE_INTEGER)
            ->add_field($sql, 'activitycount')
            ->set_is_sortable(true);
    }

    /**
     * How many activities the AI modifies.
     *
     * @return column
     */
    private function ai_column(): column {
        $alias = $this->get_table_alias(self::TEMPLATE_TABLE);
        $itemalias = $this->get_table_alias(self::ITEM_TABLE);
        $param = database::generate_param_name();
        $sql = "(SELECT COUNT(1) FROM {" . self::ITEM_TABLE . "} {$itemalias}
                  WHERE {$itemalias}.templateid = {$alias}.id AND {$itemalias}.action = :{$param})";
        $params = [$param => template_actions::AI];
        $joins = $this->get_joins();
        $title = new lang_string('template_col_ai', 'local_coursegen');
        $entityname = $this->get_entity_name();
        $column = new column('aicount', $title, $entityname);

        return $column
            ->add_joins($joins)
            ->set_type(column::TYPE_INTEGER)
            ->add_field($sql, 'aicount', $params)
            ->set_is_sortable(true);
    }

    /**
     * Moment the template was last saved.
     *
     * @return column
     */
    private function modified_column(): column {
        $alias = $this->get_table_alias(self::TEMPLATE_TABLE);
        $joins = $this->get_joins();
        $title = new lang_string('template_col_modified', 'local_coursegen');
        $entityname = $this->get_entity_name();
        $column = new column('timemodified', $title, $entityname);

        return $column
            ->add_joins($joins)
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_fields("{$alias}.timemodified")
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate']);
    }

    /**
     * Define all the filters.
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $alias = $this->get_table_alias(self::TEMPLATE_TABLE);
        $joins = $this->get_joins();
        $title = new lang_string('template_col_name', 'local_coursegen');
        $entityname = $this->get_entity_name();
        $filter = new filter(text::class, 'name', $title, $entityname, "{$alias}.name");
        $filter->add_joins($joins);

        return [$filter];
    }
}

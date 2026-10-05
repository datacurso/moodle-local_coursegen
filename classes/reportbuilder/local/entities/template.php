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

/**
 * Template entity for report builder.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\reportbuilder\local\entities;

use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use lang_string;

/**
 * Template entity.
 */
class template extends base {

    /**
     * Database tables this entity uses.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return ['local_coursegen_template', 'course'];
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
     * Initialise entity.
     *
     * @return base
     */
    public function initialise(): base {
        foreach ($this->get_all_columns() as $column) {
            $this->add_column($column);
        }
        foreach ($this->get_all_filters() as $filter) {
            $this->add_filter($filter)->add_condition($filter);
        }
        return $this;
    }

    /**
     * Define all columns.
     *
     * @return column[]
     */
    protected function get_all_columns(): array {
        $alias = $this->get_table_alias('local_coursegen_template');
        $coursealias = $this->get_table_alias('course');
        $columns = [];

        $columns[] = (new column('name', new lang_string('name'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_fields("{$alias}.name")
            ->set_is_sortable(true);

        $columns[] = (new column('coursefullname', new lang_string('course'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->add_join("LEFT JOIN {course} {$coursealias} ON {$coursealias}.id = {$alias}.courseid")
            ->set_type(column::TYPE_TEXT)
            ->add_fields("{$coursealias}.fullname")
            ->set_is_sortable(true);

        $columns[] = (new column(
            'timecreated', new lang_string('timecreated', 'core_reportbuilder'), $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_fields("{$alias}.timecreated")
            ->set_is_sortable(true)
            ->set_callback([\core_reportbuilder\local\helpers\format::class, 'userdate']);

        $columns[] = (new column(
            'timemodified', new lang_string('timemodified', 'core_reportbuilder'), $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_fields("{$alias}.timemodified")
            ->set_is_sortable(true)
            ->set_callback([\core_reportbuilder\local\helpers\format::class, 'userdate']);

        return $columns;
    }

    /**
     * Define all filters.
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $alias = $this->get_table_alias('local_coursegen_template');
        $filters = [];

        $filters[] = (new filter(
            text::class,
            'name',
            new lang_string('name'),
            $this->get_entity_name(),
            "{$alias}.name"
        ))->add_joins($this->get_joins());

        return $filters;
    }
}

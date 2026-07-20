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
 * Templates system report.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\reportbuilder\local\systemreports;

use local_coursegen\reportbuilder\local\entities\template;
use core_reportbuilder\local\report\action;
use core_reportbuilder\system_report;
use lang_string;
use moodle_url;
use pix_icon;

/**
 * System report for course templates.
 */
class templates extends system_report {

    /**
     * Initialise the report.
     */
    protected function initialise(): void {
        $entity = new template();
        $alias = $entity->get_table_alias('local_coursegen_template');

        $this->set_main_table('local_coursegen_template', $alias);
        $this->add_entity($entity);
        $this->add_base_fields("{$alias}.id, {$alias}.name");

        $this->add_columns();
        $this->add_filters();
        $this->add_actions();

        $this->set_downloadable(false);
    }

    /**
     * Check if the user can view the report.
     *
     * @return bool
     */
    protected function can_view(): bool {
        return has_capability('local/coursegen:managetemplates', $this->get_context());
    }

    /**
     * Add columns to the report.
     */
    protected function add_columns(): void {
        $this->add_column_from_entity('template:name');
        $this->add_column_from_entity('template:coursefullname');
        $this->add_column_from_entity('template:timemodified');
        $this->set_initial_sort_column('template:timemodified', SORT_DESC);
    }

    /**
     * Add filters to the report.
     */
    protected function add_filters(): void {
        $this->add_filter_from_entity('template:name');
    }

    /**
     * Add row actions.
     */
    protected function add_actions(): void {
        $this->add_action((new action(
            new moodle_url('/local/coursegen/edit_template.php', ['id' => ':id']),
            new pix_icon('t/edit', ''),
            [],
            false,
            new lang_string('edit')
        )));

        $this->add_action((new action(
            new moodle_url('/local/coursegen/manage_templates.php', [
                'action' => 'delete',
                'id' => ':id',
                'sesskey' => sesskey(),
            ]),
            new pix_icon('t/delete', ''),
            [],
            false,
            new lang_string('delete')
        )));
    }
}

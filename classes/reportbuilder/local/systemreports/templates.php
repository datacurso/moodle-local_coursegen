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

namespace local_coursegen\reportbuilder\local\systemreports;

use core_reportbuilder\local\report\action;
use core_reportbuilder\system_report;
use lang_string;
use local_coursegen\local\template\template_access;
use local_coursegen\reportbuilder\local\entities\template;
use moodle_url;
use pix_icon;

/**
 * The list of templates: name, course, activities, activities modified with AI and last modification.
 *
 * Each row has the actions to edit and to delete a template. Deleting asks first, in a dialogue that the
 * amd module template/manage opens.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
     * Whether the user can see the report.
     *
     * @return bool
     */
    protected function can_view(): bool {
        return template_access::can_manage();
    }

    /**
     * Add the columns of the report, the last saved first.
     */
    protected function add_columns(): void {
        $this->add_column_from_entity('template:name');
        $this->add_column_from_entity('template:coursefullname');
        $this->add_column_from_entity('template:activitycount');
        $this->add_column_from_entity('template:aicount');
        $this->add_column_from_entity('template:timemodified');
        $this->set_initial_sort_column('template:timemodified', SORT_DESC);
    }

    /**
     * Add the filters of the report.
     */
    protected function add_filters(): void {
        $this->add_filter_from_entity('template:name');
    }

    /**
     * Add the actions of each row.
     */
    protected function add_actions(): void {
        $this->add_edit_action();
        $this->add_delete_action();
    }

    /**
     * Add the action that opens the editor of the template.
     */
    private function add_edit_action(): void {
        $url = new moodle_url('/local/coursegen/edit_template.php', ['id' => ':id']);
        $icon = new pix_icon('t/edit', '');
        $title = new lang_string('edit');

        $action = new action($url, $icon, [], false, $title);
        $this->add_action($action);
    }

    /**
     * Add the action that deletes the template after asking.
     *
     * The attributes carry the template for the amd module: ":id" and ":name" are filled with the row.
     */
    private function add_delete_action(): void {
        $url = new moodle_url('/local/coursegen/manage_templates.php');
        $icon = new pix_icon('t/delete', '');
        $title = new lang_string('delete');
        $attributes = [
            'data-action' => 'local_coursegen/template/delete',
            'data-templateid' => ':id',
            'data-name' => ':name',
        ];

        $action = new action($url, $icon, $attributes, false, $title);
        $this->add_action($action);
    }
}

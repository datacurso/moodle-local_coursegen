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

namespace local_coursegen\output;

use local_coursegen\local\template\template_repository;
use moodle_url;
use renderable;
use renderer_base;
use templatable;

/**
 * The list of templates: name, course, how many activities the AI modifies, and the actions of each one.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manage_templates_page implements renderable, templatable {
    /** @var template_repository Where the templates live. */
    private template_repository $repository;

    /**
     * Constructor.
     *
     * @param template_repository|null $repository Repository to use, or null for the default one.
     */
    public function __construct(?template_repository $repository = null) {
        if ($repository === null) {
            $repository = new template_repository();
        }
        $this->repository = $repository;
    }

    /**
     * Export the data the template needs.
     *
     * @param renderer_base $output Renderer.
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $templates = $this->repository->list_all();
        $counts = $this->repository->count_items();
        $rows = [];
        foreach ($templates as $template) {
            $rows[] = $this->describe_template($template, $counts);
        }

        $createurl = new moodle_url('/local/coursegen/edit_template.php');

        $createaddress = $createurl->out(false);
        return [
            'templates' => $rows,
            'hastemplates' => $rows !== [],
            'createurl' => $createaddress,
        ];
    }

    /**
     * Describe one template as a row of the list.
     *
     * @param \stdClass $template Row of the template.
     * @param array[] $counts Activities of every template, keyed by template id.
     * @return array
     */
    private function describe_template(\stdClass $template, array $counts): array {
        global $DB;

        $course = $DB->get_record('course', ['id' => $template->courseid], 'id, fullname');
        $coursename = '';
        if ($course) {
            $coursename = format_string($course->fullname);
        }

        $itemcounts = $counts[(int) $template->id] ?? ['total' => 0, 'ai' => 0];
        $editurl = new moodle_url('/local/coursegen/edit_template.php', ['id' => $template->id]);
        $dateformat = get_string('strftimedatetimeshort');
        $modified = userdate($template->timemodified, $dateformat);
        $name = format_string($template->name);
        $editaddress = $editurl->out(false);

        return [
            'id' => (int) $template->id,
            'name' => $name,
            'coursename' => $coursename,
            'coursemissing' => !$course,
            'activitycount' => $itemcounts['total'],
            'aicount' => $itemcounts['ai'],
            'modified' => $modified,
            'editurl' => $editaddress,
        ];
    }
}

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

use core_course_category;
use local_coursegen\local\template\plain_text;
use local_coursegen\local\template\template_service;
use moodle_url;
use renderable;
use renderer_base;
use templatable;

/**
 * The editor of a template: the course picker, the name and description, and for each section its activities
 * with the two choices an admin has for each one.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class edit_template_page implements renderable, templatable {
    /** @var array What the service loaded: template, courseid, courseusable, sections and missing. */
    private array $loaded;

    /**
     * Constructor.
     *
     * @param int $templateid Template being edited, or 0 for a new one, for example 3.
     * @param int $courseid Course chosen in the picker, or 0 to use the one of the template, for example 42.
     * @param template_service|null $service Service to load with, or null for the default one.
     */
    public function __construct(int $templateid, int $courseid, ?template_service $service = null) {
        if ($service === null) {
            $service = new template_service();
        }
        $this->loaded = $service->load_for_edit($templateid, $courseid);
    }

    /**
     * Export the data the template needs.
     *
     * @param renderer_base $output Renderer.
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $template = $this->loaded['template'];
        $courseid = $this->loaded['courseid'];
        $templateid = 0;
        $name = '';
        $description = '';
        if ($template !== null) {
            $templateid = (int) $template->id;
            $name = (string) $template->name;
            $description = (string) $template->description;
        }

        $sections = $this->describe_sections($this->loaded['sections']);
        $missing = $this->describe_missing($this->loaded['missing']);
        $listurl = new moodle_url('/local/coursegen/manage_templates.php');
        $editurl = new moodle_url('/local/coursegen/edit_template.php');
        $course = $this->selected_course($courseid);

        $categories = $this->describe_categories($course['categoryid']);
        $listaddress = $listurl->out(false);
        $editaddress = $editurl->out(false);

        return [
            'templateid' => $templateid,
            'courseid' => $courseid,
            'name' => $name,
            'description' => $description,
            'maxnamelength' => 255,
            'maxtextlength' => plain_text::MAX_LENGTH,
            'categories' => $categories,
            'coursename' => $course['label'],
            'hascourse' => $course['label'] !== '',
            'courseusable' => $this->loaded['courseusable'],
            'hassections' => $sections !== [],
            'sections' => $sections,
            'hasmissing' => $missing !== [],
            'missing' => $missing,
            'listurl' => $listaddress,
            'editurl' => $editaddress,
        ];
    }

    /**
     * The label and category of the chosen course.
     *
     * @param int $courseid Course id, or 0.
     * @return array With label and categoryid.
     */
    private function selected_course(int $courseid): array {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname, category');
        if (!$course || !$this->loaded['courseusable']) {
            return ['label' => '', 'categoryid' => 0];
        }

        $label = format_string($course->fullname);

        return ['label' => $label, 'categoryid' => (int) $course->category];
    }

    /**
     * The categories of the filter, with the one of the chosen course selected.
     *
     * @param int $selectedid Category to select, or 0 for all categories.
     * @return array[]
     */
    private function describe_categories(int $selectedid): array {
        $categories = core_course_category::make_categories_list();
        $allname = get_string('template_category_all', 'local_coursegen');
        $alloption = ['id' => 0, 'name' => $allname, 'selected' => $selectedid === 0];
        $options = [$alloption];
        foreach ($categories as $id => $name) {
            $options[] = ['id' => (int) $id, 'name' => $name, 'selected' => (int) $id === $selectedid];
        }

        return $options;
    }

    /**
     * Describe the sections for the template.
     *
     * @param array[] $sections Sections loaded by the service.
     * @return array[]
     */
    private function describe_sections(array $sections): array {
        $described = [];
        foreach ($sections as $section) {
            $section['activities'] = $this->describe_activities($section['activities']);
            $section['hasactivities'] = $section['activities'] !== [];
            $section['activitycount'] = count($section['activities']);
            $described[] = $section;
        }

        return $described;
    }

    /**
     * Describe the activities of a section for the template.
     *
     * @param array[] $activities Activities with action and instruction.
     * @return array[]
     */
    private function describe_activities(array $activities): array {
        $described = [];
        foreach ($activities as $activity) {
            $activity['name'] = format_string($activity['name']);
            $activity['isai'] = $activity['action'] === 'ai';
            $activity['iskeep'] = $activity['action'] !== 'ai';
            $described[] = $activity;
        }

        return $described;
    }

    /**
     * Describe the saved activities that no longer exist.
     *
     * @param array[] $missing Entries with cmid, action and instruction.
     * @return array[]
     */
    private function describe_missing(array $missing): array {
        $described = [];
        foreach ($missing as $entry) {
            $label = get_string('template_missing_item', 'local_coursegen', $entry['cmid']);
            $described[] = ['cmid' => $entry['cmid'], 'label' => $label];
        }

        return $described;
    }
}

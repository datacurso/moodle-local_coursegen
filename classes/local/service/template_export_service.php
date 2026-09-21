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

namespace local_coursegen\local\service;

use local_coursegen\local\models\template;
use local_coursegen\local\models\template_activity;
use local_coursegen\local\models\template_instance;
use local_coursegen\local\models\template_section;

/**
 * Builds the /course-template/init payload for one saved template.
 *
 * Two kinds of entry travel in "activities": the base course's own real
 * activities (carrying the admin's saved action - keep/reference/template),
 * and the template's virtual instances, which have no course module of their
 * own and are submitted as action="modify" driven by the mold they were
 * created from (template_source_cmid).
 *
 * How every element is named stably across requests lives in
 * template_export_uids.php; how a section's own layout is described lives in
 * template_export_sections.php.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_export_service {
    /**
     * Build the full init payload.
     *
     * @param int $templateid
     * @param string $generalinstruction The professor's single prompt/syllabus instruction.
     * @return array
     */
    public static function build_init_payload(int $templateid, string $generalinstruction = ''): array {
        global $CFG;

        $template = template::get_record(['id' => $templateid]);
        if (!$template) {
            throw new \moodle_exception('invalidtemplate', 'local_coursegen');
        }

        $course = get_course($template->get('courseid'));
        $modinfo = get_fast_modinfo($course);

        $actions = self::saved_activity_actions($templateid);
        $behaviors = self::saved_section_behaviors($templateid);

        return [
            'course_configuration' => [
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
                'lang' => $course->lang ?: current_language(),
                'numsections' => count($modinfo->get_section_info_all()) - 1,
                // How the course is laid out, and how its format is set up.
                // A course generated from a template is the template's course
                // with other content in it, so it is read the same way, and a
                // preview of it has to be drawn the same way.
                'format' => $course->format,
                'format_options' => template_export_sections::format_settings($course),
            ],
            'sections_info' => template_export_sections::sections_info($templateid, $course, $modinfo, $behaviors),
            'activities' => array_merge(
                self::real_activities($templateid, $modinfo, $actions),
                self::instance_activities($templateid, $modinfo)
            ),
            'general_instruction' => $generalinstruction,
            'lang' => $course->lang ?: current_language(),
            'with_images' => false,
            'site_url' => $CFG->wwwroot,
            // Reference files are attached after /init (they need its
            // thread_id), so the run must not start until they have landed.
            'defer_start' => true,
        ];
    }

    /**
     * cmid => saved action, for this template.
     *
     * @param int $templateid
     * @return array
     */
    private static function saved_activity_actions(int $templateid): array {
        $actions = [];
        foreach (template_activity::get_records(['templateid' => $templateid]) as $activity) {
            $actions[(int) $activity->get('cmid')] = $activity->get('action');
        }
        return $actions;
    }

    /**
     * sectionid => saved behavior, for this template.
     *
     * @param int $templateid
     * @return array
     */
    private static function saved_section_behaviors(int $templateid): array {
        $behaviors = [];
        foreach (template_section::get_records(['templateid' => $templateid]) as $section) {
            $behaviors[(int) $section->get('sectionid')] = $section->get('behavior');
        }
        return $behaviors;
    }

    /**
     * The base course's own activities, excluding the ones marked "exclude".
     *
     * @param int $templateid
     * @param \course_modinfo $modinfo
     * @param array $actions
     * @return array
     */
    private static function real_activities(int $templateid, $modinfo, array $actions): array {
        $activities = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $action = $actions[$cm->id] ?? 'keep';
            if ($action === 'exclude') {
                continue;
            }
            $activities[] = [
                'resource_type' => $cm->modname,
                'uid' => template_export_uids::stable_uid($templateid, 'cm', (int) $cm->id),
                'cmid' => (int) $cm->id,
                'parameters' => template_activity_export::parameters_for($cm),
                'template_behavior' => ['action' => $action, 'useasreference' => true],
            ];
        }
        return $activities;
    }

    /**
     * The template's virtual instances, as "modify" activities driven by their mold.
     *
     * @param int $templateid
     * @param \course_modinfo $modinfo
     * @return array
     */
    private static function instance_activities(int $templateid, $modinfo): array {
        $sectionnums = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            $sectionnums[(int) $section->id] = (int) $section->section;
        }

        $activities = [];
        $instances = template_instance::get_records(['templateid' => $templateid], 'sortorder');
        foreach ($instances as $instance) {
            $activities[] = [
                'resource_type' => $instance->get('modname') ?: 'lesson',
                'uid' => template_export_uids::instance_uid($instance),
                'cmid' => template_export_uids::instance_cmid((int) $instance->get('id')),
                'parameters' => [
                    'name' => $instance->get('name'),
                    'section' => $sectionnums[(int) $instance->get('sectionid')] ?? 0,
                ],
                'template_behavior' => [
                    'action' => 'modify',
                    'useasreference' => true,
                    'prompt' => (string) $instance->get('prompt'),
                    'template_source_cmid' => (int) $instance->get('sourcecmid'),
                ],
            ];
        }
        return $activities;
    }
}

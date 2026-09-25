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

        $courseid = $template->get('courseid');
        $course = get_course($courseid);
        $modinfo = get_fast_modinfo($course);

        $actions = self::saved_activity_actions($templateid);
        $behaviors = self::saved_section_behaviors($templateid);

        $lang = $course->lang;
        if (!$lang) {
            $lang = current_language();
        }

        $sectioninfoall = $modinfo->get_section_info_all();
        $numsections = count($sectioninfoall) - 1;

        $formatoptions = template_export_sections::format_settings($course);
        $sectionsinfo = template_export_sections::sections_info($templateid, $course, $modinfo, $behaviors);

        $realactivities = self::real_activities($templateid, $modinfo, $actions);
        $instanceactivities = self::instance_activities($templateid, $modinfo);
        $activities = array_merge($realactivities, $instanceactivities);

        $courseconfiguration = [
            'fullname' => $course->fullname,
            'shortname' => $course->shortname,
            'lang' => $lang,
            'numsections' => $numsections,
            // How the course is laid out, and how its format is set up.
            // A course generated from a template is the template's course
            // with other content in it, so it is read the same way, and a
            // preview of it has to be drawn the same way.
            'format' => $course->format,
            'format_options' => $formatoptions,
        ];

        return [
            'course_configuration' => $courseconfiguration,
            'sections_info' => $sectionsinfo,
            'activities' => $activities,
            'general_instruction' => $generalinstruction,
            'lang' => $lang,
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
        $records = template_activity::get_records(['templateid' => $templateid]);
        $actions = [];
        foreach ($records as $activity) {
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
        $records = template_section::get_records(['templateid' => $templateid]);
        $behaviors = [];
        foreach ($records as $section) {
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
        $cms = $modinfo->get_cms();
        $activities = [];
        foreach ($cms as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $action = $actions[$cm->id] ?? 'keep';
            if ($action === 'exclude') {
                continue;
            }
            $activities[] = self::real_activity_entry($templateid, $cm, $action);
        }
        return $activities;
    }

    /**
     * One real activity's own entry.
     *
     * @param int $templateid
     * @param \cm_info $cm
     * @param string $action
     * @return array
     */
    private static function real_activity_entry(int $templateid, $cm, string $action): array {
        $cmid = (int) $cm->id;
        $uid = template_export_uids::stable_uid($templateid, 'cm', $cmid);
        $parameters = template_activity_export::parameters_for($cm);
        return [
            'resource_type' => $cm->modname,
            'uid' => $uid,
            'cmid' => $cmid,
            'parameters' => $parameters,
            'template_behavior' => ['action' => $action, 'useasreference' => true],
        ];
    }

    /**
     * The template's virtual instances, as "modify" activities driven by their mold.
     *
     * @param int $templateid
     * @param \course_modinfo $modinfo
     * @return array
     */
    private static function instance_activities(int $templateid, $modinfo): array {
        $sectionnums = self::section_numbers_by_id($modinfo);

        $instances = template_instance::get_records(['templateid' => $templateid], 'sortorder');
        $activities = [];
        foreach ($instances as $instance) {
            $activities[] = self::instance_activity_entry($instance, $sectionnums);
        }
        return $activities;
    }

    /**
     * Every section's own number, keyed by its id.
     *
     * @param \course_modinfo $modinfo
     * @return array
     */
    private static function section_numbers_by_id($modinfo): array {
        $sections = $modinfo->get_section_info_all();
        $sectionnums = [];
        foreach ($sections as $section) {
            $sectionnums[(int) $section->id] = (int) $section->section;
        }
        return $sectionnums;
    }

    /**
     * One template instance's own entry, as a "modify" activity driven by its mold.
     *
     * @param \local_coursegen\local\models\template_instance $instance
     * @param array $sectionnums Section id => section number.
     * @return array
     */
    private static function instance_activity_entry($instance, array $sectionnums): array {
        $modname = $instance->get('modname');
        if (!$modname) {
            $modname = 'lesson';
        }

        $instanceid = $instance->get('id');
        $instanceid = (int) $instanceid;

        $uid = template_export_uids::instance_uid($instance);
        $cmid = template_export_uids::instance_cmid($instanceid);

        $sectionid = $instance->get('sectionid');
        $sectionid = (int) $sectionid;
        $section = $sectionnums[$sectionid] ?? 0;

        $name = $instance->get('name');

        $prompt = $instance->get('prompt');
        $prompt = (string) $prompt;

        $sourcecmid = $instance->get('sourcecmid');
        $sourcecmid = (int) $sourcecmid;

        return [
            'resource_type' => $modname,
            'uid' => $uid,
            'cmid' => $cmid,
            'parameters' => [
                'name' => $name,
                'section' => $section,
            ],
            'template_behavior' => [
                'action' => 'modify',
                'useasreference' => true,
                'prompt' => $prompt,
                'template_source_cmid' => $sourcecmid,
            ],
        ];
    }
}

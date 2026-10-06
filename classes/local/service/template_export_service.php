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

use local_coursegen\local\template\template_actions;
use local_coursegen\local\template\template_repository;

/**
 * Builds the init payload, contract version 2, for one saved template.
 *
 * The payload carries the course of the template and, for each of its activities, what the AI does
 * with it: nothing, or what the admin asked in the instruction. How every element is named stably across
 * requests lives in template_export_uids.php; how a section's own layout is described lives in
 * template_export_sections.php; how a saved choice is told to the service lives in template_export_behavior.php.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_export_service {
    /** @var int Version of the payload contract the AI service reads. */
    public const CONTRACT_VERSION = 2;

    /**
     * Build the full init payload.
     *
     * @param int $templateid
     * @param string $generalinstruction The professor's single prompt/syllabus instruction.
     * @return array
     */
    public static function build_init_payload(int $templateid, string $generalinstruction = ''): array {
        global $CFG;

        $repository = new template_repository();
        $template = $repository->find($templateid);
        if ($template === null) {
            throw new \moodle_exception('invalidtemplate', 'local_coursegen');
        }

        $course = get_course($template->courseid);
        $modinfo = get_fast_modinfo($course);
        $items = $repository->items_of($templateid);

        $lang = $course->lang;
        if (!$lang) {
            $lang = current_language();
        }

        $behaviors = self::section_behaviors($modinfo, $items);
        $sectionsinfo = template_export_sections::sections_info($course, $modinfo, $behaviors);
        $activities = self::activities($modinfo, $items);
        $formatoptions = template_export_sections::format_settings($course);
        $sectioninfos = $modinfo->get_section_info_all();
        $sectioncount = count($sectioninfos);

        $courseconfiguration = [
            'fullname' => $course->fullname,
            'shortname' => $course->shortname,
            'lang' => $lang,
            'numsections' => $sectioncount - 1,
            // How the course is laid out, and how its format is set up.
            // A course generated from a template is the template's course
            // with other content in it, so it is read the same way, and a
            // preview of it has to be drawn the same way.
            'format' => $course->format,
            'format_options' => $formatoptions,
            // What the new course takes from the template's course as it is.
            'enablecompletion' => (int) $course->enablecompletion,
            'course_settings' => template_course_settings::export($course),
        ];

        return [
            'contract_version' => self::CONTRACT_VERSION,
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
     * The visible activities of the course, each with what the AI does with it.
     *
     * @param \course_modinfo $modinfo
     * @param \stdClass[] $items Saved items keyed by course module id.
     * @return array
     */
    private static function activities(\course_modinfo $modinfo, array $items): array {
        $activities = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $activities[] = self::activity_entry($cm, $items[$cm->id] ?? null);
        }
        return $activities;
    }

    /**
     * One activity's own entry.
     *
     * @param \cm_info $cm
     * @param \stdClass|null $item
     * @return array
     */
    private static function activity_entry(\cm_info $cm, ?\stdClass $item): array {
        return [
            'resource_type' => $cm->modname,
            'modname' => $cm->modname,
            'name' => $cm->name,
            'section' => (int) $cm->sectionnum,
            'uid' => template_export_uids::random_uid(),
            'cmid' => (int) $cm->id,
            'parameters' => template_activity_export::parameters_for($cm),
            'template_behavior' => template_export_behavior::for_item($item),
        ];
    }

    /**
     * The behavior of every section: it is modified when any of its activities is, and kept as is otherwise.
     *
     * @param \course_modinfo $modinfo
     * @param \stdClass[] $items Saved items keyed by course module id.
     * @return array Section id => "aimodify" or "keep".
     */
    private static function section_behaviors(\course_modinfo $modinfo, array $items): array {
        $behaviors = self::kept_sections($modinfo);
        $modifiedsections = self::modified_section_ids($modinfo, $items);
        foreach ($modifiedsections as $sectionid) {
            $behaviors[$sectionid] = 'aimodify';
        }
        return $behaviors;
    }

    /**
     * Every section of the course, marked as kept.
     *
     * @param \course_modinfo $modinfo
     * @return array Section id => "keep".
     */
    private static function kept_sections(\course_modinfo $modinfo): array {
        $behaviors = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            $behaviors[(int) $section->id] = 'keep';
        }
        return $behaviors;
    }

    /**
     * The ids of the sections that hold an activity the AI modifies.
     *
     * @param \course_modinfo $modinfo
     * @param \stdClass[] $items Saved items keyed by course module id.
     * @return int[]
     */
    private static function modified_section_ids(\course_modinfo $modinfo, array $items): array {
        $sectionids = [];
        foreach ($modinfo->get_cms() as $cm) {
            $item = $items[$cm->id] ?? null;
            if ($item !== null && $item->action === template_actions::AI) {
                $sectionids[] = (int) $cm->section;
            }
        }
        return $sectionids;
    }
}

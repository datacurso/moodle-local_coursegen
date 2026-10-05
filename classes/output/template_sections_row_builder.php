<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_coursegen\output;

use local_coursegen\local\models\template_activity;
use local_coursegen\local\service\template_instance_layout;
use local_coursegen\local\space\space_rules;

/**
 * Build row contexts for the template course-structure review.
 *
 * @package local_coursegen
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_sections_row_builder {
    /**
     * Build real activity contexts in section order.
     *
     * @param \course_modinfo $modinfo
     * @param \section_info $sectioninfo
     * @param array $saved
     * @return array
     */
    public static function real_rows_of_section(
        \course_modinfo $modinfo,
        \section_info $sectioninfo,
        array $saved
    ): array {
        $rows = [];
        $cmids = $modinfo->sections[$sectioninfo->section] ?? [];
        foreach ($cmids as $cmid) {
            $cm = $modinfo->get_cm($cmid);
            if ($cm->deletioninprogress) {
                continue;
            }
            $realcmid = (int) $cm->id;
            $rows[$realcmid] = self::real_row_context($cm, $realcmid, $saved);
        }
        return $rows;
    }

    /**
     * Build one activity's render context.
     *
     * @param \cm_info $cm
     * @param int $cmid
     * @param array $saved
     * @return array
     */
    private static function real_row_context(\cm_info $cm, int $cmid, array $saved): array {
        $savedaction = $saved['actions'][$cmid] ?? null;
        $actionoptions = template_row_options::activity_actions($cmid, $cm->modname, $savedaction);
        $activeaction = template_row_options::active_action($actionoptions);
        $savedscope = $saved['scopes'][$cmid] ?? template_activity::SCOPE_COURSE;
        $scopeoptions = template_row_options::template_scope_options($cmid, $savedscope);
        $scopelabel = template_row_options::active_scope_label($scopeoptions);
        $space = $saved['spaces'][$cmid] ?? ['required' => true, 'instruction' => ''];
        $isspace = $activeaction === template_activity::ACTION_SPACE;
        $legacyspace = $savedaction === template_activity::ACTION_SPACE && !space_rules::allows($cm->modname);
        $spacebadge = template_row_options::space_badge_label($space['required']);

        $viewurl = null;
        if ($cm->url) {
            $viewurl = $cm->url->out(false);
        }
        $icon = $cm->get_icon_url();
        $iconurl = $icon->out(false);
        $name = $cm->get_formatted_name();
        $typelabel = $cm->get_module_type_name();

        return [
            'isreal' => true,
            'isinstance' => false,
            'cmid' => $cmid,
            'name' => $name,
            'viewurl' => $viewurl,
            'modname' => $cm->modname,
            'typelabel' => $typelabel,
            'iconurl' => $iconurl,
            'actions' => $actionoptions,
            'prompt' => $saved['prompts'][$cmid] ?? '',
            'istemplate' => $activeaction === template_activity::ACTION_TEMPLATE,
            'scopelabel' => $scopelabel,
            'isspace' => $isspace,
            'spacerequired' => $space['required'],
            'spacerequiredvalue' => (int) $space['required'],
            'spaceinstruction' => $space['instruction'],
            'hasspaceinstruction' => $isspace && $space['instruction'] !== '',
            'spacebadge' => $spacebadge,
            'legacyspace' => $legacyspace,
        ];
    }

    /**
     * Add final-row markers and render contexts for real and virtual rows.
     *
     * @param array $orderedrows
     * @param array $activitiesbycmid
     * @return array
     */
    public static function render_rows(array $orderedrows, array $activitiesbycmid): array {
        $rows = [];
        $lastindex = count($orderedrows) - 1;
        foreach ($orderedrows as $index => $entry) {
            if ($entry['type'] === template_instance_layout::TYPE_REAL) {
                $context = $activitiesbycmid[$entry['cmid']];
            } else if ($entry['type'] === template_instance_layout::TYPE_SPACE) {
                $context = ['isreal' => false, 'isinstance' => false, 'isvirtualspace' => true]
                    + template_virtual_row_options::space_row_context($entry['record']);
            } else {
                $context = ['isreal' => false, 'isinstance' => true]
                    + template_virtual_row_options::instance_row_context($entry['record']);
            }
            $context['islast'] = ($index === $lastindex);
            $rows[] = $context;
        }
        return $rows;
    }
}

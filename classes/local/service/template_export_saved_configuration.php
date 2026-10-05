<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_coursegen\local\service;

use local_coursegen\local\models\template_activity;
use local_coursegen\local\models\template_section;

/**
 * Read the saved per-activity and per-section generation settings.
 *
 * @package local_coursegen
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_export_saved_configuration {
    /**
     * Return settings indexed by course module and section ID.
     *
     * @param int $templateid
     * @return array
     */
    public static function load(int $templateid): array {
        $activities = [];
        foreach (template_activity::get_records(['templateid' => $templateid]) as $activity) {
            $cmid = (int) $activity->get('cmid');
            $activities[$cmid] = [
                'action' => (string) $activity->get('action'),
                'prompt' => (string) $activity->get('prompt'),
            ];
        }

        $behaviors = [];
        foreach (template_section::get_records(['templateid' => $templateid]) as $section) {
            $sectionid = (int) $section->get('sectionid');
            $behaviors[$sectionid] = $section->get('behavior');
        }

        return ['activities' => $activities, 'behaviors' => $behaviors];
    }
}

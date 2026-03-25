<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursegen\external;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;
use local_coursegen\local\image_generation\activities;

/**
 * External function to manage image generation settings.
 *
 * @package    local_coursegen
 * @category   external
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manage_image_generation extends external_api {

    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'overridecourse'   => new external_value(PARAM_INT, 'Allow course override'),
            'overrideactivity' => new external_value(PARAM_INT, 'Allow activity override'),
            'generationmode'   => new external_value(PARAM_ALPHANUM, 'Mode: auto, manual, disabled'),
            'activities'       => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_ALPHANUMEXT, 'Activity identifier'),
                    'enabled' => new external_value(PARAM_INT, 'Whether the activity type is enabled'),
                    'prompt' => new external_value(PARAM_RAW, 'Prompt used for this activity type', VALUE_DEFAULT, ''),
                    'parts' => new external_multiple_structure(
                        new external_single_structure([
                            'id' => new external_value(PARAM_ALPHANUMEXT, 'Activity part identifier'),
                            'enabled' => new external_value(PARAM_INT, 'Whether the activity part is enabled'),
                            'maximages' => new external_value(PARAM_INT, 'Maximum images to generate for this part', VALUE_DEFAULT, 0),
                        ]),
                        'Optional per-activity parts configuration',
                        VALUE_DEFAULT,
                        []
                    ),
                ]),
                'Activity settings list'
            ),
        ]);
    }

    /**
     * Save the image generation settings.
     *
     * @param int $overridecourse Allow course override.
     * @param int $overrideactivity Allow activity override.
     * @param string $generationmode Mode: auto, manual, disabled.
     * @param array $activities Activity settings list.
     * @return array
     */
    public static function execute(
        int $overridecourse,
        int $overrideactivity,
        string $generationmode,
        array $activities
    ): array {

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        set_config('overridecourse', $overridecourse, 'local_coursegen');
        set_config('overrideactivity', $overrideactivity, 'local_coursegen');
        set_config('generationmode', $generationmode, 'local_coursegen');

        $submittedbyid = [];
        foreach ($activities as $activity) {
            if (!is_array($activity) || empty($activity['id'])) {
                continue;
            }
            $id = (string) $activity['id'];
            $submittedbyid[$id] = $activity;
        }

        foreach (activities::get_definitions() as $definition) {
            $id = $definition['id'];
            if (!array_key_exists($id, $submittedbyid)) {
                continue;
            }

            $configenable = $definition['configenable'];

            $enabled = !empty($submittedbyid[$id]['enabled']) ? 1 : 0;

            set_config($configenable, $enabled, 'local_coursegen');

            $definitionparts = $definition['parts'] ?? [];
            if (!empty($definitionparts) && is_array($definitionparts)) {
                $submittedparts = $submittedbyid[$id]['parts'] ?? [];
                $submittedpartsbyid = [];
                foreach ($submittedparts as $submittedpart) {
                    if (!is_array($submittedpart) || empty($submittedpart['id'])) {
                        continue;
                    }
                    $submittedpartsbyid[(string) $submittedpart['id']] = $submittedpart;
                }

                foreach ($definitionparts as $partdefinition) {
                    $partid = $partdefinition['id'];
                    $partconfigenable = $partdefinition['configenable'];
                    $partconfigmaximages = $partdefinition['configmaximages'] ?? null;
                    $partenabled = 0;
                    $maximages = 0;
                    if (array_key_exists($partid, $submittedpartsbyid)) {
                        $partenabled = !empty($submittedpartsbyid[$partid]['enabled']) ? 1 : 0;
                        $submittedmax = isset($submittedpartsbyid[$partid]['maximages'])
                            ? (int) $submittedpartsbyid[$partid]['maximages'] : 0;
                        if ($submittedmax > 0) {
                            $maximages = min($submittedmax, 5);
                        }
                    }
                    set_config($partconfigenable, $partenabled, 'local_coursegen');
                    if ($partconfigmaximages !== null) {
                        set_config($partconfigmaximages, $maximages, 'local_coursegen');
                    }
                }
            }
        }

        return ['success' => true];
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'True if saved successfully'),
        ]);
    }
}

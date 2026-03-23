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
use external_value;

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
            'enableimgbook'    => new external_value(PARAM_INT, 'Enable for books'),
            'promptimgbook'    => new external_value(PARAM_RAW, 'Book prompt', VALUE_DEFAULT, ''),
            'enableimgquiz'    => new external_value(PARAM_INT, 'Enable for quizzes'),
            'promptimgquiz'    => new external_value(PARAM_RAW, 'Quiz prompt', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Save the image generation settings.
     *
     * @param int $overridecourse Allow course override.
     * @param int $overrideactivity Allow activity override.
     * @param string $generationmode Mode: auto, manual, disabled.
     * @param int $enableimgbook Enable for books.
     * @param string $promptimgbook Book prompt.
     * @param int $enableimgquiz Enable for quizzes.
     * @param string $promptimgquiz Quiz prompt.
     * @return array
     */
    public static function execute(
        int $overridecourse,
        int $overrideactivity,
        string $generationmode,
        int $enableimgbook,
        string $promptimgbook,
        int $enableimgquiz,
        string $promptimgquiz
    ): array {

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        set_config('overridecourse', $overridecourse, 'local_coursegen');
        set_config('overrideactivity', $overrideactivity, 'local_coursegen');
        set_config('generationmode', $generationmode, 'local_coursegen');
        set_config('enableimgbook', $enableimgbook, 'local_coursegen');
        set_config('promptimgbook', $promptimgbook, 'local_coursegen');
        set_config('enableimgquiz', $enableimgquiz, 'local_coursegen');
        set_config('promptimgquiz', $promptimgquiz, 'local_coursegen');

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

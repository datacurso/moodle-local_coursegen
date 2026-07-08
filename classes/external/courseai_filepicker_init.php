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

/**
 * External API to initialise a filepicker (draft area) for courseai syllabus upload.
 *
 * @package    local_coursegen
 * @category   external
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\external;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/repository/lib.php');

/**
 * External API to initialise a filepicker (draft area) for courseai syllabus upload.
 */
class courseai_filepicker_init extends external_api {
    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Initialise and return filepicker options.
     *
     * @return array
     */
    public static function execute(): array {
        global $PAGE;

        $params = self::validate_parameters(self::execute_parameters(), []);

        $context = context_system::instance();
        self::validate_context($context);

        // Check permissions.
        require_capability('moodle/course:create', $context);
        require_capability('local/coursegen:createcoursewithai', $context);

        $draftitemid = file_get_unused_draft_itemid();
        $clientid = uniqid('local_coursegen_courseai_syllabus_');

        $args = (object) [
            'context' => $context,
            'accepted_types' => ['.pdf', '.docx', '.txt'],
            'return_types' => FILE_INTERNAL,
            'env' => 'filepicker',
            'client_id' => $clientid,
            'itemid' => $draftitemid,
            'maxbytes' => 10 * 1024 * 1024, // 10MB
            'maxfiles' => 1,
            'subdirs' => 0,
        ];

        $options = initialise_filepicker($args);

        // Ensure the filepicker templates are available for JS.
        $PAGE->set_context($context);
        $fprenderer = $PAGE->get_renderer('core', 'files');
        $templates = method_exists($fprenderer, 'filepicker_js_templates') ? $fprenderer->filepicker_js_templates() : [];

        return [
            'clientid' => $clientid,
            'draftitemid' => $draftitemid,
            'options' => json_encode($options),
            'templates' => json_encode($templates),
        ];
    }

    /**
     * Returns description of method return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'clientid' => new external_value(PARAM_RAW, 'Filepicker client id'),
            'draftitemid' => new external_value(PARAM_INT, 'Draft item id to store the file'),
            'options' => new external_value(PARAM_RAW, 'JSON encoded filepicker options'),
            'templates' => new external_value(PARAM_RAW, 'JSON encoded filepicker templates'),
        ]);
    }
}

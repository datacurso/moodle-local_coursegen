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

namespace local_coursegen\external;

use aiprovider_datacurso\local\plugin_settings;
use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursegen\local\models\template;
use local_coursegen\local\service\course_session_service;
use local_coursegen\local\service\template_image_addresses;

/**
 * The addresses of the pictures of a template run: the template's picture each one starts from, and the new one.
 *
 * The page of the run asks for a picture's addresses once the picture is made, to draw its thumbnails. A picture
 * whose address cannot be found or made gets an empty address, so one picture never stops the list. Read-only for
 * the course: it only downloads the new pictures of the person's own session into the site's file storage.
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_template_image_urls extends external_api {
    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'recordid' => new external_value(PARAM_INT, 'ID from local_coursegen_course_sessions'),
            'items' => new external_multiple_structure(new external_single_structure([
                'key' => new external_value(PARAM_RAW, 'The key of the picture in the events of the run'),
                'kind' => new external_value(PARAM_ALPHANUMEXT, 'The kind of the picture'),
                'reference' => new external_value(PARAM_FILE, 'The name of the template picture it starts from', VALUE_DEFAULT, ''),
                'thread_id' => new external_value(PARAM_ALPHANUMEXT, 'The thread of a stored file', VALUE_DEFAULT, ''),
                'file_id' => new external_value(PARAM_FILE, 'The id of a stored file', VALUE_DEFAULT, ''),
                'filename' => new external_value(PARAM_FILE, 'The name of a stored file', VALUE_DEFAULT, ''),
                'path' => new external_value(PARAM_PATH, 'The path of a picture saved inside an activity', VALUE_DEFAULT, ''),
            ])),
        ]);
    }

    /**
     * The addresses of some pictures of a session owned by the current user.
     *
     * @param int $recordid Session record ID.
     * @param array $items The pictures.
     * @return array
     */
    public static function execute(int $recordid, array $items): array {
        global $USER;

        plugin_settings::require_enabled('local_coursegen');
        $params = self::validate_parameters(self::execute_parameters(), ['recordid' => $recordid, 'items' => $items]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/coursegen:createtemplatecoursewithai', $context);

        $session = course_session_service::get_user_session((int) $params['recordid'], (int) $USER->id);
        $template = template::get_record(['id' => (int) $session->get('templateid')]);
        if (!$template) {
            throw new \moodle_exception('error_no_session_found', 'local_coursegen');
        }
        $addresses = new template_image_addresses((int) $template->get('courseid'), (string) $session->get('session_id'));
        return self::addresses_of($addresses, $params['items']);
    }

    /**
     * The addresses of each picture; one that cannot be found or made gets empty addresses.
     *
     * @param template_image_addresses $addresses
     * @param array $items
     * @return array
     */
    private static function addresses_of(template_image_addresses $addresses, array $items): array {
        $result = [];
        foreach ($items as $item) {
            $result[] = self::one($addresses, $item);
        }
        return $result;
    }

    /**
     * The addresses of one picture.
     *
     * @param template_image_addresses $addresses
     * @param array $item
     * @return array
     */
    private static function one(template_image_addresses $addresses, array $item): array {
        $original = '';
        $current = '';
        try {
            $original = $addresses->original($item);
            $current = $addresses->current($item);
        } catch (\moodle_exception $exception) {
            debugging('local_coursegen: picture address not available: ' . $exception->getMessage(), DEBUG_DEVELOPER);
        }
        return ['key' => (string) $item['key'], 'original' => $original, 'current' => $current];
    }

    /**
     * Return definition.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'key' => new external_value(PARAM_RAW, 'The key of the picture'),
            'original' => new external_value(PARAM_URL, 'The address of the template picture, or empty'),
            'current' => new external_value(PARAM_URL, 'The address of the new picture, or empty'),
        ]));
    }
}

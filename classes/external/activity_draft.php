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

use context_course;
use core\session\manager;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_coursegen\local\draft_files;
use local_coursegen\local\models\activity_draft as draft_model;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Save, resume and discard the unfinished work of the AI activity editor.
 *
 * The draft is always the one of the user that makes the call: no function receives a user, every query filters by the
 * logged in user, and every call checks that the user can create activities with AI in the course.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_draft extends external_api {
    /**
     * Describes the parameters of resume().
     *
     * @return external_function_parameters
     */
    public static function resume_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'The course'),
            'kind' => new external_value(PARAM_ALPHANUMEXT, 'The kind of activity being edited'),
        ]);
    }

    /**
     * The draft of the user, when there is one.
     *
     * @param int $courseid
     * @param string $kind
     * @return array
     */
    public static function resume(int $courseid, string $kind): array {
        global $USER;

        $params = self::validate_parameters(self::resume_parameters(), ['courseid' => $courseid, 'kind' => $kind]);
        self::check_access($params['courseid']);

        $draft = draft_model::find_for_user($USER->id, $params['courseid'], $params['kind']);
        if (!$draft || (int) $draft->get('schemaver') !== draft_model::SCHEMA_VERSION) {
            return ['exists' => false, 'data' => '', 'timemodified' => 0];
        }
        return [
            'exists' => true,
            'data' => (string) $draft->get('data'),
            'timemodified' => (int) $draft->get('timemodified'),
        ];
    }

    /**
     * Describes the return of resume().
     *
     * @return external_single_structure
     */
    public static function resume_returns(): external_single_structure {
        return new external_single_structure([
            'exists' => new external_value(PARAM_BOOL, 'Whether the user has a draft'),
            'data' => new external_value(PARAM_RAW, 'The state of the editor as JSON'),
            'timemodified' => new external_value(PARAM_INT, 'When the draft changed last'),
        ]);
    }

    /**
     * Describes the parameters of update().
     *
     * @return external_function_parameters
     */
    public static function update_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'The course'),
            'kind' => new external_value(PARAM_ALPHANUMEXT, 'The kind of activity being edited'),
            'data' => new external_value(PARAM_RAW, 'The state of the editor as JSON'),
        ]);
    }

    /**
     * Save the draft of the user. Nothing is written when the data is the same as the saved one, and a draft that is not valid
     * or is too big is not saved.
     *
     * @param int $courseid
     * @param string $kind
     * @param string $data
     * @return array
     */
    public static function update(int $courseid, string $kind, string $data): array {
        global $USER;

        $params = self::validate_parameters(self::update_parameters(), [
            'courseid' => $courseid, 'kind' => $kind, 'data' => $data,
        ]);
        self::check_access($params['courseid']);

        // While someone else is being impersonated, the work is not saved in the name of that person.
        if (manager::is_loggedinas()) {
            return ['saved' => false];
        }

        $hash = sha1($params['data']);
        $draft = draft_model::find_for_user($USER->id, $params['courseid'], $params['kind']);
        if ($draft && $draft->get('contenthash') === $hash && (int) $draft->get('schemaver') === draft_model::SCHEMA_VERSION) {
            return ['saved' => false];
        }

        $values = [
            'data' => $params['data'],
            'contenthash' => $hash,
            'schemaver' => draft_model::SCHEMA_VERSION,
        ];
        try {
            if ($draft) {
                $draft->from_record((object) $values);
                $draft->update();
            } else {
                $values += ['userid' => $USER->id, 'courseid' => $params['courseid'], 'kind' => $params['kind']];
                (new draft_model(0, (object) $values))->create();
            }
        } catch (\core\invalid_persistent_exception $e) {
            // A draft that is not valid or is too big is not saved; the person keeps editing.
            return ['saved' => false];
        } catch (\dml_exception $e) {
            // Two saves of the same draft at once: the other one won, and the next save will go through.
            return ['saved' => false];
        }
        // The files the draft no longer refers to go away, unless they are so new that the draft may not mention them yet.
        draft_files::prune($USER->id, $params['courseid'], $params['kind'], self::file_keys($params['data']), time() - 600);
        return ['saved' => true];
    }

    /**
     * Describes the return of update().
     *
     * @return external_single_structure
     */
    public static function update_returns(): external_single_structure {
        return new external_single_structure([
            'saved' => new external_value(PARAM_BOOL, 'Whether something was written'),
        ]);
    }

    /**
     * Describes the parameters of reset().
     *
     * @return external_function_parameters
     */
    public static function reset_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'The course'),
            'kind' => new external_value(PARAM_ALPHANUMEXT, 'The kind of activity being edited'),
            'keepfiles' => new external_value(PARAM_BOOL, 'Keep the files, the activity may still need them', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Discard the draft of the user, and its files unless they are asked to stay.
     *
     * @param int $courseid
     * @param string $kind
     * @param bool $keepfiles
     * @return array
     */
    public static function reset(int $courseid, string $kind, bool $keepfiles = false): array {
        global $USER;

        $params = self::validate_parameters(self::reset_parameters(), [
            'courseid' => $courseid, 'kind' => $kind, 'keepfiles' => $keepfiles,
        ]);
        self::check_access($params['courseid']);

        $draft = draft_model::get_record(['userid' => $USER->id, 'courseid' => $params['courseid'], 'kind' => $params['kind']]);
        if ($draft) {
            $draft->delete();
        }
        if (!$params['keepfiles']) {
            draft_files::delete_all($USER->id, $params['courseid'], $params['kind']);
        }
        return [];
    }

    /**
     * Describes the return of reset().
     *
     * @return external_single_structure
     */
    public static function reset_returns(): external_single_structure {
        return new external_single_structure([]);
    }

    /**
     * Describes the parameters of file_save().
     *
     * @return external_function_parameters
     */
    public static function file_save_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'The course'),
            'kind' => new external_value(PARAM_ALPHANUMEXT, 'The kind of activity being edited'),
            'key' => new external_value(PARAM_RAW, 'The name the draft will use for the file'),
            'draftitemid' => new external_value(PARAM_INT, 'The draft area of the file picker that has the file'),
            'role' => new external_value(PARAM_ALPHA, 'image or reference'),
        ]);
    }

    /**
     * Keep a file the person added, in Moodle, until the activity is created. Nothing is sent to the AI service.
     *
     * @param int $courseid
     * @param string $kind
     * @param string $key
     * @param int $draftitemid
     * @param string $role
     * @return array
     */
    public static function file_save(int $courseid, string $kind, string $key, int $draftitemid, string $role): array {
        global $USER;

        $params = self::validate_parameters(self::file_save_parameters(), [
            'courseid' => $courseid, 'kind' => $kind, 'key' => $key, 'draftitemid' => $draftitemid, 'role' => $role,
        ]);
        self::check_access($params['courseid']);
        if (manager::is_loggedinas()) {
            throw new \moodle_exception('error_draft_notavailable', 'local_coursegen');
        }

        draft_files::save($USER->id, $params['courseid'], $params['kind'], $params['key'], $params['draftitemid'], $params['role']);
        return ['saved' => true];
    }

    /**
     * Describes the return of file_save().
     *
     * @return external_single_structure
     */
    public static function file_save_returns(): external_single_structure {
        return new external_single_structure([
            'saved' => new external_value(PARAM_BOOL, 'Whether the file was kept'),
        ]);
    }

    /**
     * The keys of the files a draft refers to: its reference file and the picture of each element.
     *
     * @param string $data The draft as JSON.
     * @return string[]
     */
    private static function file_keys(string $data): array {
        $draft = json_decode($data, true);
        $keys = [];
        if (is_array($draft['reference'] ?? null) && is_string($draft['reference']['key'] ?? null)) {
            $keys[] = $draft['reference']['key'];
        }
        foreach ((array) ($draft['model']['slides'] ?? []) as $slide) {
            foreach ((array) ($slide['blocks'] ?? []) as $block) {
                if (is_array($block) && is_string($block['fileKey'] ?? null)) {
                    $keys[] = $block['fileKey'];
                }
            }
        }
        return $keys;
    }

    /**
     * The user can create activities with AI in the course, as the rest of the generation asks.
     *
     * @param int $courseid
     */
    private static function check_access(int $courseid): void {
        $context = context_course::instance($courseid);
        self::validate_context($context);
        require_capability('moodle/course:manageactivities', $context);
        require_capability('local/coursegen:createactivitywithai', $context);
    }
}

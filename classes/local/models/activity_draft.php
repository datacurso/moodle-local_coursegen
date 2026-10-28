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

namespace local_coursegen\local\models;

use core\persistent;

/**
 * The unfinished work of the AI activity editor of one user in one course.
 *
 * There is one draft per user, course and kind of activity. The draft belongs to the user that saved it: nothing reads it
 * or changes it without filtering by that user.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_draft extends persistent {
    /** The table. */
    const TABLE = 'local_coursegen_activity_draft';

    /** The most the data of a draft can weigh, in bytes. A bigger draft is not saved. */
    const MAX_BYTES = 262144;

    /** How long a draft is kept after its last change, in seconds. */
    const LIFETIME = 30 * DAYSECS;

    /** The version of the editor model the data is saved with. A draft of another version is not used. */
    const SCHEMA_VERSION = 1;

    /**
     * The properties of a draft.
     *
     * @return array
     */
    protected static function define_properties(): array {
        return [
            'userid' => ['type' => PARAM_INT],
            'courseid' => ['type' => PARAM_INT],
            'kind' => ['type' => PARAM_ALPHANUMEXT],
            'schemaver' => ['type' => PARAM_INT, 'default' => self::SCHEMA_VERSION],
            'contenthash' => ['type' => PARAM_ALPHANUMEXT],
            'data' => ['type' => PARAM_RAW],
        ];
    }

    /**
     * The data is JSON and is not bigger than the limit.
     *
     * @param string $value
     * @return true|\lang_string
     */
    protected function validate_data($value) {
        if (strlen($value) > self::MAX_BYTES) {
            return new \lang_string('error_draft_toolarge', 'local_coursegen');
        }
        if (!is_array(json_decode($value, true))) {
            return new \lang_string('error_draft_invalid', 'local_coursegen');
        }
        return true;
    }

    /**
     * The draft of a user, or null when there is none or it is too old.
     *
     * An old draft is deleted as it is found.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $kind
     * @return self|null
     */
    public static function find_for_user(int $userid, int $courseid, string $kind): ?self {
        $draft = self::get_record(['userid' => $userid, 'courseid' => $courseid, 'kind' => $kind]);
        if (!$draft) {
            return null;
        }
        if ((int) $draft->get('timemodified') < time() - self::LIFETIME) {
            $draft->delete();
            return null;
        }
        return $draft;
    }
}

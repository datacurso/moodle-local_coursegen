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
 * Persistent model for table local_coursegen_course_sessions.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_session extends persistent {
    /** Table name for the persistent. */
    const TABLE = 'local_coursegen_course_sessions';

    /** Status: pending / initial state. */
    const STATUS_PENDING = 1;

    /** Status: course is being created. */
    const STATUS_CREATING = 2;

    /** Status: course created successfully. */
    const STATUS_CREATED = 3;

    /** Status: creation failed. */
    const STATUS_FAILED = 4;

    /**
     * Return the definition of the properties of this model.
     *
     * @return array
     */
    protected static function define_properties(): array {
        return [
            'courseid' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'userid' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
            ],
            'session_id' => [
                'type' => PARAM_TEXT,
                'null' => NULL_NOT_ALLOWED,
            ],
            'status' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
                'choices' => [
                    self::STATUS_PENDING,
                    self::STATUS_CREATING,
                    self::STATUS_CREATED,
                    self::STATUS_FAILED,
                ],
                'default' => self::STATUS_PENDING,
            ],
            'coursedata' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'timecreated' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
            ],
            'timemodified' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
            ],
        ];
    }
}

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

/**
 * Service helper to retrieve AI course context information using the persistent model.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_context_service {
    /**
     * Get AI course context info from database for the given course.
     *
     * This mirrors the information previously obtained via ai_context::get_course_context_info
     * but using the persistent model/service layer.
     *
     * @param int $courseid Course ID.
     * @return \stdClass|null Object with context_type, lang and name (system instruction name) when available.
     */
    public static function get_course_context(int $courseid): ?\stdClass {
        global $DB;

        $sql = 'SELECT cc.context_type, cc.prompt_text, cc.lang, si.name AS system_instruction_name
                  FROM {local_coursegen_course_context} cc
             LEFT JOIN {local_coursegen_system_instruction} si ON cc.system_instruction_id = si.id
                 WHERE cc.courseid = :courseid';

        $params = ['courseid' => $courseid];

        $record = $DB->get_record_sql($sql, $params);
        if (!$record) {
            return null;
        }

        return $record;
    }
}

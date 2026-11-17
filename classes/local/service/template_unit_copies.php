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

use local_coursegen\local\models\course_session;

/**
 * The sections of a run whose template section was repeated once per unit of the syllabus.
 *
 * The AI service writes the copies after reading the syllabus, so the sections it answers are not the ones the
 * plugin sent: the template section is there once per unit and every section after it moved down. Each entry
 * says which section of the template it comes from (source_section) and a copy says it is one (cloned), which is
 * what a resized template already says. The answered sections take the place of the sent ones in the session,
 * so the build places every section, every kept activity and every copy where it belongs.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_unit_copies {
    /**
     * Whether the answered sections hold copies of a template section.
     *
     * @param array $sectionsinfo The result's sections_info.
     * @return bool
     */
    public static function has_copies(array $sectionsinfo): bool {
        foreach ($sectionsinfo as $entry) {
            if (!empty($entry['cloned'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Keep the answered sections as the session's own when they hold copies; leave the session alone otherwise.
     *
     * @param course_session $session
     * @param array $sectionsinfo The result's sections_info.
     * @return bool Whether the session took them.
     */
    public static function adopt(course_session $session, array $sectionsinfo): bool {
        if (!self::has_copies($sectionsinfo)) {
            return false;
        }
        $coursedata = json_decode((string) $session->get('coursedata'), true) ?: [];
        $coursedata['payload']['sections_info'] = array_values($sectionsinfo);
        $session->set('coursedata', json_encode($coursedata, JSON_UNESCAPED_UNICODE));
        $session->update();
        return true;
    }
}

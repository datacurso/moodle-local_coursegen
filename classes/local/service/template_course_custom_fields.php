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

use core_course\customfield\course_handler;
use core_customfield\api;
use core_customfield\data_controller;

/**
 * The values of the custom fields of a template's course, in the course made from it.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_course_custom_fields {
    /**
     * Give the new course the values the template's course has for its custom fields.
     *
     * A field the template's course has no value for stays as the site defines it.
     *
     * @param \stdClass $course The new course.
     * @param int $sourcecourseid The template's course.
     */
    public static function copy(\stdClass $course, int $sourcecourseid): void {
        $fields = course_handler::create()->get_fields();
        $contextid = \context_course::instance($course->id)->id;
        $sourcecontextid = \context_course::instance($sourcecourseid)->id;

        $sourcedatas = api::get_instance_fields_data($fields, $sourcecourseid, false);
        foreach ($sourcedatas as $sourcedata) {
            $record = $sourcedata->to_record();
            $sourcedataid = $record->id;
            unset($record->id);
            $record->instanceid = $course->id;
            $record->contextid = $contextid;

            $data = data_controller::create(0, $record, $sourcedata->get_field());
            $data->save();

            template_area_files_copier::copy(
                $sourcecontextid,
                $contextid,
                'customfield_' . $sourcedata->get_field()->get('type'),
                'value',
                $sourcedataid,
                $data->get('id')
            );
        }
    }
}

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
 * External functions and service declaration for DataCurso
 *
 * Documentation: {@link https://moodledev.io/docs/apis/subsystems/external/description}
 *
 * @package    local_coursegen
 * @category   webservice
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_coursegen_create_mod' => [
        'classname' => 'local_coursegen\external\create_mod',
        'methodname' => 'execute',
        'description' => 'Apply AI-generated module result to a course',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/course:manageactivities,moodle/course:update,local/coursegen:createactivitywithai',
    ],
    'local_coursegen_create_mod_stream' => [
        'classname' => 'local_coursegen\external\create_mod_stream',
        'methodname' => 'execute',
        'description' => 'Start streaming job to create module with AI',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/course:manageactivities,moodle/course:update,local/coursegen:createactivitywithai',
    ],
    'local_coursegen_create_course' => [
        'classname' => 'local_coursegen\external\create_course',
        'methodname' => 'execute',
        'description' => 'Apply AI-generated content to a course',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/course:manageactivities,local/coursegen:createcoursewithai',
    ],
    'local_coursegen_plan_course_message' => [
        'classname' => 'local_coursegen\external\plan_course_message',
        'methodname' => 'execute',
        'description' => 'Send message to AI course planning session',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/course:update,local/coursegen:createcoursewithai',
    ],
    'local_coursegen_plan_course_execute' => [
        'classname' => 'local_coursegen\external\plan_course_execute',
        'methodname' => 'execute',
        'description' => 'Execute AI course planning session',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/course:update,local/coursegen:createcoursewithai',
    ],
];

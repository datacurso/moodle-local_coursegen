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
        'description' => 'Create module for ask question to chatbot based in that information',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/course:manageactivities,moodle/course:update',
    ],
    'local_coursegen_create_mod_stream' => [
        'classname' => 'local_coursegen\external\create_mod_stream',
        'methodname' => 'execute',
        'description' => 'Start streaming job to create module with AI and store job_id',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/course:manageactivities,moodle/course:update',
    ],
    'local_coursegen_create_course' => [
        'classname' => 'local_coursegen\external\create_course',
        'methodname' => 'execute',
        'description' => 'Create course with AI assistance',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/course:create',
    ],
    'local_coursegen_course_planning_feedback' => [
        'classname' => 'local_coursegen\external\course_planning_feedback',
        'methodname' => 'execute',
        'description' => 'Send human feedback for AI course planning session',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_coursegen_regenerate_detailed_item' => [
        'classname' => 'local_coursegen\external\regenerate_detailed_item',
        'methodname' => 'execute',
        'description' => 'Regenerate a single section, activity, or image in the detailed plan',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_coursegen_activity_feedback' => [
        'classname' => 'local_coursegen\\external\\activity_feedback',
        'methodname' => 'execute',
        'description' => 'Send human feedback for AI activity generation job',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_coursegen_activity_filepicker_init' => [
        'classname' => 'local_coursegen\\external\\activity_filepicker_init',
        'methodname' => 'execute',
        'description' => 'Initialise filepicker draft area for AI activity file uploads',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'moodle/course:manageactivities,moodle/course:update',
        'loginrequired' => true,
    ],
    'local_coursegen_activity_file_upload' => [
        'classname' => 'local_coursegen\\external\\activity_file_upload',
        'methodname' => 'execute',
        'description' => 'Upload a file for an AI activity generation thread',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/course:manageactivities,moodle/course:update',
        'loginrequired' => true,
    ],
    'local_coursegen_validate_course_form' => [
        'classname' => 'local_coursegen\\external\\validate_course_form',
        'methodname' => 'execute',
        'description' => 'Validate AI-related course form fields for coursegen',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_coursegen_process_course_form' => [
        'classname' => 'local_coursegen\\external\\process_course_form',
        'methodname' => 'execute',
        'description' => 'Store full course edit form payload for AI processing',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_coursegen_manage_image_generation' => [
        'classname' => 'local_coursegen\\external\\manage_image_generation',
        'methodname' => 'execute',
        'description' => 'Save image generation settings for course and activity creation',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/site:config',
        'loginrequired' => true,
    ],
    'local_coursegen_start_course_planning' => [
        'classname' => 'local_coursegen\\external\\start_course_planning',
        'methodname' => 'execute',
        'description' => 'Start AI course planning session',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/course:create,local/coursegen:createcoursewithai',
        'loginrequired' => true,
    ],
    'local_coursegen_courseai_syllabus_upload' => [
        'classname' => 'local_coursegen\\external\\courseai_syllabus_upload',
        'methodname' => 'execute',
        'description' => 'Upload syllabus file for courseai session',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/course:create,local/coursegen:createcoursewithai',
        'loginrequired' => true,
    ],
    'local_coursegen_courseai_filepicker_init' => [
        'classname' => 'local_coursegen\\external\\courseai_filepicker_init',
        'methodname' => 'execute',
        'description' => 'Initialise filepicker draft area for courseai syllabus upload',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'moodle/course:create,local/coursegen:createcoursewithai',
        'loginrequired' => true,
    ],
];

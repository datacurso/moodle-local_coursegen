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
 * Callback implementations for DataCurso
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_coursegen\local\models\course_context;
use local_coursegen\local\service\course_session_service;

/**
 * Serve the files from the local_coursegen file areas.
 *
 * Syllabus files are stored in the SYSTEM context with the planning session
 * id as item id (see courseai_syllabus_upload), so they are served from the
 * system context and gated on session ownership or the view_syllabus
 * capability.
 *
 * @param stdClass $course the course object
 * @param stdClass $cm the course module object
 * @param stdClass $context the context
 * @param string $filearea the name of the file area
 * @param array $args extra arguments (itemid, path)
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 */
function local_coursegen_pluginfile(
    $course,
    $cm,
    $context,
    string $filearea,
    array $args,
    bool $forcedownload,
    array $options = []
) {
    global $USER;

    // Make sure the user is logged.
    require_login(null, false);

    // Syllabus files live in the system context only.
    if ($context->contextlevel != CONTEXT_SYSTEM) {
        return false;
    }

    // Make sure the filearea is the expected one.
    if ($filearea !== course_context::CONTEXT_TYPE_SYLLABUS) {
        return false;
    }

    // Args is an array containing [itemid, path].
    // Fetch the itemid from the path: it is the planning session id.
    $itemid = array_shift($args);

    // Only the session owner or users allowed to view syllabus files may access it.
    if (!course_session_service::can_view_syllabus((int)$itemid, (int)$USER->id)) {
        return false;
    }

    // Extract the filename / filepath from the $args array.
    $filename = array_pop($args); // The last item in the $args array.
    if (empty($args)) {
        // Args is empty => the path is '/'.
        $filepath = '/';
    } else {
        // Args contains the remaining elements of the filepath.
        $filepath = '/' . implode('/', $args) . '/';
    }

    // Retrieve the file from the Files API.
    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_coursegen', $filearea, $itemid, $filepath, $filename);
    if (!$file) {
        // The file does not exist.
        return false;
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}

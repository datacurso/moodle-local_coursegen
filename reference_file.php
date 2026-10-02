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
 * Takes or empties the file a teacher brings for one place of a template.
 *
 * It answers an upload from the template screen, which carries a real file
 * and so cannot go through a web service call.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

use local_coursegen\local\reference\reference_file_upload;

require_login(null, false);
require_sesskey();
$context = context_system::instance();
require_capability('local/coursegen:createtemplatecoursewithai', $context);

$templateid = required_param('templateid', PARAM_INT);
$slotkey = required_param('slotkey', PARAM_TEXT);
$action = required_param('action', PARAM_ALPHA);

if ($action === 'upload') {
    if (!isset($_FILES['file']) || is_array($_FILES['file']['name'])) {
        throw new moodle_exception('referencefileupload', 'local_coursegen', '', UPLOAD_ERR_NO_FILE);
    }
    $filename = reference_file_upload::accept((int) $USER->id, $templateid, $slotkey, $_FILES['file']);
    echo json_encode(['filename' => $filename]);
    die();
}
if ($action === 'remove') {
    reference_file_upload::discard((int) $USER->id, $templateid, $slotkey);
    echo json_encode(['filename' => '']);
    die();
}
throw new moodle_exception('invalidparameter', 'debug');

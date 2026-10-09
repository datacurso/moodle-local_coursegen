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
 * Serves a file of the preview to the person who is reviewing the generated course.
 *
 * A page of the preview that embeds a file (a PDF viewer, an image) points to this page. It reads the file from the
 * draft area of the user who is logged in, so nobody else can read it, and shows a PDF or an image inside the page
 * where the draft file page of Moodle would always download it. Any other type is downloaded.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_DEBUG_DISPLAY', true);

require_once(__DIR__ . '/../../config.php');

use local_coursegen\local\preview\preview_file_policy;
use local_coursegen\local\preview\preview_file_request;
use local_coursegen\utils\preview_draft_store;

require_login(null, false);
$context = context_system::instance();
require_capability('local/coursegen:createtemplatecoursewithai', $context);

// The path after the script, for example /221/7f1c2a9e-5b0d-4c1e-9a77-3e2d8b6a4f10/guide.pdf.
$relativepath = get_file_argument();
// Whether the address asks for the download, for example ?forcedownload=1.
$forcedownload = optional_param('forcedownload', 0, PARAM_BOOL);

$request = preview_file_request::from_path($relativepath);
$store = new preview_draft_store($request->sessionid(), '', static fn() => null);
$file = $store->served($request->uid(), $request->filename());
if ($file === null) {
    send_file_not_found();
}

$mimetype = $file->get_mimetype();
$inline = preview_file_policy::is_shown_inline($mimetype, (bool) $forcedownload);
header('X-Content-Type-Options: nosniff');
\core\session\manager::write_close();
send_stored_file($file, 0, 0, !$inline, ['cacheability' => 'private']);

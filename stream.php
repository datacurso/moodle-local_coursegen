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
 * Streams a generation of the AI service to the browser as server-sent events.
 *
 * The browser calls this page, never the service, so Moodle checks who may read the stream.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
// Must be defined before config.php, so Moodle does not buffer the output.
define('NO_OUTPUT_BUFFERING', true);

require_once('../../config.php');

require_login(null, false);
require_sesskey();

// Which stream of the AI service to open: "course" (course planning and generation) or "activity" (one activity).
// Example: ?streamtype=course
// PARAM_ALPHA keeps only letters, because the value ends up in the service URL, so no slashes or dots get through.
$streamtype = required_param('streamtype', PARAM_ALPHA);
// Identifier of the generation thread in the AI service, the one the service returned when the generation started.
// Example: ?threadid=3f2a9c1e-77b4-4e0a-9d21-5c8f
// PARAM_ALPHANUMEXT keeps only letters, numbers, hyphens and underscores, because the value ends up in the service URL,
// so no slashes or dots get through.
$threadid = required_param('threadid', PARAM_ALPHANUMEXT);

$relay = new \local_coursegen\local\streaming\stream_relay();
$relay->run($streamtype, $threadid);

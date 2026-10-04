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

$kind = required_param('kind', PARAM_ALPHA);
$id = required_param('id', PARAM_ALPHANUMEXT);

$relay = new \local_coursegen\local\streaming\stream_relay();
$relay->run($kind, $id);

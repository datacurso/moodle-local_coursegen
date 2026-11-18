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
 * Make the template of a course whose activities carry placeholders.
 *
 * Usage: php local/coursegen/cli/create_template_from_course.php --courseid=646 [--dry-run] [--json]
 * Run it with --help for every option.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_coursegen\local\placeholder\create_template_command;

[$long, $short] = create_template_command::definition();
[$options, $unrecognised] = cli_get_params($long, $short);

if ($unrecognised) {
    $list = implode(PHP_EOL . '  ', $unrecognised);
    cli_error('Unknown options:' . PHP_EOL . '  ' . $list, create_template_command::EXIT_USAGE);
}

$result = create_template_command::run($options);

if ($result['code'] === 0) {
    echo $result['output'] . PHP_EOL;
    exit(0);
}

fwrite(STDERR, $result['output'] . PHP_EOL);
exit($result['code']);

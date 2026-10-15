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
 * Move a template's configuration from one site to another, through a course restored from a backup.
 *
 * The same operation the page local/coursegen/transfer_template.php offers, for a server without a browser.
 *
 * Export (the site that has the template):
 *   php local/coursegen/cli/template_config.php --export --templateid=2 --file=/tmp/template.json
 *
 * Import (the site where the course was restored):
 *   php local/coursegen/cli/template_config.php --import --courseid=123 --file=/tmp/template.json [--dry-run]
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_coursegen\local\service\template_config_transfer;

[$options, $unrecognised] = cli_get_params(
    [
        'export' => false,
        'import' => false,
        'templateid' => 0,
        'courseid' => 0,
        'file' => '',
        'name' => '',
        'dry-run' => false,
        'help' => false,
    ],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error('Unknown options: ' . implode(' ', $unrecognised), 2);
}
if ($options['help'] || (!$options['export'] && !$options['import'])) {
    cli_writeln(
        "Move the configuration of a template to a restored copy of its course.\n\n" .
        "  --export --templateid=N --file=PATH     Write the template's configuration to PATH.\n" .
        "  --import --courseid=N --file=PATH       Rebuild the template over course N from PATH.\n" .
        "    --name=TEXT                           Name of the template (default: the one in the file).\n" .
        "    --dry-run                             Show what would be matched and write nothing.\n"
    );
    exit($options['help'] ? 0 : 2);
}
if ($options['file'] === '') {
    cli_error('--file is required.', 2);
}

if ($options['export']) {
    $data = template_config_transfer::export((int) $options['templateid']);
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    file_put_contents($options['file'], json_encode($data, $flags));
    cli_writeln(sprintf(
        'Exported template %d: %d sections, %d activities, %d instances, %d spaces -> %s',
        $options['templateid'],
        count($data['sections']),
        count($data['activities']),
        count($data['instances']),
        count($data['spaces']),
        $options['file']
    ));
    exit(0);
}

try {
    $data = template_config_transfer::parse((string) file_get_contents($options['file']));
} catch (moodle_exception $error) {
    cli_error($error->getMessage(), 2);
}
$result = template_config_transfer::import((int) $options['courseid'], $data, $options['name'], (bool) $options['dry-run']);
$counts = $result['counts'];
cli_writeln(sprintf(
    'Course %d: %d sections, %d activities, %d instances, %d spaces to configure.',
    $options['courseid'],
    $counts['sections'],
    $counts['activities'],
    $counts['instances'],
    $counts['spaces']
));
if (!$result['ok']) {
    cli_writeln('The course does not match the template. Nothing was written. Missing:');
    foreach ($result['problems'] as $problem) {
        if ($problem['type'] === 'section') {
            cli_writeln('  - section ' . $problem['section']);
        } else {
            cli_writeln(sprintf(
                '  - %s "%s" (number %d) in section %d',
                $problem['modname'],
                $problem['name'],
                $problem['position'],
                $problem['section']
            ));
        }
    }
    exit(1);
}
if ($options['dry-run']) {
    cli_writeln('Dry run: everything matches, nothing was written.');
    exit(0);
}
cli_writeln(sprintf(
    'Template "%s" (id %d) configured over course %d.',
    $result['name'],
    $result['templateid'],
    $options['courseid']
));
exit(0);

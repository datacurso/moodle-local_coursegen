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
 * The template of a course lives in the plugin's tables, not in the course backup, and it refers to
 * sections and activities by id, which change on restore. This command writes the configuration to a
 * file keyed by section number and by activity (type, name and position among those alike), and
 * rebuilds the template over a restored copy of the course by matching on those keys.
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

use local_coursegen\local\models\template;
use local_coursegen\local\service\template_persistence_service;

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

/**
 * The key of each activity of a course: section number, type, name and position among the alike.
 *
 * @param int $courseid
 * @return array{bykey: array<string,int>, bycmid: array<int,string>}
 */
function local_coursegen_cli_activity_keys(int $courseid): array {
    $modinfo = get_fast_modinfo($courseid);
    $seen = [];
    $bykey = [];
    $bycmid = [];
    foreach ($modinfo->get_sections() as $sectionnum => $cmids) {
        foreach ($cmids as $cmid) {
            $cm = $modinfo->get_cm($cmid);
            $base = $sectionnum . '|' . $cm->modname . '|' . trim($cm->name);
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $key = $base . '|' . $seen[$base];
            $bykey[$key] = (int) $cmid;
            $bycmid[(int) $cmid] = $key;
        }
    }
    return ['bykey' => $bykey, 'bycmid' => $bycmid];
}

/**
 * Write the configuration of a template to a file.
 *
 * @param int $templateid
 * @param string $file
 */
function local_coursegen_cli_export(int $templateid, string $file): void {
    global $DB;

    $template = $DB->get_record('local_coursegen_template', ['id' => $templateid], '*', MUST_EXIST);
    $keys = local_coursegen_cli_activity_keys((int) $template->courseid);
    $sectionnums = [];
    foreach ($DB->get_records('course_sections', ['course' => $template->courseid]) as $section) {
        $sectionnums[(int) $section->id] = (int) $section->section;
    }
    $key = function (int $cmid) use ($keys): ?string {
        return $keys['bycmid'][$cmid] ?? null;
    };

    $data = [
        'format' => 1,
        'template' => [
            'name' => $template->name,
            'description' => $template->description,
            'maxsections' => $template->maxsections,
            'nolimit' => (int) $template->nolimit,
            'namingpattern' => $template->namingpattern,
            'namingstart' => (int) $template->namingstart,
        ],
        'sections' => [],
        'activities' => [],
        'instances' => [],
        'spaces' => [],
    ];
    $params = ['templateid' => $templateid];
    foreach ($DB->get_records('local_coursegen_tpl_section', $params, 'sectionnum') as $row) {
        $data['sections'][] = ['sectionnum' => (int) $row->sectionnum, 'behavior' => $row->behavior];
    }
    foreach ($DB->get_records('local_coursegen_tpl_activity', $params, 'id') as $row) {
        $data['activities'][] = [
            'section' => $sectionnums[(int) $row->sectionid] ?? null,
            'key' => $key((int) $row->cmid),
            'action' => $row->action,
            'useasreference' => (int) $row->useasreference,
            'templatescope' => $row->templatescope,
            'prompt' => (string) $row->prompt,
        ];
    }
    foreach ($DB->get_records('local_coursegen_tpl_instance', $params, 'sectionid, sortorder, id') as $row) {
        $data['instances'][] = [
            'section' => $sectionnums[(int) $row->sectionid] ?? null,
            'source' => $key((int) $row->sourcecmid),
            'after' => $key((int) $row->aftercmid),
            'sourcename' => $row->sourcename,
            'name' => $row->name,
            'typelabel' => $row->typelabel,
            'modname' => $row->modname,
            'prompt' => (string) $row->prompt,
            'sortorder' => (int) $row->sortorder,
        ];
    }
    foreach ($DB->get_records('local_coursegen_tpl_space', $params, 'sectionid, sortorder, id') as $row) {
        $data['spaces'][] = [
            'section' => $sectionnums[(int) $row->sectionid] ?? null,
            'after' => $key((int) $row->aftercmid),
            'modname' => $row->modname,
            'required' => (int) $row->required,
            'instruction' => $row->instruction,
            'sortorder' => (int) $row->sortorder,
        ];
    }
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    cli_writeln(sprintf(
        'Exported template %d: %d sections, %d activities, %d instances, %d spaces -> %s',
        $templateid,
        count($data['sections']),
        count($data['activities']),
        count($data['instances']),
        count($data['spaces']),
        $file
    ));
}

/**
 * Rebuild the template over a course from a configuration file.
 *
 * @param int $courseid
 * @param string $file
 * @param string $name
 * @param bool $dryrun
 * @return int Exit code.
 */
function local_coursegen_cli_import(int $courseid, string $file, string $name, bool $dryrun): int {
    global $DB, $USER;

    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data) || ($data['format'] ?? 0) !== 1) {
        cli_error('The file is not a template configuration this command wrote.', 2);
    }

    $keys = local_coursegen_cli_activity_keys($courseid);
    $sectionids = [];
    foreach ($DB->get_records('course_sections', ['course' => $courseid]) as $section) {
        $sectionids[(int) $section->section] = (int) $section->id;
    }

    $problems = [];
    $cmid = function (?string $key, string $what) use ($keys, &$problems): int {
        if ($key === null) {
            return 0;
        }
        if (!isset($keys['bykey'][$key])) {
            $problems[$key] = $what . ': ' . $key;
            return 0;
        }
        return $keys['bykey'][$key];
    };
    $sectionid = function (?int $num, string $what) use ($sectionids, &$problems): int {
        if ($num === null || !isset($sectionids[$num])) {
            $problems['section ' . $num] = $what . ': section ' . $num;
            return 0;
        }
        return $sectionids[$num];
    };

    $sections = [];
    foreach ($data['sections'] as $row) {
        $sections[$row['sectionnum']] = [
            'sectionid' => $sectionid($row['sectionnum'], 'the course has no section'),
            'sectionnum' => $row['sectionnum'],
            'behavior' => $row['behavior'],
            'activities' => [],
            'instances' => [],
            'spaces' => [],
        ];
    }
    foreach ($data['activities'] as $row) {
        $id = $cmid($row['key'], 'no activity matches');
        $sections[$row['section']]['activities'][] = [
            'cmid' => $id,
            'action' => $row['action'],
            'useasreference' => $row['useasreference'],
            'templatescope' => $row['templatescope'],
            'prompt' => $row['prompt'],
        ];
    }
    foreach ($data['instances'] as $row) {
        $sections[$row['section']]['instances'][] = [
            'sourcecmid' => $cmid($row['source'], 'no activity matches'),
            'sourcename' => $row['sourcename'],
            'name' => $row['name'],
            'typelabel' => $row['typelabel'],
            'modname' => $row['modname'],
            'prompt' => $row['prompt'],
            'aftercmid' => $cmid($row['after'], 'no activity matches'),
            'sortorder' => $row['sortorder'],
        ];
    }
    foreach ($data['spaces'] as $row) {
        $sections[$row['section']]['spaces'][] = [
            'modname' => $row['modname'],
            'required' => $row['required'],
            'instruction' => (string) $row['instruction'],
            'aftercmid' => $cmid($row['after'], 'no activity matches'),
            'sortorder' => $row['sortorder'],
        ];
    }

    cli_writeln(sprintf(
        'Course %d "%s": %d sections, %d activities, %d instances, %d spaces to configure.',
        $courseid,
        format_string($course->fullname),
        count($sections),
        count($data['activities']),
        count($data['instances']),
        count($data['spaces'])
    ));
    if ($problems) {
        cli_writeln('The restored course does not match the template. Nothing was written. Missing:');
        foreach ($problems as $problem) {
            cli_writeln('  - ' . $problem);
        }
        return 1;
    }
    if ($dryrun) {
        cli_writeln('Dry run: everything matches, nothing was written.');
        return 0;
    }

    $transaction = $DB->start_delegated_transaction();
    $existing = $DB->get_record('local_coursegen_template', ['courseid' => $courseid]);
    $info = $data['template'];
    $templatename = $name !== '' ? $name : $info['name'];
    if ($existing) {
        $template = new template((int) $existing->id);
    } else {
        $template = new template(0);
        $template->set('courseid', $courseid);
    }
    $template->set('name', $templatename);
    $template->set('description', $info['description']);
    $template->set('maxsections', $info['maxsections']);
    $template->set('nolimit', $info['nolimit']);
    $template->set('namingpattern', $info['namingpattern']);
    $template->set('namingstart', $info['namingstart']);
    $template->set('usermodified', (int) ($USER->id ?? 0));
    if ($existing) {
        $template->update();
    } else {
        $template->create();
    }
    template_persistence_service::save_sections((int) $template->get('id'), array_values($sections));
    $transaction->allow_commit();

    cli_writeln(sprintf('Template "%s" (id %d) configured over course %d.', $templatename, $template->get('id'), $courseid));
    return 0;
}

if ($options['export']) {
    local_coursegen_cli_export((int) $options['templateid'], $options['file']);
    exit(0);
}
exit(local_coursegen_cli_import((int) $options['courseid'], $options['file'], $options['name'], (bool) $options['dry-run']));

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

namespace local_coursegen\local\service;

use local_coursegen\local\models\template;

/**
 * Moves a template's configuration to a restored copy of its course.
 *
 * A template lives in the plugin's tables and refers to sections and activities by id, which change when the
 * course is restored. The configuration is therefore written keyed by section number and by activity (section,
 * type, name and position among those alike), and rebuilt over another course by matching on those keys. When a
 * key has no counterpart in the course nothing is written.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_config_transfer {
    /** @var int Version of the file format. */
    public const FORMAT = 1;

    /**
     * The configuration of a template, ready to be written as JSON.
     *
     * @param int $templateid
     * @return array
     */
    public static function export(int $templateid): array {
        global $DB;

        $template = $DB->get_record('local_coursegen_template', ['id' => $templateid], '*', MUST_EXIST);
        $keys = self::activity_keys((int) $template->courseid);
        $sectionnums = [];
        foreach ($DB->get_records('course_sections', ['course' => $template->courseid]) as $section) {
            $sectionnums[(int) $section->id] = (int) $section->section;
        }
        $key = static function (int $cmid) use ($keys): ?string {
            return $keys['bycmid'][$cmid] ?? null;
        };

        $params = ['templateid' => $templateid];
        $data = [
            'format' => self::FORMAT,
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
        return $data;
    }

    /**
     * The configuration a file holds.
     *
     * @param string $json The content of the file.
     * @return array
     * @throws \moodle_exception When the content is not a configuration this class wrote.
     */
    public static function parse(string $json): array {
        $data = json_decode($json, true);
        $valid = is_array($data) && ($data['format'] ?? 0) === self::FORMAT;
        if ($valid) {
            foreach (['template', 'sections', 'activities', 'instances', 'spaces'] as $part) {
                $valid = $valid && isset($data[$part]) && is_array($data[$part]);
            }
        }
        if (!$valid) {
            throw new \moodle_exception('template_transfer_invalid', 'local_coursegen');
        }
        return $data;
    }

    /**
     * Rebuild the template over a course from a configuration.
     *
     * @param int $courseid The course the template is made over.
     * @param array $data A configuration read by {@see self::parse()}.
     * @param string $name The template's name, or empty to keep the one of the configuration.
     * @param bool $dryrun Whether to only check the course and write nothing.
     * @return array {ok: bool, problems: array, counts: array, templateid: ?int, name: string}
     */
    public static function import(int $courseid, array $data, string $name = '', bool $dryrun = false): array {
        global $DB;

        $DB->get_record('course', ['id' => $courseid], 'id', MUST_EXIST);
        $keys = self::activity_keys($courseid);
        $sectionids = [];
        foreach ($DB->get_records('course_sections', ['course' => $courseid]) as $section) {
            $sectionids[(int) $section->section] = (int) $section->id;
        }

        $problems = [];
        $sections = self::sections($data, $sectionids, $keys, $problems);
        $templatename = $name !== '' ? $name : (string) $data['template']['name'];
        $result = [
            'ok' => $problems === [],
            'problems' => array_values($problems),
            'counts' => [
                'sections' => count($sections),
                'activities' => count($data['activities']),
                'instances' => count($data['instances']),
                'spaces' => count($data['spaces']),
            ],
            'templateid' => null,
            'name' => $templatename,
        ];
        if ($result['ok'] && !$dryrun) {
            $result['templateid'] = self::write($courseid, $data, $templatename, $sections);
        }
        return $result;
    }

    /**
     * The key of each activity of a course: section number, type, name and position among the alike.
     *
     * @param int $courseid
     * @return array{bykey: array<string,int>, bycmid: array<int,string>}
     */
    private static function activity_keys(int $courseid): array {
        $modinfo = get_fast_modinfo($courseid, -1);
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
     * What a missing key looks like in the list of problems.
     *
     * @param string $key A key made by {@see self::activity_keys()}.
     * @return array {type: string, section: int, modname: string, name: string, position: int}
     */
    private static function missing_activity(string $key): array {
        $parts = explode('|', $key);
        $position = (int) array_pop($parts);
        $section = (int) array_shift($parts);
        $modname = (string) array_shift($parts);
        return [
            'type' => 'activity',
            'section' => $section,
            'modname' => $modname,
            'name' => implode('|', $parts),
            'position' => $position,
        ];
    }

    /**
     * The sections of the configuration, with every id replaced by the one of the course.
     *
     * @param array $data
     * @param array $sectionids Section number => id, in the course.
     * @param array $keys The activities of the course, see {@see self::activity_keys()}.
     * @param array $problems Filled with what the course does not have.
     * @return array The sections, in the shape {@see template_persistence_service::save_sections()} reads.
     */
    private static function sections(array $data, array $sectionids, array $keys, array &$problems): array {
        $cmid = static function (?string $key) use ($keys, &$problems): int {
            if ($key === null) {
                return 0;
            }
            if (!isset($keys['bykey'][$key])) {
                $problems['a' . $key] = self::missing_activity($key);
                return 0;
            }
            return $keys['bykey'][$key];
        };

        $sections = [];
        foreach ($data['sections'] as $row) {
            $num = (int) $row['sectionnum'];
            if (!isset($sectionids[$num])) {
                $problems['s' . $num] = ['type' => 'section', 'section' => $num];
            }
            $sections[$num] = [
                'sectionid' => $sectionids[$num] ?? 0,
                'sectionnum' => $num,
                'behavior' => $row['behavior'],
                'activities' => [],
                'instances' => [],
                'spaces' => [],
            ];
        }
        foreach ($data['activities'] as $row) {
            $sections[(int) $row['section']]['activities'][] = [
                'cmid' => $cmid($row['key']),
                'action' => $row['action'],
                'useasreference' => $row['useasreference'],
                'templatescope' => $row['templatescope'],
                'prompt' => $row['prompt'],
            ];
        }
        foreach ($data['instances'] as $row) {
            $sections[(int) $row['section']]['instances'][] = [
                'sourcecmid' => $cmid($row['source']),
                'sourcename' => $row['sourcename'],
                'name' => $row['name'],
                'typelabel' => $row['typelabel'],
                'modname' => $row['modname'],
                'prompt' => $row['prompt'],
                'aftercmid' => $cmid($row['after']),
                'sortorder' => $row['sortorder'],
            ];
        }
        foreach ($data['spaces'] as $row) {
            $sections[(int) $row['section']]['spaces'][] = [
                'modname' => $row['modname'],
                'required' => $row['required'],
                'instruction' => (string) $row['instruction'],
                'aftercmid' => $cmid($row['after']),
                'sortorder' => $row['sortorder'],
            ];
        }
        return $sections;
    }

    /**
     * Create or update the template of the course and rebuild everything it holds.
     *
     * @param int $courseid
     * @param array $data
     * @param string $name
     * @param array $sections The sections made by {@see self::sections()}.
     * @return int The template's id.
     */
    private static function write(int $courseid, array $data, string $name, array $sections): int {
        global $DB, $USER;

        $transaction = $DB->start_delegated_transaction();
        $existing = $DB->get_record('local_coursegen_template', ['courseid' => $courseid]);
        $template = new template($existing ? (int) $existing->id : 0);
        if (!$existing) {
            $template->set('courseid', $courseid);
        }
        $info = $data['template'];
        $template->set('name', $name);
        $template->set('description', $info['description']);
        $template->set('maxsections', $info['maxsections']);
        $template->set('nolimit', (int) $info['nolimit']);
        $template->set('namingpattern', $info['namingpattern']);
        $template->set('namingstart', (int) $info['namingstart']);
        $template->set('usermodified', (int) ($USER->id ?? 0));
        if ($existing) {
            $template->update();
        } else {
            $template->create();
        }
        template_persistence_service::save_sections((int) $template->get('id'), array_values($sections));
        $transaction->allow_commit();
        return (int) $template->get('id');
    }
}

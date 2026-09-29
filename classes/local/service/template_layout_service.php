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

use context_course;
use local_coursegen\local\models\template;
use local_coursegen\local\models\template_instance;
use local_coursegen\local\models\template_section;

/**
 * Lays a generated course out the way its template is laid out.
 *
 * Generation builds a section's content in two passes that each append to
 * the section: the AI-generated activities first, the kept copies after. The
 * template itself interleaves both (a kept lesson sits between a label and a
 * page), so once every activity exists each section's sequence is rewritten
 * to follow the template's own order: the base section's real activities,
 * with the saved instances anchored after them (template_instance_layout).
 * The base section's summary is carried over too when generation left the
 * new section without one.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_layout_service {
    /**
     * Order every section like the template and copy the missing summaries.
     *
     * @param int $templateid
     * @param int $targetcourseid
     * @param array<int,int> $generatedcms template_instance id => cmid created for it.
     * @param array<int,int> $keptcms Base course cmid => cmid of its copy.
     * @return void
     */
    public static function apply(int $templateid, int $targetcourseid, array $generatedcms, array $keptcms): void {
        $template = template::get_record(['id' => $templateid]);
        if (!$template) {
            return;
        }

        $basecourse = get_course($template->get('courseid'));
        $targetcourse = get_course($targetcourseid);
        $basemodinfo = get_fast_modinfo($basecourse);
        $targetmodinfo = get_fast_modinfo($targetcourse);

        $instancesbysection = [];
        foreach (template_instance::get_records(['templateid' => $templateid]) as $instance) {
            $instancesbysection[(int) $instance->get('sectionid')][] = $instance;
        }
        $behaviors = [];
        foreach (template_section::get_records(['templateid' => $templateid]) as $section) {
            $behaviors[(int) $section->get('sectionid')] = (string) $section->get('behavior');
        }

        $changed = false;
        foreach ($basemodinfo->get_section_info_all() as $basesection) {
            $targetsection = $targetmodinfo->get_section_info((int) $basesection->section, IGNORE_MISSING);
            if (!$targetsection) {
                continue;
            }

            $basecmids = $basemodinfo->sections[$basesection->section] ?? [];
            $rows = template_instance_layout::ordered_rows(
                array_map('intval', $basecmids),
                $instancesbysection[(int) $basesection->id] ?? []
            );
            $ordered = self::target_cmids($rows, $generatedcms, $keptcms);
            $changed = self::reorder_section($targetsection, $ordered) || $changed;

            if (($behaviors[(int) $basesection->id] ?? 'aimodify') !== 'exclude') {
                $changed = self::copy_summary($basesection, $basecourse, $targetsection, $targetcourse) || $changed;
            }
        }

        if ($changed) {
            rebuild_course_cache($targetcourseid, true);
        }
    }

    /**
     * The saved instance of THIS template a payload cmid stands for.
     *
     * A real cmid echoed back (a kept activity) never resolves; a synthetic
     * cmid resolves only when the tpl_instance it names belongs to the template.
     *
     * @param int $cmid A cmid from the payload.
     * @param int $templateid
     * @return int|null The template_instance id, or null.
     */
    public static function instance_id_for(int $cmid, int $templateid): ?int {
        global $DB;

        $instanceid = template_export_service::instance_id_of($cmid);
        if ($instanceid === null || $templateid <= 0) {
            return null;
        }
        // The offset arithmetic alone cannot tell a synthetic instance cmid
        // apart from a REAL course_modules id that happens to land in the
        // same numeric range once a site's global id sequence passes
        // INSTANCE_CMID_BASE - a real cmid must never be treated as an
        // instance, even if it collides with a genuine template_instance id
        // of the template being generated.
        if ($DB->record_exists('course_modules', ['id' => $cmid])) {
            return null;
        }
        $exists = template_instance::record_exists_select(
            'id = :id AND templateid = :templateid',
            ['id' => $instanceid, 'templateid' => $templateid]
        );
        return $exists ? $instanceid : null;
    }

    /**
     * Key the created activities by the saved instance each one stands for.
     *
     * @param array<int,int> $generatedcms Payload cmid => created cmid.
     * @param int $templateid
     * @return array<int,int> template_instance id => created cmid; entries that are not
     *     instances of this template are dropped.
     */
    public static function generated_by_instance(array $generatedcms, int $templateid): array {
        $byinstance = [];
        foreach ($generatedcms as $payloadcmid => $cmid) {
            $instanceid = self::instance_id_for((int) $payloadcmid, $templateid);
            if ($instanceid !== null) {
                $byinstance[$instanceid] = (int) $cmid;
            }
        }
        return $byinstance;
    }

    /**
     * Translate the template's row order into target course cmids.
     *
     * Rows with no counterpart in the target (a mold, an excluded activity,
     * an instance or copy that failed) are skipped.
     *
     * @param array $rows template_instance_layout::ordered_rows() output.
     * @param array<int,int> $generatedcms
     * @param array<int,int> $keptcms
     * @return int[]
     */
    private static function target_cmids(array $rows, array $generatedcms, array $keptcms): array {
        $cmids = [];
        foreach ($rows as $row) {
            if ($row['type'] === 'real') {
                $cmid = $keptcms[(int) $row['cmid']] ?? null;
            } else {
                $cmid = $generatedcms[(int) $row['record']->get('id')] ?? null;
            }
            if ($cmid !== null) {
                $cmids[] = (int) $cmid;
            }
        }
        return $cmids;
    }

    /**
     * Rewrite one target section's sequence to the given order.
     *
     * Activities of the section the template knows nothing about keep their
     * current relative order after the ordered ones; cmids not in the
     * section are ignored.
     *
     * @param \section_info $targetsection
     * @param int[] $ordered Desired leading order.
     * @return bool Whether the sequence changed.
     */
    private static function reorder_section($targetsection, array $ordered): bool {
        global $DB;

        $current = array_map('intval', array_filter(explode(',', (string) $targetsection->sequence)));
        if (empty($current)) {
            return false;
        }
        $present = array_flip($current);
        $sequence = [];
        foreach ($ordered as $cmid) {
            if (isset($present[$cmid]) && !in_array($cmid, $sequence, true)) {
                $sequence[] = $cmid;
            }
        }
        foreach ($current as $cmid) {
            if (!in_array($cmid, $sequence, true)) {
                $sequence[] = $cmid;
            }
        }
        if ($sequence === $current) {
            return false;
        }

        $DB->set_field('course_sections', 'sequence', implode(',', $sequence), ['id' => $targetsection->id]);
        return true;
    }

    /**
     * Give the target section the base section's summary, files included, when it has none.
     *
     * @param \section_info $basesection
     * @param \stdClass $basecourse
     * @param \section_info $targetsection
     * @param \stdClass $targetcourse
     * @return bool Whether the section changed.
     */
    private static function copy_summary($basesection, $basecourse, $targetsection, $targetcourse): bool {
        global $DB;

        // Read the target row itself: modinfo may lag behind the section
        // writes generation just made.
        $targetsummary = (string) $DB->get_field('course_sections', 'summary', ['id' => $targetsection->id]);
        if (trim($targetsummary) !== '' || trim((string) $basesection->summary) === '') {
            return false;
        }

        $fs = get_file_storage();
        $basecontextid = context_course::instance($basecourse->id)->id;
        $targetcontextid = context_course::instance($targetcourse->id)->id;
        $files = $fs->get_area_files($basecontextid, 'course', 'section', $basesection->id, 'id', false);
        foreach ($files as $file) {
            $fs->create_file_from_storedfile([
                'contextid' => $targetcontextid,
                'component' => 'course',
                'filearea' => 'section',
                'itemid' => $targetsection->id,
                'filepath' => $file->get_filepath(),
                'filename' => $file->get_filename(),
            ], $file);
        }

        $DB->update_record('course_sections', (object) [
            'id' => $targetsection->id,
            'summary' => $basesection->summary,
            'summaryformat' => (int) $basesection->summaryformat,
            'timemodified' => time(),
        ]);
        return true;
    }
}

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

use course_modinfo;
use local_coursegen\local\link\activity_url_remap;
use local_coursegen\local\link\module_link_texts;
use local_coursegen\local\link\module_link_texts_registry;

/**
 * Points the links of the copied kept activities at the new course.
 *
 * A kept activity is copied as it was written, so the links in its texts
 * still address the activities of the template's course. Each address of an
 * activity that has a counterpart in the new course is changed to that
 * counterpart: a kept activity maps to its copy and a template source that
 * produced exactly one activity maps to that activity. An address of any
 * other activity is left as it is, as is every text of a module that is not in
 * module_link_texts_registry.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class kept_link_rewriter {
    /**
     * Rewrite the links of the kept activities of a course built from a template result.
     *
     * @param int $courseid The new course.
     * @param array $payloadactivities Every activity entry of the result, kept ones included.
     * @param array $generatedcms Payload cmid => created cmid, for the generated activities.
     * @param array $keptcms Base course cmid => created cmid, for the copied kept activities.
     */
    public static function rewrite_for_course(
        int $courseid,
        array $payloadactivities,
        array $generatedcms,
        array $keptcms
    ): void {
        $newcmidbyoldcmid = self::counterparts($payloadactivities, $generatedcms, $keptcms);
        if ($newcmidbyoldcmid === [] || $keptcms === []) {
            return;
        }
        course_modinfo::clear_instance_cache($courseid);
        $modinfo = get_fast_modinfo($courseid);
        self::rewrite_activities($modinfo, array_values($keptcms), $newcmidbyoldcmid);
        rebuild_course_cache($courseid, true);
    }

    /**
     * The activity of the new course that stands for each activity of the template's course.
     *
     * @param array $payloadactivities
     * @param array $generatedcms
     * @param array $keptcms
     * @return array<int,int> Template course cmid => new course cmid.
     */
    private static function counterparts(array $payloadactivities, array $generatedcms, array $keptcms): array {
        $counterparts = $keptcms;
        $instancecmidsbysource = self::instance_cmids_by_source($payloadactivities, $generatedcms);
        foreach ($instancecmidsbysource as $sourcecmid => $cmids) {
            if (count($cmids) === 1 && $cmids[0] > 0) {
                $counterparts[$sourcecmid] = $cmids[0];
            }
        }
        return $counterparts;
    }

    /**
     * The created activities of every template source.
     *
     * @param array $payloadactivities
     * @param array $generatedcms
     * @return array<int,int[]> Template source cmid => created cmid of each of its instances, 0 when not created.
     */
    private static function instance_cmids_by_source(array $payloadactivities, array $generatedcms): array {
        $bysource = [];
        foreach ($payloadactivities as $entry) {
            $behavior = $entry['template_behavior'] ?? [];
            $action = $behavior['action'] ?? '';
            $sourcecmid = (int) ($behavior['template_source_cmid'] ?? 0);
            $payloadcmid = (int) ($entry['cmid'] ?? 0);
            if ($action !== 'instance' || $sourcecmid === 0) {
                continue;
            }
            $createdcmid = $generatedcms[$payloadcmid] ?? 0;
            $bysource[$sourcecmid][] = (int) $createdcmid;
        }
        return $bysource;
    }

    /**
     * Rewrite every text of each of the given activities, when its module is searched.
     *
     * @param course_modinfo $modinfo
     * @param int[] $cmids
     * @param array<int,int> $newcmidbyoldcmid
     */
    private static function rewrite_activities(course_modinfo $modinfo, array $cmids, array $newcmidbyoldcmid): void {
        global $DB;

        foreach ($cmids as $cmid) {
            $cm = $modinfo->get_cm($cmid);
            $texts = module_link_texts_registry::for_module($cm->modname);
            if ($texts === null) {
                continue;
            }
            $records = $DB->get_records($texts->table(), [$texts->instance_column() => $cm->instance]);
            self::rewrite_records($texts, $records, $newcmidbyoldcmid);
        }
    }

    /**
     * Rewrite the text columns of each record.
     *
     * @param module_link_texts $texts
     * @param \stdClass[] $records
     * @param array<int,int> $newcmidbyoldcmid
     */
    private static function rewrite_records(module_link_texts $texts, array $records, array $newcmidbyoldcmid): void {
        foreach ($records as $record) {
            self::rewrite_columns($texts, $record, $newcmidbyoldcmid);
        }
    }

    /**
     * Rewrite each text column of one record, storing only the ones that changed.
     *
     * @param module_link_texts $texts
     * @param \stdClass $record
     * @param array<int,int> $newcmidbyoldcmid
     */
    private static function rewrite_columns(module_link_texts $texts, \stdClass $record, array $newcmidbyoldcmid): void {
        global $DB;

        foreach ($texts->text_columns() as $column) {
            $original = (string) $record->{$column};
            $rewritten = activity_url_remap::apply($original, $newcmidbyoldcmid);
            if ($rewritten !== $original) {
                $DB->set_field($texts->table(), $column, $rewritten, ['id' => $record->id]);
            }
        }
    }
}

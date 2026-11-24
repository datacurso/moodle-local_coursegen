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
use local_coursegen\local\models\template_instance;

/**
 * Points the links of the copied kept activities, of the generated ones and of the section summaries at the new course.
 *
 * A kept activity is copied as it was written, and a generated one keeps the text of its template
 * that has no placeholder, so the links in both still address the activities of the template's course. Each address of an
 * activity that has a counterpart in the new course is changed to that
 * counterpart: a kept activity maps to its copy and a template source that
 * produced exactly one activity maps to that activity. An address of any
 * other activity is left as it is, as is every text of a module that is not in
 * module_link_texts_registry. Section summaries are copied from the template as they were written too, so the
 * cards of a section that lead to its activities are rewritten the same way.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class kept_link_rewriter {
    /**
     * Rewrite the links of the kept and the generated activities of a course built from a template result.
     *
     * @param int $courseid The new course.
     * @param array $payloadactivities Every activity entry of the result, kept ones included.
     * @param array $generatedcms Activity key (generated_activity_key) => created cmid, for the generated activities.
     * @param array $keptcms Base course cmid => created cmid, for the copied kept activities.
     */
    public static function rewrite_for_course(
        int $courseid,
        array $payloadactivities,
        array $generatedcms,
        array $keptcms
    ): void {
        $newcmidbyoldcmid = self::counterparts($payloadactivities, $generatedcms, $keptcms)
            + self::rewritten_without_mold($payloadactivities, $generatedcms);
        if ($newcmidbyoldcmid === []) {
            return;
        }
        $cmids = array_unique(array_map('intval', array_merge(array_values($keptcms), array_values($generatedcms))));
        $cmids = array_values(array_filter($cmids));
        if ($cmids !== []) {
            course_modinfo::clear_instance_cache($courseid);
            $modinfo = get_fast_modinfo($courseid);
            self::rewrite_activities($modinfo, $cmids, $newcmidbyoldcmid);
        }
        self::rewrite_section_summaries($courseid, $newcmidbyoldcmid);
        rebuild_course_cache($courseid, true);
    }

    /**
     * Rewrite the summaries of the sections of the new course.
     *
     * A summary is copied from the template's section as it was written, so its links (the cards that lead to
     * the activities of the section) still address the template's course.
     *
     * @param int $courseid
     * @param array $newcmidbyoldcmid
     */
    private static function rewrite_section_summaries(int $courseid, array $newcmidbyoldcmid): void {
        global $DB;

        $sections = $DB->get_records('course_sections', ['course' => $courseid], '', 'id, summary');
        foreach ($sections as $section) {
            $original = (string) $section->summary;
            $rewritten = activity_url_remap::apply($original, $newcmidbyoldcmid);
            if ($rewritten !== $original) {
                $DB->set_field('course_sections', 'summary', $rewritten, ['id' => $section->id]);
            }
        }
    }

    /**
     * The activity of the new course that stands for each activity of the template's course.
     *
     * @param array $payloadactivities
     * @param array $generatedcms
     * @param array $keptcms
     * @return array<int,int> Template course cmid => new course cmid.
     */
    public static function counterparts(array $payloadactivities, array $generatedcms, array $keptcms): array {
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
     * The activities of the template's course that the AI rewrote without a mold, mapped to the activity that replaced them.
     *
     * An activity "modified with AI" that has no markers is written from its instruction, so its instance names no
     * template source; the template keeps which activity it stands for (its owner) on the instance, found here by the
     * uid the instance carries in the result.
     *
     * @param array $payloadactivities
     * @param array $generatedcms
     * @return array<int,int> Template course cmid => new course cmid.
     */
    private static function rewritten_without_mold(array $payloadactivities, array $generatedcms): array {
        global $DB;

        $createdbyuid = [];
        foreach ($payloadactivities as $entry) {
            $behavior = $entry['template_behavior'] ?? [];
            if (($behavior['action'] ?? '') !== 'instance' || (int) ($behavior['template_source_cmid'] ?? 0) !== 0) {
                continue;
            }
            $uid = trim((string) ($entry['uid'] ?? ''));
            $created = (int) ($generatedcms[generated_activity_key::of($entry)] ?? 0);
            if ($uid !== '' && $created > 0) {
                $createdbyuid[$uid] = $created;
            }
        }
        if ($createdbyuid === []) {
            return [];
        }
        $owned = [];
        foreach (array_chunk(array_keys($createdbyuid), 200) as $uids) {
            [$insql, $params] = $DB->get_in_or_equal($uids);
            foreach (template_instance::get_records_select("uid $insql AND ownercmid > 0", $params) as $instance) {
                $owned[(int) $instance->get('ownercmid')] = $createdbyuid[(string) $instance->get('uid')];
            }
        }
        return $owned;
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
            if ($action !== 'instance' || $sourcecmid === 0) {
                continue;
            }
            $createdcmid = $generatedcms[generated_activity_key::of($entry)] ?? 0;
            $bysource[$sourcecmid][] = (int) $createdcmid;
        }
        return $bysource;
    }

    /**
     * Rewrite every text of each of the given activities, when its module is searched.
     *
     * @param course_modinfo $modinfo
     * @param int[] $cmids
     * @param array $newcmidbyoldcmid
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
     * @param array $newcmidbyoldcmid
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
     * @param array $newcmidbyoldcmid
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

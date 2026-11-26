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

use cm_info;
use course_modinfo;
use local_coursegen\local\link\embed_link;
use local_coursegen\local\link\link_token;
use local_coursegen\local\link\module_link_texts;
use local_coursegen\local\link\module_link_texts_registry;

/**
 * Turns the link tokens of the generated activities into real activity URLs.
 *
 * Runs once every activity of the course exists, the generated ones and the
 * copied kept ones, because a token may name any of them. Only the texts of
 * the generated activities of the modules in module_link_texts_registry are
 * read. Every text is resolved in memory before any is stored, so a token that
 * cannot be resolved stops the run with nothing rewritten, and no token is
 * ever left in the stored content without being reported.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class activity_link_resolver {
    /**
     * Resolve the tokens of a course built from a template result.
     *
     * @param int $courseid The new course.
     * @param array $payloadactivities Every activity entry of the result, kept ones included.
     * @param array $generatedcms Activity key (generated_activity_key) => created cmid, for the generated activities.
     * @param array $keptcms Template cmid => created cmid, for the copied kept activities.
     * @param array $clonecms Clone section number => [template cmid => cmid of its copy there].
     * @throws \moodle_exception When a token names no activity of the course.
     */
    public static function resolve_for_course(
        int $courseid,
        array $payloadactivities,
        array $generatedcms,
        array $keptcms,
        array $clonecms = []
    ): void {
        $cmidbyuid = link_targets::build($payloadactivities, $generatedcms, $keptcms);
        $generatedcmids = array_values($generatedcms);
        self::resolve($courseid, $cmidbyuid, $generatedcmids, self::clone_targets($payloadactivities, $generatedcms, $clonecms));
    }

    /**
     * For each section that repeats a unit, the uid of every kept activity copied there => its copy there.
     *
     * @param array $payloadactivities
     * @param array $generatedcms
     * @param array $clonecms Clone section number => [template cmid => cmid of its copy there].
     * @return array<int,array<string,int>> Section number => uid => cmid.
     */
    private static function clone_targets(array $payloadactivities, array $generatedcms, array $clonecms): array {
        $targets = [];
        foreach ($payloadactivities as $entry) {
            $uid = (string) ($entry['uid'] ?? '');
            $cmid = (int) ($entry['cmid'] ?? 0);
            if ($uid === '' || $cmid <= 0 || isset($generatedcms[generated_activity_key::of($entry)])) {
                continue;
            }
            foreach ($clonecms as $section => $copies) {
                if (isset($copies[$cmid])) {
                    $targets[(int) $section][$uid] = (int) $copies[$cmid];
                }
            }
        }
        return $targets;
    }

    /**
     * Resolve the tokens in the texts of the given generated activities.
     *
     * @param int $courseid
     * @param array $cmidbyuid Target uid => created cmid.
     * @param int[] $generatedcmids The activities whose texts are searched.
     * @param array $sectioncmidbyuid Section number => uid => cmid: the targets that differ in that section.
     * @throws \moodle_exception When a token names no activity of the course.
     */
    public static function resolve(int $courseid, array $cmidbyuid, array $generatedcmids, array $sectioncmidbyuid = []): void {
        global $DB;

        course_modinfo::clear_instance_cache($courseid);
        $modinfo = get_fast_modinfo($courseid);
        $urlbyuid = self::urls_by_uid($cmidbyuid, $modinfo);
        $sectionurlbyuid = [];
        foreach ($sectioncmidbyuid as $section => $targets) {
            $sectionurlbyuid[(int) $section] = self::urls_by_uid($targets, $modinfo);
        }

        // Every text is resolved in memory first, so a token that cannot be resolved stops the run before anything is written.
        $updates = self::resolve_activities($modinfo, $generatedcmids, $urlbyuid, $sectionurlbyuid);

        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($updates as $update) {
                $DB->set_field($update->table, $update->column, $update->value, ['id' => $update->id]);
            }
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }

        self::hide_embedded_from_course_page($courseid, $updates);
        rebuild_course_cache($courseid, true);
    }

    /**
     * Take off the course page every H5P activity another activity shows embedded.
     *
     * The student uses it inside the activity that embeds it; listed on the course page as well, it was one more
     * activity to do, the same one twice. It stays available (and graded) through the embedding activity. Moodle hides
     * an available activity from the course page only on a site that allows it ("allowstealth"); elsewhere it stays
     * listed as before.
     *
     * @param int $courseid
     * @param \stdClass[] $updates The texts written, each with the activities it embeds.
     */
    private static function hide_embedded_from_course_page(int $courseid, array $updates): void {
        global $DB;

        $cmids = [];
        foreach ($updates as $update) {
            foreach ($update->embedded as $cmid) {
                $cmids[$cmid] = $cmid;
            }
        }
        foreach ($cmids as $cmid) {
            $visible = $DB->get_field('course_modules', 'visible', ['id' => $cmid, 'course' => $courseid]);
            if ((int) $visible === 1) {
                set_coursemodule_visible($cmid, 1, 0, false);
            }
        }
    }

    /**
     * The URL of every target that has a page of its own.
     *
     * @param array $cmidbyuid
     * @param course_modinfo $modinfo
     * @return array<string,string> uid => URL.
     */
    private static function urls_by_uid(array $cmidbyuid, course_modinfo $modinfo): array {
        $urlbyuid = [];
        foreach ($cmidbyuid as $uid => $cmid) {
            $cm = $modinfo->get_cm($cmid);
            // An activity with no page of its own (a text and media area) is reached at its place in the course.
            $url = $cm->get_url() ?? new \moodle_url('/course/view.php', ['id' => $cm->course], 'module-' . $cm->id);
            $urlbyuid[(string) $uid] = $url->out(false);
        }
        return $urlbyuid;
    }

    /**
     * Resolve the texts of each of the given activities, without storing anything.
     *
     * @param course_modinfo $modinfo
     * @param int[] $generatedcmids
     * @param array $urlbyuid
     * @param array $sectionurlbyuid Section number => uid => URL, overriding $urlbyuid in that section.
     * @return \stdClass[] The texts that changed, each with table, id, column and value.
     * @throws \moodle_exception When a token remains after resolving.
     */
    private static function resolve_activities(
        course_modinfo $modinfo,
        array $generatedcmids,
        array $urlbyuid,
        array $sectionurlbyuid = []
    ): array {
        $updates = [];
        foreach ($generatedcmids as $cmid) {
            $cm = $modinfo->get_cm($cmid);
            $urls = ($sectionurlbyuid[(int) $cm->sectionnum] ?? []) + $urlbyuid;
            $updates = array_merge($updates, self::resolve_activity($cm, $urls));
        }
        return $updates;
    }

    /**
     * Resolve every text of one activity, when its module is searched.
     *
     * @param cm_info $cm
     * @param array $urlbyuid
     * @return \stdClass[] The texts that changed, each with table, id, column and value.
     * @throws \moodle_exception When a token remains after resolving.
     */
    private static function resolve_activity(cm_info $cm, array $urlbyuid): array {
        global $DB;

        $texts = module_link_texts_registry::for_module($cm->modname);
        if ($texts === null) {
            return [];
        }
        $records = $DB->get_records($texts->table(), [$texts->instance_column() => $cm->instance]);
        return self::resolve_records($cm, $texts, $records, $urlbyuid);
    }

    /**
     * Resolve the text columns of each record.
     *
     * @param cm_info $cm
     * @param module_link_texts $texts
     * @param \stdClass[] $records
     * @param array $urlbyuid
     * @return \stdClass[] The texts that changed, each with table, id, column and value.
     * @throws \moodle_exception When a token remains after resolving.
     */
    private static function resolve_records(cm_info $cm, module_link_texts $texts, array $records, array $urlbyuid): array {
        $updates = [];
        foreach ($records as $record) {
            $updates = array_merge($updates, self::resolve_columns($cm, $texts, $record, $urlbyuid));
        }
        return $updates;
    }

    /**
     * Resolve each text column of one record.
     *
     * @param cm_info $cm
     * @param module_link_texts $texts
     * @param \stdClass $record
     * @param array $urlbyuid
     * @return \stdClass[] The texts that changed, each with table, id, column and value.
     * @throws \moodle_exception When a token remains after resolving.
     */
    private static function resolve_columns(cm_info $cm, module_link_texts $texts, \stdClass $record, array $urlbyuid): array {
        $updates = [];
        foreach ($texts->text_columns() as $column) {
            $update = self::resolve_column($cm, $texts->table(), $record, $column, $urlbyuid);
            if ($update !== null) {
                $updates[] = $update;
            }
        }
        return $updates;
    }

    /**
     * Resolve one text and refuse to leave a token in it.
     *
     * @param cm_info $cm
     * @param string $table
     * @param \stdClass $record
     * @param string $column
     * @param array $urlbyuid
     * @return \stdClass|null The update to store (with the H5P activities it embeds), or null when the text did not change.
     * @throws \moodle_exception When a token remains after resolving.
     */
    private static function resolve_column(
        cm_info $cm,
        string $table,
        \stdClass $record,
        string $column,
        array $urlbyuid
    ): ?\stdClass {
        $original = (string) $record->{$column};
        $resolved = link_token::replace($original, $urlbyuid);
        $embedded = embed_link::embedded_cmids($resolved);
        $resolved = embed_link::apply($resolved);

        $remaininguid = link_token::first_remaining_uid($resolved);
        if ($remaininguid !== null) {
            $details = (object) ['activity' => $cm->name, 'uid' => $remaininguid];
            throw new \moodle_exception('linkunresolved', 'local_coursegen', '', $details);
        }
        if ($resolved === $original) {
            return null;
        }
        return (object) [
            'table' => $table,
            'id' => $record->id,
            'column' => $column,
            'value' => $resolved,
            'embedded' => $embedded,
        ];
    }
}

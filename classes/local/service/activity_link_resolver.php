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
use local_coursegen\local\link\link_token;
use local_coursegen\local\link\module_link_texts;
use local_coursegen\local\link\module_link_texts_registry;

/**
 * Turns the link tokens of the generated activities into real activity URLs.
 *
 * Runs once every activity of the course exists, the generated ones and the
 * copied kept ones, because a token may name any of them. Only the texts of
 * the generated activities of the modules in module_link_texts_registry are
 * read. A token that cannot be resolved stops the run: nothing is rewritten
 * and no token is ever left in the stored content without being reported.
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
     * @param array $generatedcms Payload cmid => created cmid, for the generated activities.
     * @param array $keptcms Payload cmid => created cmid, for the copied kept activities.
     * @throws \moodle_exception When a token names no activity of the course.
     */
    public static function resolve_for_course(
        int $courseid,
        array $payloadactivities,
        array $generatedcms,
        array $keptcms
    ): void {
        $cmidbyuid = link_targets::build($payloadactivities, $generatedcms, $keptcms);
        $generatedcmids = array_values($generatedcms);
        self::resolve($courseid, $cmidbyuid, $generatedcmids);
    }

    /**
     * Resolve the tokens in the texts of the given generated activities.
     *
     * @param int $courseid
     * @param array<string,int> $cmidbyuid Target uid => created cmid.
     * @param int[] $generatedcmids The activities whose texts are searched.
     * @throws \moodle_exception When a token names no activity of the course.
     */
    public static function resolve(int $courseid, array $cmidbyuid, array $generatedcmids): void {
        global $DB;

        course_modinfo::clear_instance_cache($courseid);
        $modinfo = get_fast_modinfo($courseid);
        $urlbyuid = self::urls_by_uid($cmidbyuid, $modinfo);
        $embedurlbyuid = self::file_urls_by_uid($cmidbyuid, $modinfo);

        $transaction = $DB->start_delegated_transaction();
        try {
            self::resolve_activities($modinfo, $generatedcmids, $urlbyuid, $embedurlbyuid);
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }

        rebuild_course_cache($courseid, true);
    }

    /**
     * The URL of every target that has a page of its own.
     *
     * @param array<string,int> $cmidbyuid
     * @param course_modinfo $modinfo
     * @return array<string,string> uid => URL.
     */
    private static function urls_by_uid(array $cmidbyuid, course_modinfo $modinfo): array {
        $urlbyuid = [];
        foreach ($cmidbyuid as $uid => $cmid) {
            $cm = $modinfo->get_cm($cmid);
            $url = $cm->get_url();
            if ($url === null) {
                continue;
            }
            $urlbyuid[(string) $uid] = $url->out(false);
        }
        return $urlbyuid;
    }

    /**
     * The URL of the stored file of every target that is a file resource.
     *
     * A src that points at a resource shows its file, so it needs the address of the file in the new course. A
     * resource with no file is left out and its src keeps the page URL.
     *
     * @param array<string,int> $cmidbyuid
     * @param course_modinfo $modinfo
     * @return array<string,string> uid => URL of the file.
     */
    private static function file_urls_by_uid(array $cmidbyuid, course_modinfo $modinfo): array {
        global $DB;

        $urls = [];
        foreach ($cmidbyuid as $uid => $cmid) {
            $cm = $modinfo->get_cm($cmid);
            if ($cm->modname !== 'resource') {
                continue;
            }
            $files = get_file_storage()->get_area_files($cm->context->id, 'mod_resource', 'content', 0, 'sortorder, id', false);
            $file = reset($files);
            if (!$file) {
                continue;
            }
            $revision = (int) $DB->get_field('resource', 'revision', ['id' => $cm->instance]);
            $filepath = $file->get_filepath();
            $filename = $file->get_filename();
            $url = \moodle_url::make_pluginfile_url($cm->context->id, 'mod_resource', 'content', $revision, $filepath, $filename);
            $urls[(string) $uid] = $url->out(false);
        }
        return $urls;
    }

    /**
     * Resolve the texts of each of the given activities.
     *
     * @param course_modinfo $modinfo
     * @param int[] $generatedcmids
     * @param array<string,string> $urlbyuid
     * @param array<string,string> $embedurlbyuid
     */
    private static function resolve_activities(
        course_modinfo $modinfo,
        array $generatedcmids,
        array $urlbyuid,
        array $embedurlbyuid
    ): void {
        foreach ($generatedcmids as $cmid) {
            $cm = $modinfo->get_cm($cmid);
            self::resolve_activity($cm, $urlbyuid, $embedurlbyuid);
        }
    }

    /**
     * Resolve every text of one activity, when its module is searched.
     *
     * @param cm_info $cm
     * @param array<string,string> $urlbyuid
     * @param array<string,string> $embedurlbyuid
     */
    private static function resolve_activity(cm_info $cm, array $urlbyuid, array $embedurlbyuid): void {
        global $DB;

        $texts = module_link_texts_registry::for_module($cm->modname);
        if ($texts === null) {
            return;
        }
        $records = $DB->get_records($texts->table(), [$texts->instance_column() => $cm->instance]);
        self::resolve_records($cm, $texts, $records, $urlbyuid, $embedurlbyuid);
    }

    /**
     * Resolve the text columns of each record.
     *
     * @param cm_info $cm
     * @param module_link_texts $texts
     * @param \stdClass[] $records
     * @param array<string,string> $urlbyuid
     * @param array<string,string> $embedurlbyuid
     */
    private static function resolve_records(
        cm_info $cm,
        module_link_texts $texts,
        array $records,
        array $urlbyuid,
        array $embedurlbyuid
    ): void {
        foreach ($records as $record) {
            self::resolve_columns($cm, $texts, $record, $urlbyuid, $embedurlbyuid);
        }
    }

    /**
     * Resolve each text column of one record.
     *
     * @param cm_info $cm
     * @param module_link_texts $texts
     * @param \stdClass $record
     * @param array<string,string> $urlbyuid
     * @param array<string,string> $embedurlbyuid
     */
    private static function resolve_columns(
        cm_info $cm,
        module_link_texts $texts,
        \stdClass $record,
        array $urlbyuid,
        array $embedurlbyuid
    ): void {
        foreach ($texts->text_columns() as $column) {
            self::resolve_column($cm, $texts->table(), $record, $column, $urlbyuid, $embedurlbyuid);
        }
    }

    /**
     * Resolve one text, store it when it changed, and refuse to leave a token in it.
     *
     * @param cm_info $cm
     * @param string $table
     * @param \stdClass $record
     * @param string $column
     * @param array<string,string> $urlbyuid
     * @param array<string,string> $embedurlbyuid
     * @throws \moodle_exception When a token remains after resolving.
     */
    private static function resolve_column(
        cm_info $cm,
        string $table,
        \stdClass $record,
        string $column,
        array $urlbyuid,
        array $embedurlbyuid
    ): void {
        global $DB;

        $original = (string) $record->{$column};
        $resolved = link_token::replace($original, $urlbyuid, $embedurlbyuid);

        $remaininguid = link_token::first_remaining_uid($resolved);
        if ($remaininguid !== null) {
            $details = (object) ['activity' => $cm->name, 'uid' => $remaininguid];
            throw new \moodle_exception('linkunresolved', 'local_coursegen', '', $details);
        }
        if ($resolved === $original) {
            return;
        }
        $DB->set_field($table, $column, $resolved, ['id' => $record->id]);
    }
}

<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Binds the verified template-tool syllabus reference to a server link token.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local\service;

use context_module;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

/**
 * Validates the fixed source pair and inserts a token for the existing resolver.
 * The model never supplies a target URL or a link token.
 */
final class template_tool_link_reference {
    /**
     * Add the deterministic reference token to the one generated fixture page.
     *
     * @param array $result Service result payload.
     * @return array Payload with validated reference and server-generated token.
     */
    public static function prepare(array $result): array {
        $reference = $result['template_tool_resource_reference'] ?? null;
        if ($reference === null) {
            return $result;
        }
        if (!is_array($reference) || ($reference['kind'] ?? '') !== 'source_activity'
                || (int) ($reference['cmid'] ?? 0) !== 11340
                || (int) ($reference['page_cmid'] ?? 0) !== 11342) {
            throw new \moodle_exception('invalidtoolreference', 'local_coursegen');
        }
        $uid = (string) ($reference['uid'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/', $uid)) {
            throw new \moodle_exception('invalidtoolreference', 'local_coursegen');
        }
        $activities = $result['generated_activities'] ?? [];
        $resource = self::find_activity($activities, 11340, $uid);
        $page = self::find_activity($activities, 11342, '');
        if (($resource['resource_type'] ?? '') !== 'resource' || ($page['resource_type'] ?? '') !== 'page') {
            throw new \moodle_exception('invalidtoolreference', 'local_coursegen');
        }
        $parameters = $page['parameters'] ?? [];
        $pagecontent = $parameters['page']['text'] ?? null;
        if (!is_string($pagecontent) || $pagecontent === '' || strpos($pagecontent, '$@COURSEGENLINK*') !== false) {
            throw new \moodle_exception('invalidtoolreference', 'local_coursegen');
        }
        if (preg_match('/https?:\/\/|www\.|\b(?:href|src)\s*=|pluginfile\.php/i', $pagecontent)) {
            throw new \moodle_exception('invalidtoolreference', 'local_coursegen');
        }
        $parameters['page']['text'] .= '\n<iframe src="$@COURSEGENLINK*' . $uid . '@$" title="Syllabus"></iframe>';
        $page['parameters'] = $parameters;
        foreach ($activities as $index => $activity) {
            if ((int) ($activity['cmid'] ?? 0) === 11342) {
                $activities[$index] = $page;
                break;
            }
        }
        $result['generated_activities'] = $activities;
        return $result;
    }

    /**
     * Resolve the fixed resource reference to the copied PDF file URL.
     *
     * @param int $courseid New course id.
     * @param array|null $reference Typed reference from the service result.
     * @param array $generatedcms Source cmid => new cmid for AI-written activities.
     * @param array $keptcms Source cmid => new cmid for copied activities.
     */
    public static function resolve_file_url(int $courseid, ?array $reference, array $generatedcms, array $keptcms): void {
        global $DB;
        if ($reference === null) {
            return;
        }
        if ((int) ($reference['cmid'] ?? 0) !== 11340 || (int) ($reference['page_cmid'] ?? 0) !== 11342) {
            throw new \moodle_exception('invalidtoolreference', 'local_coursegen');
        }
        $newresourcecmid = (int) ($generatedcms[11340] ?? $keptcms[11340] ?? 0);
        $newpagecmid = (int) ($generatedcms[11342] ?? 0);
        if ($courseid <= 0 || $newresourcecmid <= 0 || $newpagecmid <= 0) {
            throw new \moodle_exception('invalidtoolreference', 'local_coursegen');
        }
        $modinfo = get_fast_modinfo($courseid);
        $resourcecm = $modinfo->get_cm($newresourcecmid);
        $pagecm = $modinfo->get_cm($newpagecmid);
        if ($resourcecm->modname !== 'resource' || $pagecm->modname !== 'page') {
            throw new \moodle_exception('invalidtoolreference', 'local_coursegen');
        }
        $fileurl = self::copied_pdf_url($newresourcecmid);
        $oldurl = $resourcecm->get_url();
        if ($oldurl === null) {
            throw new \moodle_exception('invalidtoolreference', 'local_coursegen');
        }
        $pagerecord = $DB->get_record('page', ['id' => $pagecm->instance], '*', MUST_EXIST);
        $pattern = '/src=(["\'])' . preg_quote(s($oldurl->out(false)), '/') . '\\1/i';
        if (preg_match_all($pattern, (string) $pagerecord->content) !== 1) {
            throw new \moodle_exception('invalidtoolreference', 'local_coursegen');
        }
        $pagerecord->content = preg_replace_callback($pattern, static function(array $matches) use ($fileurl): string {
            return 'src=' . $matches[1] . s($fileurl) . $matches[1];
        }, $pagerecord->content);
        $DB->update_record('page', $pagerecord);
    }

    /**
     * The direct inline URL of the exact copied syllabus PDF.
     *
     * @param int $resourcecmid Destination resource course-module id.
     * @return string
     */
    private static function copied_pdf_url(int $resourcecmid): string {
        $context = context_module::instance($resourcecmid);
        $files = get_file_storage()->get_area_files(
            $context->id,
            'mod_resource',
            'content',
            0,
            'sortorder',
            false
        );
        foreach ($files as $file) {
            if ($file->get_filename() === 'GD Redacción para Medios I.pdf') {
                return moodle_url::make_pluginfile_url(
                    $context->id,
                    'mod_resource',
                    'content',
                    0,
                    $file->get_filepath(),
                    $file->get_filename()
                )->out(false);
            }
        }
        throw new \moodle_exception('invalidtoolreference', 'local_coursegen');
    }

    /**
     * Find an activity by the immutable source cmid and, where known, uid.
     *
     * @param array $activities
     * @param int $cmid
     * @param string $uid
     * @return array
     */
    private static function find_activity(array $activities, int $cmid, string $uid): array {
        foreach ($activities as $activity) {
            if ((int) ($activity['cmid'] ?? 0) !== $cmid) {
                continue;
            }
            if ($uid !== '' && (string) ($activity['uid'] ?? '') !== $uid) {
                continue;
            }
            return $activity;
        }
        throw new \moodle_exception('invalidtoolreference', 'local_coursegen');
    }
}

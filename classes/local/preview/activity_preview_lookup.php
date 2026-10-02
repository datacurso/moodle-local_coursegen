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

namespace local_coursegen\local\preview;

use local_coursegen\local\models\course_session;
use local_coursegen\local\reference\generated_reference_files;
use local_coursegen\local\reference\reference_file_urls;
use local_coursegen\local\service\template_ai_api_service;

/**
 * Which activity activity_preview.php is being asked for, and what to draw
 * it from: its own entry of the finished result, or the payload's own copy
 * for an activity the run keeps or has not finished.
 *
 * A finished activity carries everything its preview is made of - the tree of
 * rows with what the AI wrote laid in, the files, the scalar columns - so
 * nothing else is read to complete it: not the template's activity in the
 * payload, and not a row matched by title or by position. Kept apart from the
 * page itself, which is everything that happens once this is known.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_preview_lookup {
    /**
     * Find the activity, and what to build its preview from.
     *
     * @param string $uid
     * @param array $payload The full init payload the run was sent.
     * @param course_session $session
     * @return array {modname: string, parameters: array, cmid: int, kept: bool, generated_files: array}
     * @throws \moodle_exception When the activity is not part of the run, or its result cannot be drawn.
     */
    public static function resolve(string $uid, array $payload, course_session $session): array {
        $answer = self::answer_of($session);
        $found = self::from_answer($answer, $uid);
        if ($found !== null) {
            $found['parameters'] = self::with_files($found, $uid, $session);
            return $found;
        }
        $found = self::from_payload($payload, $uid);
        if ($found === null) {
            throw new \moodle_exception('courseai_preview_not_found', 'local_coursegen');
        }
        return $found;
    }

    /**
     * Whether the template keeps this activity as it is.
     *
     * @param array $activity The activity, as the payload or the result describes it.
     * @return bool
     */
    public static function is_kept(array $activity): bool {
        $templatebehavior = $activity['template_behavior'] ?? [];
        $action = $templatebehavior['action'] ?? '';
        return $action === 'keep';
    }

    /**
     * An activity the finished result describes, as its preview is drawn.
     *
     * A kept activity is not taken from the result: the answer only echoes
     * it, and the payload holds it whole. A written one must be current, or it
     * is refused rather than completed from somewhere else.
     *
     * @param array $answer The result of the run; empty while it has none.
     * @param string $uid
     * @return array|null {modname: string, parameters: array, cmid: int, kept: bool, generated_files: array};
     *                    null when the result has no such activity to draw.
     * @throws \moodle_exception When the activity predates record ids or a record names an unknown row.
     */
    public static function from_answer(array $answer, string $uid): ?array {
        $activities = $answer['generated_activities'] ?? [];
        $activity = self::listed($activities, $uid);
        if ($activity === null || self::is_kept($activity)) {
            return null;
        }
        result_activity_check::assert_current($activity);

        $modname = $activity['resource_type'] ?? '';
        $templatebehavior = $activity['template_behavior'] ?? [];
        $sourcecmid = $templatebehavior['template_source_cmid'] ?? 0;
        $parameters = $activity['parameters'] ?? [];
        $generatedfiles = $activity['generated_files'] ?? [];
        return [
            'modname' => (string) $modname,
            'parameters' => (array) $parameters,
            'cmid' => (int) $sourcecmid,
            'kept' => false,
            'generated_files' => (array) $generatedfiles,
        ];
    }

    /**
     * The payload's own copy of one activity, for an activity the run keeps or has not finished.
     *
     * Its parameters hold the same tree a finished activity's do. The payload
     * describes every activity of the template completely, and names each one
     * by the same uid.
     *
     * @param array $payload
     * @param string $uid
     * @return array|null {modname: string, parameters: array, cmid: int, kept: bool}
     */
    public static function from_payload(array $payload, string $uid): ?array {
        $activities = $payload['activities'] ?? [];
        $activity = self::listed($activities, $uid);
        if ($activity === null) {
            return null;
        }
        $modname = $activity['resource_type'] ?? '';
        $modname = (string) $modname;
        $parameters = $activity['parameters'] ?? [];
        $cmid = $activity['cmid'] ?? 0;
        return [
            'modname' => $modname,
            'parameters' => self::with_description($modname, (array) $parameters),
            'cmid' => (int) $cmid,
            'kept' => self::is_kept($activity),
            'generated_files' => [],
        ];
    }

    /**
     * The result of the run, or nothing while it has none.
     *
     * A run under review has no result yet, and asking for one is how that is
     * found out.
     *
     * @param course_session $session
     * @return array
     */
    private static function answer_of(course_session $session): array {
        $api = new template_ai_api_service();
        $threadid = $session->get('session_id');
        try {
            return $api->get_result((string) $threadid);
        } catch (\moodle_exception $exception) {
            return [];
        }
    }

    /**
     * The entry of a list of activities that carries the uid.
     *
     * @param array $activities
     * @param string $uid
     * @return array|null
     */
    private static function listed(array $activities, string $uid): ?array {
        foreach ($activities as $activity) {
            $activityuid = $activity['uid'] ?? '';
            if ((string) $activityuid === $uid) {
                return $activity;
            }
        }
        return null;
    }

    /**
     * The parameters of a type without a preview of its own, with the description its tree holds.
     *
     * Such a type shows its description only, and a payload copy keeps that
     * in its module row.
     *
     * @param string $modname
     * @param array $parameters
     * @return array
     */
    private static function with_description(string $modname, array $parameters): array {
        if (preview_factory::has_own_preview($modname)) {
            return $parameters;
        }
        $structure = $parameters['structure'] ?? [];
        $modstructure = $structure[$modname] ?? [];
        $root = $modstructure[0] ?? [];
        $intro = $root['intro'] ?? '';
        $intro = (string) $intro;
        $parameters['introeditor'] = ['text' => $intro, 'format' => FORMAT_HTML, 'itemid' => 0];
        return $parameters;
    }

    /**
     * The parameters of a finished activity with its files in place of their placeholders and tokens.
     *
     * The files the AI made are addressed where they are served from, and a
     * reference has the teacher's file: the preview never shows a token.
     *
     * @param array $found What from_answer() found.
     * @param string $uid
     * @param course_session $session
     * @return array
     */
    private static function with_files(array $found, string $uid, course_session $session): array {
        $addressed = new generated_file_preview();
        $parameters = $addressed->addressed($found['parameters'], $found['generated_files']);

        $urlbyslot = reference_file_urls::for_course_session($session);
        $activity = ['uid' => $uid, 'parameters' => $parameters];
        $withreferences = generated_reference_files::apply_to_activity($activity, $urlbyslot);
        return $withreferences['parameters'];
    }
}

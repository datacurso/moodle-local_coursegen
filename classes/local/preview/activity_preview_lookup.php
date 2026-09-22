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
use local_coursegen\local\service\template_ai_api_service;

/**
 * Which activity activity_preview.php is being asked for, and what to draw
 * it from: the finished result when there is one, the plan's draft while
 * there is not, or the payload's own copy for an activity the run keeps
 * rather than writes. Kept apart from the page itself, which is everything
 * that happens once this is known.
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
     * @return array {modname: string, parameters: array, source: array}
     */
    public static function resolve(string $uid, array $payload, course_session $session): array {
        $activitybycmid = static function (int $cmid) use ($payload): array {
            foreach (($payload['activities'] ?? []) as $activity) {
                if ((int) ($activity['cmid'] ?? 0) === $cmid) {
                    return $activity;
                }
            }
            return [];
        };

        $api = new template_ai_api_service();
        $threadid = (string) $session->get('session_id');

        $found = self::from_result($api, $threadid, $uid, $activitybycmid);
        if (!$found['parameters']) {
            $found = self::from_plan($api, $threadid, $uid, $session, $activitybycmid);
        }
        if (!$found['parameters']) {
            $found = self::from_payload($payload, $uid);
        }
        if (!$found['parameters']) {
            throw new \moodle_exception('courseai_preview_not_found', 'local_coursegen');
        }
        return $found;
    }

    /**
     * The finished activity when there is one. A run under review has no
     * result yet, and asking for one is how that is found out.
     *
     * @param template_ai_api_service $api
     * @param string $threadid
     * @param string $uid
     * @param callable $activitybycmid
     * @return array {modname: string, parameters: array, source: array}
     */
    private static function from_result(
        template_ai_api_service $api,
        string $threadid,
        string $uid,
        callable $activitybycmid
    ): array {
        try {
            foreach (($api->get_result($threadid)['generated_activities'] ?? []) as $activity) {
                if ((string) ($activity['uid'] ?? '') === $uid) {
                    return [
                        'modname' => (string) ($activity['resource_type'] ?? ''),
                        'parameters' => (array) ($activity['parameters'] ?? []),
                        'source' => $activitybycmid(
                            (int) (($activity['template_behavior'] ?? [])['template_source_cmid'] ?? 0)
                        ),
                    ];
                }
            }
        } catch (\moodle_exception $exception) {
            // No result yet - fall through to the plan.
        }
        return ['modname' => '', 'parameters' => [], 'source' => []];
    }

    /**
     * The plan's draft, laid over the mould it is built into. A run the
     * service no longer knows, or one that never reached it, has no plan to
     * ask for either; its kept activities are still in the payload.
     *
     * @param template_ai_api_service $api
     * @param string $threadid
     * @param string $uid
     * @param course_session $session
     * @param callable $activitybycmid
     * @return array {modname: string, parameters: array, source: array}
     */
    private static function from_plan(
        template_ai_api_service $api,
        string $threadid,
        string $uid,
        course_session $session,
        callable $activitybycmid
    ): array {
        try {
            $plan = $api->get_plan($threadid)['template_plan'] ?? [];
        } catch (\moodle_exception $exception) {
            $plan = [];
        }
        foreach ($plan as $entry) {
            if ((string) ($entry['uid'] ?? '') !== $uid) {
                continue;
            }
            $modname = (string) ($entry['resource_type'] ?? '');
            $parameters = plan_activity::to_parameters((array) $entry);
            // A plan describes the pieces the mould offered to fill, and a
            // mould also holds pieces it offers to nobody, which carry through
            // to the delivered activity as they are. So the mould is what is
            // shown, with the plan laid over it.
            $parameters = plan_activity::over_mould(
                $parameters,
                $modname,
                (int) ($entry['source_cmid'] ?? 0),
                $session
            );
            return [
                'modname' => $modname,
                'parameters' => $parameters,
                'source' => $activitybycmid((int) ($entry['source_cmid'] ?? 0)),
            ];
        }
        return ['modname' => '', 'parameters' => [], 'source' => []];
    }

    /**
     * The payload's own copy, for an activity the run keeps rather than
     * writes: it is not in the answer at all, so it is read from what was
     * sent. The payload describes every activity of the template completely,
     * and names each one by the same uid.
     *
     * @param array $payload
     * @param string $uid
     * @return array {modname: string, parameters: array, source: array}
     */
    private static function from_payload(array $payload, string $uid): array {
        foreach (($payload['activities'] ?? []) as $activity) {
            if ((string) ($activity['uid'] ?? '') === $uid) {
                return [
                    'modname' => (string) ($activity['resource_type'] ?? ''),
                    'parameters' => real_activity::to_parameters($activity),
                    'source' => $activity,
                ];
            }
        }
        return ['modname' => '', 'parameters' => [], 'source' => []];
    }
}

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
        $api = new template_ai_api_service();
        $threadid = $session->get('session_id');
        $threadid = (string) $threadid;

        $found = self::from_result($api, $threadid, $uid, $payload);
        if (!$found['parameters']) {
            $found = self::from_plan($api, $threadid, $uid, $session, $payload);
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
     * The payload's own copy of one activity, by its course module id.
     *
     * @param array $payload
     * @param int $cmid
     * @return array
     */
    private static function activity_by_cmid(array $payload, int $cmid): array {
        $activities = $payload['activities'] ?? [];
        foreach ($activities as $activity) {
            $activitycmid = $activity['cmid'] ?? 0;
            $activitycmid = (int) $activitycmid;
            if ($activitycmid === $cmid) {
                return $activity;
            }
        }
        return [];
    }

    /**
     * The finished activity when there is one. A run under review has no
     * result yet, and asking for one is how that is found out.
     *
     * @param template_ai_api_service $api
     * @param string $threadid
     * @param string $uid
     * @param array $payload
     * @return array {modname: string, parameters: array, source: array}
     */
    private static function from_result(
        template_ai_api_service $api,
        string $threadid,
        string $uid,
        array $payload
    ): array {
        try {
            $result = $api->get_result($threadid);
            $activities = $result['generated_activities'] ?? [];
            foreach ($activities as $activity) {
                $activityuid = $activity['uid'] ?? '';
                $activityuid = (string) $activityuid;
                if ($activityuid !== $uid) {
                    continue;
                }
                $modname = $activity['resource_type'] ?? '';
                $modname = (string) $modname;

                $parameters = $activity['parameters'] ?? [];
                $parameters = (array) $parameters;

                $templatebehavior = $activity['template_behavior'] ?? [];
                $sourcecmid = $templatebehavior['template_source_cmid'] ?? 0;
                $sourcecmid = (int) $sourcecmid;
                $source = self::activity_by_cmid($payload, $sourcecmid);

                return [
                    'modname' => $modname,
                    'parameters' => $parameters,
                    'source' => $source,
                ];
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
     * @param array $payload
     * @return array {modname: string, parameters: array, source: array}
     */
    private static function from_plan(
        template_ai_api_service $api,
        string $threadid,
        string $uid,
        course_session $session,
        array $payload
    ): array {
        try {
            $planresult = $api->get_plan($threadid);
            $plan = $planresult['template_plan'] ?? [];
        } catch (\moodle_exception $exception) {
            $plan = [];
        }
        foreach ($plan as $entry) {
            $entryuid = $entry['uid'] ?? '';
            $entryuid = (string) $entryuid;
            if ($entryuid !== $uid) {
                continue;
            }
            $modname = $entry['resource_type'] ?? '';
            $modname = (string) $modname;

            $sourcecmid = $entry['source_cmid'] ?? 0;
            $sourcecmid = (int) $sourcecmid;

            $parameters = plan_activity::to_parameters((array) $entry);
            // A plan describes the pieces the mould offered to fill, and a
            // mould also holds pieces it offers to nobody, which carry through
            // to the delivered activity as they are. So the mould is what is
            // shown, with the plan laid over it.
            $parameters = plan_activity::over_mould($parameters, $modname, $sourcecmid, $session);

            $source = self::activity_by_cmid($payload, $sourcecmid);
            return [
                'modname' => $modname,
                'parameters' => $parameters,
                'source' => $source,
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
        $activities = $payload['activities'] ?? [];
        foreach ($activities as $activity) {
            $activityuid = $activity['uid'] ?? '';
            $activityuid = (string) $activityuid;
            if ($activityuid !== $uid) {
                continue;
            }
            $modname = $activity['resource_type'] ?? '';
            $modname = (string) $modname;
            $parameters = kept_activity::to_parameters($activity);
            return [
                'modname' => $modname,
                'parameters' => $parameters,
                'source' => $activity,
            ];
        }
        return ['modname' => '', 'parameters' => [], 'source' => []];
    }
}

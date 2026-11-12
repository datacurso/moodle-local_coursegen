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

use aiprovider_datacurso\httpclient\ai_course_api;
use local_coursegen\local\api_client_factory;
use local_coursegen\local\tenant_config;

/**
 * Asks the Datacurso course service to export or delete what it keeps of a person or a course.
 *
 * The service keeps, per planning session or activity generation (a thread), the prompt, the
 * conversation, the generated content and the files made for it, for at most its retention period
 * (30 days by default). The plugin knows the id of every thread it opened: the session_id of the
 * planning sessions and the job_id of the module jobs. A deletion names those ids and, for a person,
 * the person too, so threads the plugin no longer holds a row for are deleted as well.
 *
 * The service only touches the threads of this plugin (it identifies itself on every call) on this
 * site. Mindfree, LCG-PRIV-001.
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class remote_data_service {
    /** @var string Route that exports the threads. */
    public const EXPORT_PATH = '/privacy/export';

    /** @var string Route that deletes the threads. */
    public const DELETE_PATH = '/privacy/delete';

    /** @var string userid sent when the request is about threads, not a person. */
    private const NO_USER = '';

    /** @var ai_course_api Datacurso course API client. */
    private ai_course_api $client;

    /**
     * Constructor.
     *
     * In Moodle Workplace each tenant may point at its own course service (tenant_config), so the
     * client is built with the service URLs of the tenant the data belongs to.
     *
     * @param ai_course_api|null $client Pre-built client (tests), or null to build it from the configuration.
     * @param int|null $tenantid Tenant whose service URLs are used, or null for the current user's tenant.
     */
    public function __construct(?ai_course_api $client = null, ?int $tenantid = null) {
        if ($client !== null) {
            $this->client = $client;
            return;
        }
        $baseurl = tenant_config::get('datacurso_service_url', null, $tenantid) ?: null;
        $baseurleu = tenant_config::get('datacurso_service_url_eu', null, $tenantid) ?: null;
        $this->client = api_client_factory::ai_course_api($baseurl, $baseurleu);
    }

    /**
     * Ids of the threads the plugin holds rows for.
     *
     * @param array $conditions Conditions on the session and job tables (courseid, userid).
     * @return string[]
     */
    public static function thread_ids(array $conditions): array {
        global $DB;

        $ids = array_merge(
            $DB->get_fieldset_select(
                'local_coursegen_course_sessions',
                'session_id',
                self::where($conditions),
                $conditions
            ),
            $DB->get_fieldset_select(
                'local_coursegen_module_jobs',
                'job_id',
                self::where($conditions),
                $conditions
            )
        );
        $ids = array_filter(array_map('strval', $ids), fn(string $id): bool => $id !== '');
        return array_values(array_unique($ids));
    }

    /**
     * Everything the service keeps of a person's threads.
     *
     * @param int $userid
     * @return array[] One entry per thread: settings, conversation, plan, result.
     */
    public function export_user(int $userid): array {
        $response = $this->client->request('POST', self::EXPORT_PATH, [
            'userid' => (string)$userid,
            'thread_ids' => self::thread_ids(['userid' => $userid]),
        ]);
        return is_array($response['threads'] ?? null) ? $response['threads'] : [];
    }

    /**
     * Delete threads at the service: those of a person, the listed ones, or both.
     *
     * @param int|null $userid The person whose threads go, or null for the listed threads only.
     * @param string[] $threadids
     * @return int Number of threads the service deleted.
     */
    public function erase(?int $userid, array $threadids): int {
        if ($userid === null && $threadids === []) {
            return 0;
        }
        $response = $this->client->request('POST', self::DELETE_PATH, [
            'userid' => $userid === null ? self::NO_USER : (string)$userid,
            'thread_ids' => array_values($threadids),
        ]);
        if (!is_array($response) || !array_key_exists('deleted', $response)) {
            throw new \moodle_exception('error_remote_erase', 'local_coursegen');
        }
        return (int)$response['deleted'];
    }

    /**
     * SQL condition matching the given fields.
     *
     * @param array $conditions
     * @return string
     */
    private static function where(array $conditions): string {
        $parts = [];
        foreach (array_keys($conditions) as $field) {
            $parts[] = "$field = :$field";
        }
        return $parts === [] ? '1 = 1' : implode(' AND ', $parts);
    }
}

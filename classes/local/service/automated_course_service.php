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
use local_coursegen\ai_context;
use local_coursegen\ai_course;

/**
 * Backend automation service for prompt-based course creation.
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class automated_course_service {
    /**
     * Create a full course automatically using prompt context.
     *
     * @param array $payload
     * @param callable|null $progresscallback Optional progress callback.
     * @return array
     */
    public function create_prompt_course(array $payload, ?callable $progresscallback = null): array {
        global $DB, $CFG;

        $quickmode = !empty($payload['_quick_mode']);
        unset($payload['_quick_mode']);

        $coursename = trim((string)($payload['course_name'] ?? ''));
        $prompt = trim((string)($payload['prompt_message'] ?? ''));
        $lang = trim((string)($payload['lang'] ?? ''));
        $generateimages = !empty($payload['generate_images']);
        $userid = (int)($payload['userid'] ?? 0);

        if ($coursename === '' || $prompt === '') {
            throw new \coding_exception('Course name and prompt are required for automated course generation.');
        }

        $course = $this->create_empty_course($coursename);
        $courseid = (int)$course->id;

        try {
            $this->notify_progress($progresscallback, 'course_created', 5);

            ai_context::save_course_context(
                $courseid,
                ai_context::CONTEXT_TYPE_CUSTOM_PROMPT,
                null,
                $lang === '' ? null : $lang,
                $prompt
            );

            ai_course::start_course_planning(
                $courseid,
                ai_context::CONTEXT_TYPE_CUSTOM_PROMPT,
                null,
                $coursename,
                $prompt,
                $generateimages ? 1 : 0,
                $lang === '' ? null : $lang
            );
            $this->notify_progress($progresscallback, 'planning', 20);

            $streamattempts = $quickmode ? 1 : 2;
            $streamtimeout = $quickmode ? 45 : 180;
            $this->consume_planning_stream((string)$courseid, $lang, $userid, $streamattempts, $streamtimeout);
            $this->notify_progress($progresscallback, 'planning', 40);

            $session = ai_course::get_course_session($courseid);
            if (!$session || empty($session->session_id)) {
                throw new \moodle_exception('error_no_session_found', 'local_coursegen', '', $courseid);
            }

            if ($userid > 0) {
                $DB->set_field('local_coursegen_course_sessions', 'userid', $userid, ['id' => (int)$session->id]);
            }

            ai_course::update_session_status((int)$session->id, 2);
            $this->notify_progress($progresscallback, 'executing', 45);
            $executeretries = $quickmode ? 2 : 4;
            $this->execute_remote_plan((string)$session->session_id, $lang, $userid, $executeretries);
            $this->notify_progress($progresscallback, 'waiting_result', 70);
            $resultattempts = $quickmode ? 40 : 120;
            $resultsleepseconds = $quickmode ? 1 : 2;
            $resultdata = $this->wait_for_result(
                (string)$session->session_id,
                $lang,
                $userid,
                $resultattempts,
                $resultsleepseconds,
                $progresscallback
            );

            $this->notify_progress($progresscallback, 'applying', 90);
            course_result_service::apply_result($courseid, $resultdata);
            ai_course::update_session_status((int)$session->id, 3);
            $this->notify_progress($progresscallback, 'completed', 100);

            $isenrolled = false;
            if ($userid > 0) {
                $isenrolled = $this->enrol_user_in_course($courseid, $userid);
            }

            return [
                'status' => 'completed',
                'courseid' => $courseid,
                'courseurl' => course_get_url($courseid)->out(false),
                'session_id' => (string)$session->session_id,
                'id' => $courseid,
                'isenrolled' => $isenrolled,
                'message' => get_string('coursecreated', 'local_coursegen'),
            ];
        } catch (\Throwable $e) {
            $session = ai_course::get_course_session($courseid);
            if ($session) {
                ai_course::update_session_status((int)$session->id, 4);
            }

            require_once($CFG->dirroot . '/course/lib.php');
            $failedcourse = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING);
            if ($failedcourse) {
                delete_course($failedcourse, false);
            }

            $this->notify_progress($progresscallback, 'failed', 0, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Send progress update if callback is available.
     *
     * @param callable|null $progresscallback
     * @param string $status
     * @param int $percent
     * @param string $message
     * @return void
     */
    private function notify_progress(?callable $progresscallback, string $status, int $percent, string $message = ''): void {
        if (!$progresscallback) {
            return;
        }
        $progresscallback([
            'status' => $status,
            'progresspercent' => max(0, min(100, $percent)),
            'message' => $message,
        ]);
    }

    /**
     * Create a base Moodle course where AI content will be applied.
     *
     * @param string $coursename
     * @return \stdClass
     */
    private function create_empty_course(string $coursename): \stdClass {
        global $CFG;

        require_once($CFG->dirroot . '/course/lib.php');

        $record = new \stdClass();
        $record->fullname = $coursename;
        $record->shortname = $this->generate_unique_shortname($coursename);
        $record->category = (int)\core_course_category::get_default()->id;
        $record->format = 'topics';
        $record->numsections = 0;
        $record->visible = 1;
        if (!empty($CFG->enablecompletion)) {
            $record->enablecompletion = defined('COMPLETION_ENABLED') ? COMPLETION_ENABLED : 1;
            $record->showcompletionconditions = defined('COMPLETION_SHOW_CONDITIONS') ? COMPLETION_SHOW_CONDITIONS : 1;
        }
        return create_course($record);
    }

    /**
     * Enrol user in created course using internal enrolment.
     *
     * @param int $courseid
     * @param int $userid
     * @return bool
     */
    private function enrol_user_in_course(int $courseid, int $userid): bool {
        global $CFG, $DB;

        require_once($CFG->libdir . '/enrollib.php');

        if (is_enrolled(\context_course::instance($courseid), $userid, '', true)) {
            return true;
        }

        $studentroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
        if ($studentroleid <= 0) {
            $studentroleid = null;
        }

        return (bool)enrol_try_internal_enrol($courseid, $userid, $studentroleid);
    }

    /**
     * Generate an available shortname from course name.
     *
     * @param string $coursename
     * @return string
     */
    private function generate_unique_shortname(string $coursename): string {
        global $DB;

        $base = preg_replace('/[^a-zA-Z0-9]+/', '_', \core_text::strtolower($coursename));
        $base = trim((string)$base, '_');
        if ($base === '') {
            $base = 'ai_course';
        }

        $candidate = substr($base, 0, 80);
        if (!$DB->record_exists('course', ['shortname' => $candidate])) {
            return $candidate;
        }

        $suffix = 1;
        do {
            $withsuffix = substr($base, 0, 70) . '_' . $suffix;
            $suffix++;
        } while ($DB->record_exists('course', ['shortname' => $withsuffix]));

        return $withsuffix;
    }

    /**
     * Trigger execution stage for an existing planning session.
     *
     * @param string $sessionid
     * @param string $lang
     * @param int $userid User id for backend attribution.
     * @param int $maxretries Maximum execute retries.
     * @return void
     */
    private function execute_remote_plan(string $sessionid, string $lang, int $userid, int $maxretries = 4): void {
        $baseurl = get_config('local_coursegen', 'datacurso_service_url') ?: null;
        $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu') ?: null;
        $client = new ai_course_api(null, $baseurl, $baseurleu);

        $requestdata = ['session_id' => $sessionid];
        if ($lang !== '') {
            $requestdata['lang'] = $lang;
        }
        if ($userid > 0) {
            $requestdata['userid'] = (string)$userid;
        }

        $lastmessage = '';
        for ($attempt = 1; $attempt <= max(1, $maxretries); $attempt++) {
            try {
                $client->request('POST', '/course/execute', $requestdata);
                return;
            } catch (\Throwable $e) {
                $lastmessage = $e->getMessage();
                if ($attempt < max(1, $maxretries)) {
                    sleep(2);
                    continue;
                }
            }
        }

        throw new \moodle_exception('error', 'local_coursegen', '', 'Course execute failed after retries: ' . $lastmessage);
    }

    /**
     * Consume planning stream so the AI service completes preplanning stage.
     *
     * @param string $courseid
     * @param string $lang
     * @param int $userid User id for backend attribution.
     * @param int $maxattempts Maximum stream attempts.
     * @param int $timeoutseconds Request timeout in seconds.
     * @return void
     */
    private function consume_planning_stream(
        string $courseid,
        string $lang,
        int $userid,
        int $maxattempts = 2,
        int $timeoutseconds = 180
    ): void {
        $session = ai_course::get_course_session((int)$courseid);
        if (!$session || empty($session->session_id)) {
            throw new \moodle_exception('error_no_session_found', 'local_coursegen', '', (int)$courseid);
        }

        $baseurl = get_config('local_coursegen', 'datacurso_service_url') ?: null;
        $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu') ?: null;
        $client = new ai_course_api(null, $baseurl, $baseurleu);
        $streamurl = $client->get_streaming_url_for_session((string)$session->session_id);
        if ($lang !== '') {
            $separator = strpos($streamurl, '?') === false ? '?' : '&';
            $streamurl .= $separator . 'lang=' . urlencode($lang);
        }
        if ($userid > 0) {
            $separator = strpos($streamurl, '?') === false ? '?' : '&';
            $streamurl .= $separator . 'userid=' . urlencode((string)$userid);
        }

        $curl = new \curl();
        $attempts = max(1, $maxattempts);
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = $curl->get($streamurl, [], [
                'CURLOPT_TIMEOUT' => max(15, $timeoutseconds),
                'CURLOPT_CONNECTTIMEOUT' => 30,
            ]);

            if (!is_string($response) || trim($response) === '') {
                if ($attempt < $attempts) {
                    sleep(2);
                    continue;
                }
                debugging(
                    'local_coursegen: planning stream returned empty response, continuing with execute retries.',
                    DEBUG_DEVELOPER
                );
                return;
            }

            if (strpos($response, 'assistant_message_end') !== false) {
                return;
            }

            if ($attempt < $attempts) {
                sleep(2);
                continue;
            }

            debugging(
                'local_coursegen: planning stream did not include assistant_message_end, continuing with execute retries.',
                DEBUG_DEVELOPER
            );
            return;
        }
    }

    /**
     * Poll result endpoint until completed.
     *
     * @param string $sessionid
     * @param string $lang
     * @param int $userid User id for backend attribution.
     * @param int $maxattempts Maximum polling attempts.
     * @param int $sleepseconds Delay between polls.
     * @param callable|null $progresscallback Optional progress callback.
     * @return array
     */
    private function wait_for_result(
        string $sessionid,
        string $lang,
        int $userid,
        int $maxattempts = 120,
        int $sleepseconds = 2,
        ?callable $progresscallback = null
    ): array {
        $baseurl = get_config('local_coursegen', 'datacurso_service_url') ?: null;
        $baseurleu = get_config('local_coursegen', 'datacurso_service_url_eu') ?: null;
        $client = new ai_course_api(null, $baseurl, $baseurleu);

        for ($attempt = 0; $attempt < max(1, $maxattempts); $attempt++) {
            $endpoint = '/course/result?session_id=' . urlencode($sessionid);
            if ($lang !== '') {
                $endpoint .= '&lang=' . urlencode($lang);
            }
            if ($userid > 0) {
                $endpoint .= '&userid=' . urlencode((string)$userid);
            }
            $response = (array)$client->request('GET', $endpoint);
            $status = trim((string)($response['status'] ?? ''));
            $message = trim((string)($response['message'] ?? ''));
            $progressmessage = $message;
            if ($progressmessage === '') {
                $progressmessage = 'Waiting result (attempt ' . ($attempt + 1) . '/' . max(1, $maxattempts)
                    . ', remote status: ' . ($status === '' ? 'unknown' : $status) . ')';
            }
            $this->notify_progress($progresscallback, 'waiting_result', 70, $progressmessage);

            if ($status === 'completed') {
                return (array)($response['result'] ?? []);
            }

            if ($status === 'failed' || $status === 'error') {
                $errormessage = trim((string)($response['message'] ?? 'Course generation failed.'));
                throw new \moodle_exception('error', 'local_coursegen', '', $errormessage);
            }

            sleep(max(1, $sleepseconds));
        }

        throw new \moodle_exception('error', 'local_coursegen', '', 'Timed out waiting for course result completion.');
    }
}

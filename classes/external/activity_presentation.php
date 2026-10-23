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
namespace local_coursegen\external;

use context_course;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_coursegen\local\service\ai_course_api_service;
use local_coursegen\local\service\module_job_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * The editor of a Course Presentation: write the slides, write one again, and build the package from the edits.
 *
 * Each step works on the activity generation job the chat opened, and returns what the AI service answered as JSON
 * text, because the slides are free-form data the editor reads and writes as they are.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_presentation extends external_api {
    /** @var string[] The parts of a slide the AI can write again on its own. */
    private const PARTS = ['title', 'body', 'question', 'image', 'video'];

    /**
     * Parameters of draft().
     *
     * @return external_function_parameters
     */
    public static function draft_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id where the activity is being generated'),
            'jobid' => new external_value(PARAM_TEXT, 'Activity generation job/thread id'),
            'instructions' => new external_value(PARAM_RAW, 'What the presentation is about; empty to use the one of the job'),
        ]);
    }

    /**
     * Ask the AI service to write the slides of the presentation.
     *
     * @param int $courseid
     * @param string $jobid
     * @param string $instructions
     * @return array
     */
    public static function draft(int $courseid, string $jobid, string $instructions): array {
        $params = self::validate_parameters(self::draft_parameters(), [
            'courseid' => $courseid,
            'jobid' => $jobid,
            'instructions' => $instructions,
        ]);
        $threadid = self::thread_of($params['courseid'], $params['jobid']);

        return self::answer(static function (ai_course_api_service $service) use ($threadid, $params): array {
            return $service->presentation_draft($threadid, $params['instructions']);
        });
    }

    /**
     * Returns of draft().
     *
     * @return external_single_structure
     */
    public static function draft_returns(): external_single_structure {
        return self::returns();
    }

    /**
     * Parameters of slide().
     *
     * @return external_function_parameters
     */
    public static function slide_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id where the activity is being generated'),
            'jobid' => new external_value(PARAM_TEXT, 'Activity generation job/thread id'),
            'slides' => new external_value(PARAM_RAW, 'The slides as they are now, as JSON'),
            'index' => new external_value(PARAM_INT, 'The slide to write again, 0 is the first'),
            'instruction' => new external_value(PARAM_RAW, 'What to change'),
            'part' => new external_value(PARAM_ALPHA, 'The part to change, or empty for any', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Ask the AI service to write one slide, or one of its parts, again.
     *
     * @param int $courseid
     * @param string $jobid
     * @param string $slides
     * @param int $index
     * @param string $instruction
     * @param string $part
     * @return array
     */
    public static function slide(
        int $courseid,
        string $jobid,
        string $slides,
        int $index,
        string $instruction,
        string $part = ''
    ): array {
        $params = self::validate_parameters(self::slide_parameters(), [
            'courseid' => $courseid,
            'jobid' => $jobid,
            'slides' => $slides,
            'index' => $index,
            'instruction' => $instruction,
            'part' => $part,
        ]);
        $threadid = self::thread_of($params['courseid'], $params['jobid']);
        $list = self::json_list($params['slides']);
        if ($params['part'] !== '' && !in_array($params['part'], self::PARTS, true)) {
            throw new \moodle_exception('error_invalid_slide_part', 'local_coursegen');
        }

        return self::answer(static function (ai_course_api_service $service) use ($threadid, $params, $list): array {
            return $service->presentation_slide(
                $threadid,
                $list,
                $params['index'],
                $params['instruction'],
                $params['part'] === '' ? null : $params['part']
            );
        });
    }

    /**
     * Returns of slide().
     *
     * @return external_single_structure
     */
    public static function slide_returns(): external_single_structure {
        return self::returns();
    }

    /**
     * Parameters of build().
     *
     * @return external_function_parameters
     */
    public static function build_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id where the activity is being generated'),
            'jobid' => new external_value(PARAM_TEXT, 'Activity generation job/thread id'),
            'name' => new external_value(PARAM_TEXT, 'Name of the activity'),
            'intro' => new external_value(PARAM_RAW, 'Description of the activity'),
            'slides' => new external_value(PARAM_RAW, 'The slides as the teacher left them, as JSON'),
        ]);
    }

    /**
     * Ask the AI service to make the package from the slides as they were left.
     *
     * @param int $courseid
     * @param string $jobid
     * @param string $name
     * @param string $intro
     * @param string $slides
     * @return array
     */
    public static function build(int $courseid, string $jobid, string $name, string $intro, string $slides): array {
        $params = self::validate_parameters(self::build_parameters(), [
            'courseid' => $courseid,
            'jobid' => $jobid,
            'name' => $name,
            'intro' => $intro,
            'slides' => $slides,
        ]);
        $threadid = self::thread_of($params['courseid'], $params['jobid']);
        $list = self::json_list($params['slides']);

        return self::answer(static function (ai_course_api_service $service) use ($threadid, $params, $list): array {
            return $service->presentation_build($threadid, $params['name'], $params['intro'], $list);
        });
    }

    /**
     * Returns of build().
     *
     * @return external_single_structure
     */
    public static function build_returns(): external_single_structure {
        return self::returns();
    }

    /**
     * The thread of a job of the user, after the same checks as the rest of the generation.
     *
     * @param int $courseid
     * @param string $jobid
     * @return string
     */
    private static function thread_of(int $courseid, string $jobid): string {
        global $USER;

        $context = context_course::instance($courseid);
        self::validate_context($context);
        require_capability('moodle/course:manageactivities', $context);
        require_capability('local/coursegen:createactivitywithai', $context);

        $job = module_job_service::get_user_job($jobid, $courseid, $USER->id);
        $threadid = $job->get('job_id');
        if (!$threadid) {
            throw new \moodle_exception('error_no_session_found', 'local_coursegen');
        }
        return (string) $threadid;
    }

    /**
     * Decode a list of slides.
     *
     * @param string $json
     * @return array
     */
    private static function json_list(string $json): array {
        $list = json_decode($json, true);
        if (!is_array($list) || !array_is_list($list)) {
            throw new \moodle_exception('error_invalid_slides', 'local_coursegen');
        }
        return $list;
    }

    /**
     * Run a call to the AI service and give its answer as JSON text, or its error as a Moodle one.
     *
     * @param callable $call Receives the service and returns what it answered.
     * @return array
     */
    private static function answer(callable $call): array {
        try {
            $answer = $call(new ai_course_api_service());
        } catch (\moodle_exception $e) {
            throw new \moodle_exception('error_presentation_editor', 'local_coursegen', '', $e->debuginfo ?: $e->getMessage());
        }
        return ['data' => json_encode($answer, JSON_UNESCAPED_UNICODE)];
    }

    /**
     * The structure the three calls return.
     *
     * @return external_single_structure
     */
    private static function returns(): external_single_structure {
        return new external_single_structure([
            'data' => new external_value(PARAM_RAW, 'What the AI service answered, as JSON'),
        ]);
    }
}

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

use local_coursegen\local\models\template;

defined('MOODLE_INTERNAL') || die();

/**
 * Orchestrates "create course from template" through a single-request design:
 * the template's base course is exported once (with each section/activity's
 * saved keep/modify/exclude behavior merged in, see
 * course_export_service::export_course_for_template()), sent whole to the
 * real Datacurso "course template" AI backend, and the AI's own final course
 * result is then materialized exactly like the free-creation flow already
 * does (create_course_service::create_course()).
 *
 * This replaces the previous hybrid approach (core backup/restore for "keep"
 * content plus one mock-AI call per "modify" activity): the AI service itself
 * now decides how to reproduce kept content and regenerate modified content,
 * from the one export payload it receives - Moodle no longer needs to
 * reconstruct that logic locally via backup/restore plumbing.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_course_builder_service {
    /**
     * Create a course from a template.
     *
     * @param template $template Template persistent (already loaded).
     * @param array $newsections New sections added by the professor: [['clientid' => int, 'name' => string], ...].
     *     Folded into the export payload as a top-level 'new_sections' entry (see class docblock);
     *     never dropped, but not created locally - the AI service materializes them as part of its
     *     one result, same as everything else in this design.
     * @param array $newactivities New activities added by the professor:
     *     [['sectionid' => int, 'modname' => string, 'prompt' => string, 'generateimages' => int,
     *     'draftitemid' => int], ...]. sectionid > 0 is a real base-course section id (must be
     *     behavior=custom); sectionid < 0 references a newsections clientid. Folded into the export
     *     payload as a top-level 'new_activities' entry (see class docblock). draftitemid is passed
     *     through as a plain numeric reference only - the referenced file's bytes are not uploaded to
     *     the planning thread in this pass (only the template's own general_reference_files are, via
     *     template_ai_api_service::upload_template_file()); a deliberate simplification, not a silent
     *     drop (see this class's own docblock / the accompanying report for why).
     * @param int $userid User performing the creation (recorded as the owner of the throwaway
     *     course_session this flow still needs, per create_course_service::create_course()'s contract).
     * @return array Result: success, courseid, courseurl, fullname, shortname, message,
     *     partial, haswarnings, warningscount, activityerrors.
     */
    public static function create_course_from_template(
        template $template,
        array $newsections,
        array $newactivities,
        int $userid
    ): array {
        try {
            \core_php_time_limit::raise();
            raise_memory_limit(MEMORY_EXTRA);
            // Release the session so other tabs are not blocked during the
            // (potentially slow) synchronous AI planning + course creation pass.
            \core\session\manager::write_close();

            // 1. Build the one export payload: base course content, each
            // section/activity's saved template behavior, and the template's
            // own general instruction/reference files.
            $export = course_export_service::export_course_for_template($template);

            // 2. Professor-added new sections/activities are not created
            // locally - they are folded into the same payload the AI service
            // plans from, as distinct top-level keys (never silently dropped).
            $export['new_sections'] = $newsections;
            $export['new_activities'] = $newactivities;

            // 3. A throwaway course_session, the same pattern testcoursegen.php
            // uses: create_course_service::create_course() requires a real
            // course_session persistent, even for a flow with no interactive
            // planning session of its own.
            $session = course_session_service::create_from_form_data(
                new \stdClass(),
                $userid,
                'template-' . bin2hex(random_bytes(8))
            );

            // 4. Start the AI planning thread.
            $apiservice = new template_ai_api_service();
            $threadid = $apiservice->start_template_planning($export);

            // 5. Upload every general reference file collected by the export above.
            foreach (course_export_service::get_general_reference_files() as $file) {
                $apiservice->upload_template_file($threadid, $file);
            }

            // 6. Wait for the AI's final course result (bounded polling; see
            // template_ai_api_service::wait_for_template_result()'s own
            // docblock for why polling, not SSE, was chosen here).
            $resultdata = $apiservice->wait_for_template_result($threadid);

            // 7. Materialize the result exactly like the free-creation flow does.
            $result = create_course_service::create_course($session, $resultdata, []);

            // 8. Preserve this method's own external return contract
            // (classes/external/create_course_from_template.php's declared
            // shape) regardless of which keys create_course_service::create_course()
            // itself happened to include for this outcome.
            return [
                'success' => (bool)($result['success'] ?? false),
                'courseid' => (int)($result['courseid'] ?? 0),
                'courseurl' => (string)($result['courseurl'] ?? ''),
                'fullname' => (string)($result['fullname'] ?? ''),
                'shortname' => (string)($result['shortname'] ?? ''),
                'message' => (string)($result['message'] ?? ''),
                'partial' => (bool)($result['partial'] ?? false),
                'haswarnings' => (bool)($result['haswarnings'] ?? false),
                'warningscount' => (int)($result['warningscount'] ?? 0),
                'activityerrors' => $result['activityerrors'] ?? [],
            ];
        } catch (\Throwable $e) {
            debugging('local_coursegen: create_course_from_template failed. ' . $e->getMessage(), DEBUG_DEVELOPER);
            return [
                'success' => false,
                'courseid' => 0,
                'courseurl' => '',
                'fullname' => '',
                'shortname' => '',
                'message' => $e->getMessage(),
                'partial' => false,
                'haswarnings' => false,
                'warningscount' => 0,
                'activityerrors' => [],
            ];
        }
    }
}

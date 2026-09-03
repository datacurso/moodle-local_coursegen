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

use core_text;

defined('MOODLE_INTERNAL') || die();

/**
 * Mocked AI content generator for template-mode course creation.
 *
 * Stands in for the real Datacurso AI backend while the template-mode
 * "create course" flow is wired end-to-end. Every caller only ever talks to
 * generate(): it receives a small, stable payload (modname, sectionname,
 * prompt, referencecontent, lang, title) and returns a generated_activities
 * -shape entry (['resource_type' => ..., 'parameters' => ...]), the exact
 * same shape create_mod_service::create_from_ai_result() already consumes
 * for the free-creation flow.
 *
 * REPLACE-WITH-REAL-AI: swapping this mock for the real AI means replacing
 * the body of generate() (and, in particular, build_body_html()) with a call
 * to the real course/activity generation endpoint and mapping its response
 * into the same return shape. Callers never reference this class by its
 * literal name — they resolve it through
 * template_course_builder_service::AI_SERVICE_CLASS — so a real
 * implementation under a different class name only needs that one constant
 * updated, not every call site.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mock_template_ai_service {
    /** @var string[] Module names the mock currently knows how to fabricate content for. */
    private const SUPPORTED = ['page', 'label', 'forum', 'assign'];

    /**
     * Generate a generated_activities-shape entry for one activity.
     *
     * @param array $payload {
     *     modname: string           Target module plugin name (e.g. 'page').
     *     sectionname: string       Name of the destination section (context only).
     *     prompt: string            Per-activity prompt configured on the template ('' for new activities).
     *     referencecontent: string  Optional context gathered from reference/useasreference activities.
     *     lang: string              Language code (context only, unused by the mock).
     *     title: string             Optional explicit activity title; derived otherwise.
     * }
     * @return array{resource_type:string,parameters:array}
     * @throws \moodle_exception If the module type is not supported by the mock yet.
     */
    public static function generate(array $payload): array {
        $modname = (string) ($payload['modname'] ?? '');

        if (!in_array($modname, self::SUPPORTED, true)) {
            // REPLACE-WITH-REAL-AI: every module type allowed by a template must
            // eventually be handled by the real AI backend. Until then this is a
            // per-activity warning (caught by the caller), never a fatal error.
            throw new \moodle_exception(
                'error_invalid_resource_type',
                'local_coursegen',
                '',
                $modname . ' (mock AI: not yet supported for template mode)'
            );
        }

        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            $title = self::default_title($modname, $payload);
        }
        $prompt = trim((string) ($payload['prompt'] ?? ''));
        $reference = trim((string) ($payload['referencecontent'] ?? ''));
        $sectionname = trim((string) ($payload['sectionname'] ?? ''));

        $body = self::build_body_html($title, $sectionname, $prompt, $reference);

        switch ($modname) {
            case 'page':
                return self::page_result($title, $body);
            case 'label':
                return self::label_result($title, $body);
            case 'forum':
                return self::forum_result($title, $body);
            case 'assign':
            default:
                return self::assign_result($title, $body);
        }
    }

    /**
     * Fabricate a default title when none is supplied (new activities added by the professor).
     *
     * @param string $modname Module plugin name.
     * @param array $payload Original payload.
     * @return string
     */
    private static function default_title(string $modname, array $payload): string {
        $sectionname = trim((string) ($payload['sectionname'] ?? ''));
        $label = get_string('pluginname', 'mod_' . $modname);
        return $sectionname !== '' ? ($label . ' - ' . $sectionname) : $label;
    }

    /**
     * Build the fabricated HTML body used as the activity's main content.
     *
     * REPLACE-WITH-REAL-AI: this is the only place that invents content; the
     * real integration replaces this method (and only this method) with the
     * actual AI-generated markup for the activity.
     *
     * @param string $title Activity title.
     * @param string $sectionname Destination section name.
     * @param string $prompt Per-activity prompt, if any.
     * @param string $reference Reference context gathered from the template, if any.
     * @return string HTML fragment.
     */
    private static function build_body_html(string $title, string $sectionname, string $prompt, string $reference): string {
        $parts = [];
        $parts[] = '<p>' . s($title) . '</p>';

        if ($prompt !== '') {
            $parts[] = '<p><strong>' . get_string('courseai_template_mock_prompt', 'local_coursegen') . ':</strong> '
                . s($prompt) . '</p>';
        } else {
            $parts[] = '<p>' . get_string('courseai_template_mock_generic', 'local_coursegen', s($sectionname)) . '</p>';
        }

        if ($reference !== '') {
            $parts[] = '<p><strong>' . get_string('courseai_template_mock_reference', 'local_coursegen') . ':</strong></p>';
            $parts[] = '<p>' . s(core_text::substr($reference, 0, 600)) . '</p>';
        }

        return implode("\n", $parts);
    }

    /**
     * Common course-module-level parameters shared by every mocked module type.
     *
     * @param string $modname Module plugin name.
     * @param string $title Activity title.
     * @return array
     */
    private static function base_parameters(string $modname, string $title): array {
        return [
            'modulename' => $modname,
            'name' => $title,
            'visible' => 1,
            'visibleoncoursepage' => 1,
            'groupmode' => 0,
            'groupingid' => 0,
            'completion' => 0,
            'completiongradeitemnumber' => '',
            'completionview' => 0,
            'completionexpected' => 0,
            'completionpassgrade' => 0,
            'showdescription' => 0,
            'mod_settings' => [],
        ];
    }

    /**
     * Build a mod_page generated_activities-shape entry.
     *
     * @param string $title Activity title.
     * @param string $body Fabricated body HTML.
     * @return array
     */
    private static function page_result(string $title, string $body): array {
        $parameters = self::base_parameters('page', $title);
        $parameters['introeditor'] = ['text' => '', 'format' => 1];
        $parameters['page'] = ['text' => $body, 'format' => 1];
        // RESOURCELIB_DISPLAY_AUTO: avoids needing popupwidth/popupheight.
        $parameters['display'] = 0;
        $parameters['printintro'] = 0;
        $parameters['printlastmodified'] = 1;

        return ['resource_type' => 'page', 'parameters' => $parameters];
    }

    /**
     * Build a mod_label generated_activities-shape entry.
     *
     * @param string $title Activity title.
     * @param string $body Fabricated body HTML.
     * @return array
     */
    private static function label_result(string $title, string $body): array {
        $parameters = self::base_parameters('label', $title);
        $parameters['introeditor'] = ['text' => $body, 'format' => 1];

        return ['resource_type' => 'label', 'parameters' => $parameters];
    }

    /**
     * Build a mod_forum generated_activities-shape entry, with one seed discussion.
     *
     * @param string $title Activity title.
     * @param string $body Fabricated body HTML.
     * @return array
     */
    private static function forum_result(string $title, string $body): array {
        $parameters = self::base_parameters('forum', $title);
        $parameters['introeditor'] = ['text' => '', 'format' => 1];
        $parameters['type'] = 'general';
        $parameters['assessed'] = 0;
        $parameters['scale'] = 0;
        $parameters['forcesubscribe'] = 0;
        $parameters['grade_forum'] = 0;
        // Consumed by forum_settings::add_settings() after creation (existing
        // free-mode convention for seeding forum discussions).
        $parameters['mod_settings'] = [
            'discussions' => [
                ['subject' => $title, 'message' => $body],
            ],
        ];

        return ['resource_type' => 'forum', 'parameters' => $parameters];
    }

    /**
     * Build a mod_assign generated_activities-shape entry.
     *
     * Field defaults mirror mod_assign_generator's testing defaults so
     * assign::add_instance() (called by add_moduleinfo()) receives every
     * property it reads directly, with no submission/feedback plugin enabled.
     *
     * @param string $title Activity title.
     * @param string $body Fabricated body HTML.
     * @return array
     */
    private static function assign_result(string $title, string $body): array {
        $parameters = self::base_parameters('assign', $title);
        $parameters['introeditor'] = ['text' => $body, 'format' => 1];
        $parameters += [
            'alwaysshowdescription' => 1,
            'submissiondrafts' => 1,
            'requiresubmissionstatement' => 0,
            'sendnotifications' => 0,
            'sendstudentnotifications' => 1,
            'sendlatenotifications' => 0,
            'duedate' => 0,
            'allowsubmissionsfromdate' => 0,
            'grade' => 100,
            'cutoffdate' => 0,
            'gradingduedate' => 0,
            'teamsubmission' => 0,
            'requireallteammemberssubmit' => 0,
            'teamsubmissiongroupingid' => 0,
            'blindmarking' => 0,
            'attemptreopenmethod' => 'untilpass',
            'maxattempts' => 1,
            'markingworkflow' => 0,
            'markingallocation' => 0,
            'markinganonymous' => 0,
            'activityformat' => 0,
            'timelimit' => 0,
            'submissionattachments' => 0,
        ];

        return ['resource_type' => 'assign', 'parameters' => $parameters];
    }
}

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

defined('MOODLE_INTERNAL') || die();

/**
 * Contract for the AI content generator used by template-mode course
 * creation (see template_course_builder_service).
 *
 * This is the permanent contract of the real AI backend — independent of
 * whichever implementation currently satisfies it. mock_template_ai_service
 * implements it today as a stand-in; a real implementation will implement it
 * later by wrapping the real Datacurso AI course API (see
 * ai_course_api_service/api_client_factory for the equivalent pattern
 * already used by the free-course-creation flow). Swapping mock for real is
 * then a single call to template_course_builder_service::set_ai_service(),
 * never a search for scattered references to the mock class name — and
 * AI_SUPPORTED_TYPES survives deleting the mock entirely, since it belongs
 * to this interface, not to the mock's own class body.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface template_content_generator {
    /**
     * Every activity type the real AI content service has a registered
     * content contract for — mirrors the activity-type registry in the
     * sibling `course_ai` Python service
     * (`app/agents/activity_prompts/*.py`, discovered by
     * `ActivityPromptRegistry`, one file per Moodle modname). This is the
     * single source of truth for which activity types a professor may add
     * as brand-new activities in a course generated from a template (see
     * template_config_form::definition()) — never everything installed on
     * the site, since the AI service (real or mocked) can only ever be
     * asked to generate content for a type it actually has a contract for.
     *
     * Deliberately NOT the same list as MODIFY_SUPPORTED_TYPES: this constant
     * reflects the real service's full content contract; that one is the
     * narrower, currently-implemented subset eligible for "Modify" of an
     * activity the template already contains. Extending real generation to
     * cover more of these types is tracked as Phase 2 work in
     * docs/course_template/tasks.md.
     *
     * @var string[]
     */
    public const AI_SUPPORTED_TYPES = [
        'assign', 'book', 'choice', 'data', 'feedback', 'folder', 'forum',
        'glossary', 'h5pactivity', 'imscp', 'label', 'lesson', 'page', 'quiz',
        'resource', 'scorm', 'url', 'wiki', 'workshop',
    ];

    /**
     * Module names for which "Modify with AI" is actually implemented today,
     * for an activity that already exists in the base course being turned
     * into a template — a narrower, permanent subset of AI_SUPPORTED_TYPES
     * (that one gates which brand-new activity types a template may add at
     * all).
     *
     * Lives on this interface, not on any one implementation: this is what
     * sections_config::build_activity_dropdown() reads to decide whether
     * "Modify" is even offered for an activity, and that decision must
     * survive deleting the mock — it can never depend on a constant scoped
     * to a class meant to be thrown away wholesale. Grows one type at a time
     * as Phase 2 (docs/course_template/tasks.md) lands real support for each
     * (lesson is next); whether file resources ever belong here at all is
     * also a Phase 2 decision — today's answer is no, they're kept/reference
     * only.
     *
     * @var string[]
     */
    public const MODIFY_SUPPORTED_TYPES = ['page', 'label', 'forum', 'assign'];

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
     * @throws \moodle_exception If the module type is not supported yet.
     */
    public function generate(array $payload): array;

    /**
     * Generate a grid-format course section's tile picture.
     *
     * @param array $payload {
     *     sectionname: string      The new section's own, correct name/title.
     *     sectionnum: int          The new section's own section number (context only).
     *     stylereference: string   The course's brand/style reference (colors, tone, etc.), the
     *                               same one banners already follow elsewhere in this product.
     * }
     * @return array{filename:string,mimetype:string,content:string}
     */
    public function generate_section_picture(array $payload): array;
}

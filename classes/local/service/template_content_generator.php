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
     * This is also the single source of truth for which types offer
     * "Modify with AI" on an activity the template already contains (see
     * sections_config::build_activity_dropdown()) — every type the real
     * service can generate content for must be offered, full stop; no
     * narrower subset of "currently implemented" types gates the UI. A
     * given implementation (the mock included) may still not have every
     * type built out yet — see generate()'s own REPLACE-WITH-REAL-AI note
     * for how that gap is handled without lying to the UI about it.
     *
     * @var string[]
     */
    public const AI_SUPPORTED_TYPES = [
        'assign', 'book', 'choice', 'data', 'feedback', 'folder', 'forum',
        'glossary', 'h5pactivity', 'imscp', 'label', 'lesson', 'page', 'quiz',
        'resource', 'scorm', 'url', 'wiki', 'workshop',
    ];

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

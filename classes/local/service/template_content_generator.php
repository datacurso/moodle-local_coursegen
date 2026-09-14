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
 * creation.
 *
 * This is the permanent contract of the real AI backend, independent of
 * whichever implementation/orchestrator ends up satisfying it. No orchestrator
 * on this branch consumes it directly right now (the per-activity
 * backup/restore + mock-AI approach this interface used to back was removed);
 * AI_SUPPORTED_TYPES remains the single source of truth for which activity
 * types the template UI offers (see sections_config/template_config_form)
 * regardless.
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
     * the site, since the AI service can only ever be asked to generate
     * content for a type it actually has a contract for.
     *
     * This is also the single source of truth for which types offer
     * "Modify with AI" on an activity the template already contains (see
     * sections_config::build_activity_dropdown()) — every type the real
     * service can generate content for must be offered, full stop; no
     * narrower subset of "currently implemented" types gates the UI.
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
     *     lang: string              Language code (context only).
     *     title: string             Optional explicit activity title; derived otherwise.
     *     generateimages: int       Whether images should be generated for the activity (0/1);
     *                               set for professor-added new activities.
     *     draftitemid: int          Draft area id of a professor-uploaded reference file (0 if
     *                               none); set for new activities.
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

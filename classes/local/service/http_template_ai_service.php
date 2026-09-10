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

use local_coursegen\local\httpclient\coursegen_template_client;

defined('MOODLE_INTERNAL') || die();

/**
 * HTTP-based AI content generator for template-mode course creation.
 *
 * Real (non-mock) implementation of template_content_generator: calls the
 * standalone `coursegen-template` test/dev service instead of fabricating
 * content in PHP (see mock_template_ai_service, which this class is designed
 * to be swapped in for via template_course_builder_service::set_ai_service(),
 * never the other way around).
 *
 * The service already returns each activity type's envelope in exactly the
 * generated_activities shape create_mod_service::create_from_ai_result()
 * consumes (['resource_type' => ..., 'parameters' => ...]), matching the
 * shape mock_template_ai_service's own per-type result builders produce — so
 * generate() is mostly a thin pass-through, with one deliberate exception:
 * "resource"-type (and any other single-file package-type) results carry a
 * remote file_path/file_name pair that, in the real Datacurso production
 * flow, resource_parameters downloads through the production API's own
 * /files/download proxy. This test service has no such proxy — it serves the
 * file directly at file_path — so this class downloads it itself and hands
 * create_mod_service an already-resolved draft file id instead (see
 * maybe_resolve_package_file() and resource_parameters::get_parameters()'s
 * matching guard).
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class http_template_ai_service implements template_content_generator {
    /** @var coursegen_template_client HTTP client for the coursegen-template service. */
    private coursegen_template_client $client;

    /**
     * Constructor.
     *
     * @param string|null $baseurl Optional base URL override for the coursegen-template
     *     service. When null, the client falls back to the admin setting, then its own default.
     * @param coursegen_template_client|null $client Optional pre-built client (for testing).
     */
    public function __construct(?string $baseurl = null, ?coursegen_template_client $client = null) {
        $this->client = $client ?? new coursegen_template_client($baseurl);
    }

    /**
     * Generate a generated_activities-shape entry for one activity by asking
     * the coursegen-template service for a realistic example of that type.
     *
     * @param array $payload {
     *     modname: string           Target module plugin name (e.g. 'page').
     *     sectionname: string       Name of the destination section (context only).
     *     prompt: string            Per-activity prompt configured on the template ('' for new activities).
     *     referencecontent: string  Optional context gathered from reference/useasreference activities.
     *     lang: string              Language code (context only, unused by this implementation).
     *     title: string             Optional explicit activity title; unused — the service's own
     *                               generated title is used, exactly as a real AI regeneration would.
     * }
     * @return array{resource_type:string,parameters:array}
     * @throws \moodle_exception If the module type is not supported (by this contract or by the
     *     coursegen-template service), or the service's response is malformed.
     */
    public function generate(array $payload): array {
        $modname = (string) ($payload['modname'] ?? '');

        if (!in_array($modname, self::AI_SUPPORTED_TYPES, true)) {
            throw new \moodle_exception(
                'error_invalid_resource_type',
                'local_coursegen',
                '',
                $modname . ' (coursegen-template: not a recognized activity type)'
            );
        }

        $result = $this->client->get_json('/api/activities/' . $modname);
        if ($result === null) {
            // The service's own 404 for a type it has no fixture for yet.
            throw new \moodle_exception(
                'error_invalid_resource_type',
                'local_coursegen',
                '',
                $modname . ' (coursegen-template service: type not supported)'
            );
        }

        if (empty($result['resource_type']) || !isset($result['parameters']) || !is_array($result['parameters'])) {
            throw new \moodle_exception('error_api_response', 'local_coursegen');
        }

        $parameters = $result['parameters'];
        $parameters = $this->maybe_resolve_package_file($parameters);
        $parameters = $this->flatten_known_module_editor_fields($modname, $parameters);

        return [
            'resource_type' => (string) $result['resource_type'],
            'parameters' => $parameters,
        ];
    }

    /**
     * Flatten module-specific "_editor" fields this AI-driven creation flow
     * would otherwise never process.
     *
     * create_mod_service::create_from_ai_result() never submits $mform, so
     * moodleform_mod::get_data() (and each mod_form's own data_postprocessing()
     * hook) never runs — the ONE place a module like feedback flattens its own
     * extra editor fields (beyond the generic 'introeditor', which
     * course/modlib.php::add_moduleinfo() already handles for every module)
     * into the real DB columns those tables actually require. Without this,
     * $DB->insert_record() silently drops the unmatched '..._editor' array and
     * the underlying NOT NULL column (e.g. feedback.page_after_submit) fails
     * with a raw DB error instead of ever reaching the AI content.
     *
     * Mirrors mod_feedback_mod_form::data_postprocessing() exactly. Scoped to
     * the one field this test fixture's feedback envelope actually carries —
     * extend here if/when another type's envelope needs the same treatment.
     *
     * @param string $modname Module plugin name.
     * @param array $parameters Parameters so far.
     * @return array Parameters with known editor fields flattened.
     */
    private function flatten_known_module_editor_fields(string $modname, array $parameters): array {
        if ($modname === 'feedback' && isset($parameters['page_after_submit_editor']['text'])) {
            $editor = $parameters['page_after_submit_editor'];
            $parameters['page_after_submit'] = (string) $editor['text'];
            $parameters['page_after_submitformat'] = (int) ($editor['format'] ?? FORMAT_HTML);
        }

        return $parameters;
    }

    /**
     * Resolve a single-file "package" result (currently: mod_resource) by
     * downloading the file directly from the coursegen-template service and
     * attaching it to a fresh draft file area, instead of leaving the
     * production-only file_path/file_name convention for resource_parameters
     * to (unsuccessfully) proxy through the real Datacurso API.
     *
     * A no-op for any type whose parameters don't carry the file_path +
     * file_name pair at the top of mod_settings (e.g. label/page/forum, or
     * folder's own multi-file mod_settings.files array, left untouched here).
     *
     * @param array $parameters Raw parameters from the service's response.
     * @return array Parameters, with 'files' set to a draft itemid when a
     *     single-file package was resolved.
     */
    private function maybe_resolve_package_file(array $parameters): array {
        $modsettings = $parameters['mod_settings'] ?? null;
        if (!is_array($modsettings)) {
            return $parameters;
        }

        $filepath = $modsettings['file_path'] ?? null;
        $filename = $modsettings['file_name'] ?? null;
        if (!is_string($filepath) || trim($filepath) === '' || !is_string($filename) || trim($filename) === '') {
            return $parameters;
        }

        $cleanname = clean_param(basename($filename), PARAM_FILE);
        if ($cleanname === '') {
            $cleanname = 'coursegen-template-file';
        }

        // The service's own fixture data always self-references as
        // "http://localhost:3000/..." (its own view of itself), which is
        // only reachable when this Moodle process happens to run inside the
        // exact same network namespace/host as the service — never true from
        // a real Moodle container talking to it over the shared "moodle"
        // Docker network. Keep only the path (+ query) from file_path and
        // resolve it against this client's own configured base URL instead,
        // so the download always targets the coursegen-template instance this
        // implementation was actually configured to talk to.
        $path = (string) (parse_url($filepath, PHP_URL_PATH) ?: '');
        $query = parse_url($filepath, PHP_URL_QUERY);
        $downloadurl = $this->client->get_base_url() . $path . ($query ? '?' . $query : '');

        $content = $this->client->download_raw($downloadurl);

        $draftitemid = file_get_unused_draft_itemid();
        $fs = get_file_storage();
        $context = \context_user::instance($this->current_userid());
        $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $cleanname,
        ], $content);

        // Already resolved: resource_parameters::get_parameters() honors an
        // already-set 'files' draft itemid and skips its own (production-only)
        // download step — see that class's own docblock for the guard.
        $parameters['files'] = $draftitemid;

        return $parameters;
    }

    /**
     * Current user id, used as the owner of the temporary draft file area.
     *
     * @return int
     */
    private function current_userid(): int {
        global $USER;
        return (int) ($USER->id ?? 0);
    }

    /**
     * Generate a grid-format course section's tile picture.
     *
     * The coursegen-template service exposes no section-picture endpoint (see
     * this plugin's task notes), so this mirrors mock_template_ai_service's
     * own plain, correctly-labeled placeholder rather than fabricating a
     * fake "AI" call this test service was never built to answer. Never
     * exercised by the CLI end-to-end template test (which adds no new
     * sections), but implemented so the contract is genuinely complete.
     *
     * @param array $payload {
     *     sectionname: string      The new section's own, correct name/title.
     *     sectionnum: int          The new section's own section number (context only).
     *     stylereference: string   Unused by this implementation.
     * }
     * @return array{filename:string,mimetype:string,content:string}
     */
    public function generate_section_picture(array $payload): array {
        $sectionname = trim((string) ($payload['sectionname'] ?? ''));
        if ($sectionname === '') {
            $sectionname = (string) ($payload['sectionnum'] ?? '');
        }

        $label = htmlspecialchars($sectionname, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="800" height="450" viewBox="0 0 800 450" role="img">
  <rect width="800" height="450" fill="#31424f"/>
  <text x="400" y="225" font-family="sans-serif" font-size="40" fill="#ffffff"
        text-anchor="middle" dominant-baseline="middle">{$label}</text>
</svg>
SVG;

        return [
            'filename' => 'section.svg',
            'mimetype' => 'image/svg+xml',
            'content' => $svg,
        ];
    }
}

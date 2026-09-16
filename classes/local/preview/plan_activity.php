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

/**
 * Turns one plan entry into the shape an activity preview reads.
 *
 * A run is previewable twice over, and the two moments hold the same activity
 * in different shapes. While it is being reviewed it exists as a plan: the
 * mould's own markup with a draft written into it, one entry per part. Once it
 * has been generated it exists as the module's real parameters.
 *
 * The previews are written against the second shape, because that is the shape
 * the activity is finally built from. This converts the first into it, so the
 * same preview draws the draft and the finished thing, and what the teacher
 * approves looks like what they will get for the reason that it is drawn by
 * the same code.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class plan_activity {
    /**
     * The activity parameters a plan entry stands for.
     *
     * @param array $entry One entry of template_plan.
     * @return array Parameters in the same shape the generated answer uses.
     */
    public static function to_parameters(array $entry): array {
        $parts = $entry['parts'] ?? [];
        $name = (string) ($entry['name'] ?? '');

        if (($entry['resource_type'] ?? '') === 'lesson') {
            $pages = [];
            foreach ($parts as $part) {
                $pages[] = [
                    'page_type' => 'content',
                    'title' => (string) ($part['title'] ?? ''),
                    'content_html' => (string) ($part['html'] ?? ''),
                    'buttons' => [],
                ];
            }
            return ['name' => $name, 'mod_settings' => ['pages' => $pages]];
        }

        // Every other type keeps its text in one field, so the parts are simply
        // concatenated in the order the mould authored them.
        $html = '';
        foreach ($parts as $part) {
            $html .= (string) ($part['html'] ?? '');
        }
        return [
            'name' => $name,
            'introeditor' => ['text' => $html, 'format' => FORMAT_HTML, 'itemid' => 0],
            'page' => $html,
        ];
    }

    /**
     * One drafted activity, ready to be built.
     *
     * A draft says what an activity will SAY and never how it is set up: the
     * mould settled that, and it is the mould's own settings the delivered
     * activity is built with. So the settings are taken from the mould and the
     * draft laid over the top, which is what makes a previewed activity a real
     * one rather than a shell missing every field its form requires.
     *
     * @param array $plan The whole plan.
     * @param int $cmid The activity being previewed.
     * @param int $templateid The template the run is built on.
     * @return array {resource_type, parameters}, or empty when it is not in the plan.
     */
    public static function to_activity(array $plan, int $cmid, int $templateid): array {
        $entry = null;
        foreach ($plan as $candidate) {
            if ((int) ($candidate['cmid'] ?? 0) === $cmid) {
                $entry = (array) $candidate;
                break;
            }
        }
        if ($entry === null || $templateid <= 0) {
            return [];
        }

        $payload = \local_coursegen\local\service\template_export_service::build_init_payload($templateid);
        $bycmid = [];
        foreach (($payload['activities'] ?? []) as $activity) {
            $bycmid[(int) ($activity['cmid'] ?? 0)] = $activity;
        }

        $instance = $bycmid[$cmid] ?? null;
        if (!$instance) {
            return [];
        }
        $sourcecmid = (int) (($instance['template_behavior'] ?? [])['template_source_cmid'] ?? 0);
        $parameters = (array) (($bycmid[$sourcecmid] ?? [])['parameters'] ?? []);

        $drafted = self::to_parameters($entry);
        $parameters['name'] = $drafted['name'];
        // The mould describes an activity that exists, so it carries settings
        // but not the field naming which module to create.
        $parameters['modulename'] = (string) ($instance['resource_type'] ?? '');
        if (isset($drafted['mod_settings'])) {
            $parameters['mod_settings'] = [
                'pages' => self::pages_with_drafted_content(
                    (array) (($parameters['mod_settings'] ?? [])['pages'] ?? []),
                    (array) $drafted['mod_settings']['pages']
                ),
            ];
        } else {
            $parameters['introeditor'] = $drafted['introeditor'];
        }

        return [
            'resource_type' => (string) ($instance['resource_type'] ?? ''),
            'parameters' => self::as_form_data($parameters),
        ];
    }

    /**
     * The mould's pages, each carrying what was drafted for it.
     *
     * Only the content is drafted. A page's type and its navigation buttons
     * belong to the mould, and dropping them does not merely lose the buttons:
     * a content page without any is discarded outright when the lesson is
     * built, which leaves a lesson with no pages at all.
     *
     * @param array $moldpages The mould's own pages, in order.
     * @param array $drafted The drafted pages, in the same order.
     * @return array
     */
    private static function pages_with_drafted_content(array $moldpages, array $drafted): array {
        $pages = [];
        foreach (array_values($moldpages) as $index => $page) {
            $page = (array) $page;
            if (isset($drafted[$index]['content_html'])) {
                $page['content_html'] = (string) $drafted[$index]['content_html'];
            }
            $pages[] = $page;
        }
        return $pages ?: $drafted;
    }

    /**
     * A mould's settings, in the shape a module's form submits.
     *
     * The export describes an activity that exists, so it reads the module's
     * table: a description is the `intro` column, and some columns are not form
     * fields at all. Creating an activity goes through the form, which wants
     * the editor pair and refuses what it does not recognise.
     *
     * @param array $parameters
     * @return array
     */
    private static function as_form_data(array $parameters): array {
        if (isset($parameters['intro']) && !isset($parameters['introeditor'])) {
            $parameters['introeditor'] = [
                'text' => (string) $parameters['intro'],
                'format' => FORMAT_HTML,
                'itemid' => 0,
            ];
        }
        // Columns the module keeps for itself: set when the activity is saved,
        // never submitted with it.
        unset($parameters['intro'], $parameters['conditions'], $parameters['mediafile']);

        // What every activity form carries regardless of its type, at the
        // values an activity created into a section without conditions has.
        return $parameters + [
            'visible' => 1,
            'visibleoncoursepage' => 1,
            'cmidnumber' => '',
            'lang' => '',
            'groupmode' => 0,
            'groupingid' => 0,
            'completionunlocked' => 1,
            'completion' => 0,
            'completionexpected' => 0,
            'showdescription' => 0,
        ];
    }
}

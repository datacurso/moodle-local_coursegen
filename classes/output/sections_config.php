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

/**
 * Render the sections config view: course format HTML with action controls injected.
 *
 * @package    local_coursegen
 * @copyright  2025 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\output;

/**
 * Build the sections config HTML server-side with dropdowns and prompts already injected.
 */
class sections_config {

    /**
     * Render the course preview HTML with section/activity controls injected.
     *
     * @param string $previewhtml The raw format renderer HTML.
     * @param \course_modinfo $modinfo The course modinfo.
     * @return string Modified HTML with controls.
     */
    public static function render(string $previewhtml, \course_modinfo $modinfo): string {
        $doc = new \DOMDocument();
        // Suppress warnings for HTML5 tags.
        $previouserrors = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div>' . $previewhtml . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_use_internal_errors($previouserrors);

        $xpath = new \DOMXPath($doc);

        // Inject section controls.
        $sections = $xpath->query('//*[@data-for="section"]');
        foreach ($sections as $section) {
            $sectionid = $section->getAttribute('data-id');
            if (!$sectionid) {
                continue;
            }
            $titlebars = $xpath->query('.//*[@data-for="section_title"]', $section);
            if ($titlebars->length === 0) {
                continue;
            }
            $titlebar = $titlebars->item(0);
            $control = $doc->createElement('div');
            $control->setAttribute('class', 'ml-auto dropdown');
            $controlhtml = self::build_section_dropdown((int)$sectionid);
            $frag = $doc->createDocumentFragment();
            $frag->appendXML($controlhtml);
            $control->appendChild($frag);
            $titlebar->appendChild($control);
        }

        // Hide "Collapse all" links.
        $collapsealls = $xpath->query('//*[@data-toggle="toggleall"]');
        foreach ($collapsealls as $el) {
            $el->setAttribute('style', 'display:none');
        }

        // Remove reactive toggler attribute.
        $togglers = $xpath->query('//*[@data-for="sectiontoggler"]');
        foreach ($togglers as $el) {
            $el->removeAttribute('data-for');
        }

        // Inject activity controls.
        $cmitems = $xpath->query('//*[@data-for="cmitem"]');
        foreach ($cmitems as $cmitem) {
            $cmid = $cmitem->getAttribute('data-id');
            if (!$cmid) {
                continue;
            }
            // Find the activity-grid to append dropdown inline.
            $grids = $xpath->query('.//*[contains(@class,"activity-grid")]', $cmitem);
            $grid = $grids->length > 0 ? $grids->item(0) : $cmitem;

            $dropwrap = $doc->createElement('div');
            $dropwrap->setAttribute('class', 'ml-auto dropdown');
            $dropwrap->setAttribute('data-tpl-control', (string)$cmid);
            $drophtml = self::build_activity_dropdown((int)$cmid);
            $frag = $doc->createDocumentFragment();
            $frag->appendXML($drophtml);
            $dropwrap->appendChild($frag);
            $grid->appendChild($dropwrap);

            // Prompt textarea.
            $promptwrap = $doc->createElement('div');
            $promptwrap->setAttribute('data-tpl-prompt-wrap', (string)$cmid);
            $promptwrap->setAttribute('style', 'padding:0 1rem .5rem 3.5rem');
            $textarea = $doc->createElement('textarea', '');
            $textarea->setAttribute('class', 'form-control');
            $textarea->setAttribute('rows', '2');
            $textarea->setAttribute('data-tpl-prompt', (string)$cmid);
            $textarea->setAttribute('placeholder',
                get_string('template_activity_prompt_placeholder', 'local_coursegen'));
            $promptwrap->appendChild($textarea);
            $cmitem->appendChild($promptwrap);
        }

        $html = $doc->saveHTML();
        // Strip the wrapper we added.
        $html = preg_replace('/^.*?<div>/s', '', $html);
        $html = preg_replace('/<\/div>\s*$/s', '', $html);
        return $html;
    }

    /**
     * Build section dropdown HTML.
     *
     * @param int $sectionid
     * @return string
     */
    private static function build_section_dropdown(int $sectionid): string {
        $tips = [
            'custom' => get_string('template_section_custom_tip', 'local_coursegen'),
            'keep' => get_string('template_section_keep_tip', 'local_coursegen'),
            'exclude' => get_string('template_section_exclude_tip', 'local_coursegen'),
        ];
        $html = '<button class="btn btn-sm btn-link dropdown-toggle p-0" '
            . 'style="color:#0f6cbf;text-decoration:none;font-weight:600" '
            . 'data-toggle="dropdown" title="' . s($tips['custom']) . '">'
            . get_string('template_section_custom', 'local_coursegen') . '</button>';
        $html .= '<div class="dropdown-menu dropdown-menu-right">';
        $items = [
            'custom' => get_string('template_section_custom', 'local_coursegen'),
            'keep' => get_string('template_section_keep', 'local_coursegen'),
            'exclude' => get_string('template_section_exclude', 'local_coursegen'),
        ];
        foreach ($items as $key => $label) {
            $active = $key === 'custom' ? 'active' : '';
            $html .= '<a class="dropdown-item ' . $active . '" href="#" '
                . 'data-sec-action="' . $key . '" data-sid="' . $sectionid . '" '
                . 'title="' . s($tips[$key]) . '">' . $label . '</a>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Build activity action dropdown HTML.
     *
     * @param int $cmid
     * @return string
     */
    private static function build_activity_dropdown(int $cmid): string {
        $tips = [
            'modify' => get_string('template_activity_modify_tip', 'local_coursegen'),
            'keep' => get_string('template_activity_keep_tip', 'local_coursegen'),
            'reference' => get_string('template_activity_reference_tip', 'local_coursegen'),
            'exclude' => get_string('template_activity_exclude_tip', 'local_coursegen'),
        ];
        $labels = [
            'modify' => get_string('template_activity_modify', 'local_coursegen'),
            'keep' => get_string('template_activity_keep', 'local_coursegen'),
            'reference' => get_string('template_activity_reference', 'local_coursegen'),
            'exclude' => get_string('template_activity_exclude', 'local_coursegen'),
        ];
        $html = '<button class="btn btn-sm btn-link dropdown-toggle p-0" '
            . 'style="color:#0f6cbf;text-decoration:none" '
            . 'data-toggle="dropdown" title="' . s($tips['modify']) . '">'
            . $labels['modify'] . '</button>';
        $html .= '<div class="dropdown-menu dropdown-menu-right">';
        foreach ($labels as $key => $label) {
            $active = $key === 'modify' ? 'active' : '';
            $html .= '<a class="dropdown-item ' . $active . '" href="#" '
                . 'data-act-val="' . $key . '" '
                . 'title="' . s($tips[$key]) . '">' . $label . '</a>';
        }
        $html .= '</div>';
        return $html;
    }
}

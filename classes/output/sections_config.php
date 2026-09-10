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

use local_coursegen\local\service\mock_template_ai_service;

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

        // This preview reuses the REAL course-format renderer's own HTML
        // against the REAL base course — meaning every native course-EDITING
        // affordance that renderer normally produces (inline rename, "add
        // activity" choosers, section/activity action menus) is still
        // present and fully live. An admin here only intends to configure a
        // *template*; they must never be one misclick away from actually
        // renaming a section, adding a module, or opening "Edit section" /
        // "Delete" against the real course everyone else uses as the
        // template. Strip all of that out before anything else runs.
        self::strip_native_editing_controls($doc, $xpath);

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
            $cm = $modinfo->get_cm((int) $cmid);
            $cmitem->setAttribute('data-modname', $cm->modname);

            // The renderer's own .activity-actions container (already
            // aligned top-right via its own align-self-start class) held
            // the native "⋮" actions menu we just stripped out — reuse
            // that same slot for our dropdown instead of appending at the
            // end of .activity-grid, so it consistently lands in the same
            // corner the section-level dropdown already occupies, rather
            // than wherever normal document flow happens to leave room.
            $actionslots = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " activity-actions ")]', $cmitem);
            if ($actionslots->length > 0) {
                $slot = $actionslots->item(0);
            } else {
                $grids = $xpath->query('.//*[contains(@class,"activity-grid")]', $cmitem);
                $slot = $grids->length > 0 ? $grids->item(0) : $cmitem;
            }

            $dropwrap = $doc->createElement('div');
            $dropwrap->setAttribute('class', 'ml-auto dropdown');
            $dropwrap->setAttribute('data-tpl-control', (string)$cmid);
            $drophtml = self::build_activity_dropdown((int)$cmid, $cm->modname);
            $frag = $doc->createDocumentFragment();
            $frag->appendXML($drophtml);
            $dropwrap->appendChild($frag);
            $slot->appendChild($dropwrap);

            // Prompt textarea — only visible when the default action is "Modify".
            $cansupportmodify = in_array($cm->modname, mock_template_ai_service::SUPPORTED, true);
            $promptwrap = $doc->createElement('div');
            $promptwrap->setAttribute('data-tpl-prompt-wrap', (string)$cmid);
            $promptstyle = 'padding:0 1rem .5rem 3.5rem';
            if (!$cansupportmodify) {
                $promptstyle .= ';display:none';
            }
            $promptwrap->setAttribute('style', $promptstyle);
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
     * Remove every native course-EDITING affordance the real course-format
     * renderer produces (as opposed to purely read-only view/navigation
     * links, which are left alone) — none of these apply to configuring a
     * template, and some are wired to real actions against the real base
     * course. Matched by the same semantic data-region/class markers core's
     * own course-format renderer uses everywhere, not anything specific to
     * one particular course format, so this holds for formats other than
     * format_grid too.
     *
     * @param \DOMDocument $doc
     * @param \DOMXPath $xpath
     * @return void
     */
    private static function strip_native_editing_controls(\DOMDocument $doc, \DOMXPath $xpath): void {
        // Section "⋮" actions menu (View / Edit section / Permalink, etc).
        $sectionmenus = $xpath->query('//*[@data-region="sectionactionsmmenu"]');
        foreach ($sectionmenus as $el) {
            $el->parentNode->removeChild($el);
        }

        // Activity "⋮" actions menu (Edit settings / Duplicate / Delete, etc).
        $activitymenus = $xpath->query('//*[@data-region="actionmenu"]');
        foreach ($activitymenus as $el) {
            $el->parentNode->removeChild($el);
        }

        // The "+" divider between activities that opens the real "add an
        // activity or resource"/"add subsection" chooser, PLUS the
        // section-level "add a new section" control at the end of the
        // section list — two visually similar but structurally distinct
        // controls (the activity one is a <button>, the section one an
        // <a> to changenumsections.php), both real, both matched here by
        // class/data-region rather than tag name so neither slips through.
        $dividers = $xpath->query(
            '//*[@data-region="section-addsection"]' .
            ' | //*[contains(concat(" ", normalize-space(@class), " "), " divider ")]' .
            '[.//*[contains(concat(" ", normalize-space(@class), " "), " add-content ")]' .
            ' or .//*[contains(@data-action,"open-chooser")]]'
        );
        foreach ($dividers as $el) {
            $el->parentNode->removeChild($el);
        }

        // Section/activity name inline-rename widgets (the pencil icon,
        // wired to core_update_inplace_editable). Only the rename TRIGGER
        // is removed — any separate plain view link inside the same
        // wrapper (e.g. an activity's own "view this activity" link) is
        // left untouched since it's read-only navigation, not an edit
        // affordance. If removing the trigger would leave the wrapper with
        // no visible text at all (true for section names, which have no
        // separate view link), its own data-value attribute — the plain
        // name Moodle itself already computed — becomes a plain text node
        // so the name keeps showing.
        $inplace = $xpath->query('//*[@data-inplaceeditable]');
        foreach ($inplace as $span) {
            $renamelinks = $xpath->query('.//a[@data-inplaceeditablelink]', $span);
            foreach ($renamelinks as $link) {
                $link->parentNode->removeChild($link);
            }
            if (trim($span->textContent) === '') {
                $span->appendChild($doc->createTextNode($span->getAttribute('data-value')));
            }
        }

        // Activity completion info/edit widget — a dropdown showing the
        // real completion requirements ("Students must: View...") plus an
        // "Edit conditions" link straight to that activity's real settings
        // page (course/modedit.php). Neither belongs here: it's real-course
        // completion status/editing, unrelated to how the template's AI
        // behavior is configured.
        $completionwidgets = $xpath->query('//*[@data-region="activity-information"]');
        foreach ($completionwidgets as $el) {
            $el->parentNode->removeChild($el);
        }
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
     * Only ever offers "Modify" for a module type the AI generator can
     * actually produce today (mock_template_ai_service::SUPPORTED) — an
     * admin must never be able to pick an option that generation will
     * silently fail on later. Every other type (e.g. resource/file modules,
     * or a lesson/feedback activity until Phase 2 adds support) only offers
     * Keep / Reference / Exclude, and defaults to Keep instead of Modify.
     *
     * @param int $cmid
     * @param string $modname
     * @return string
     */
    private static function build_activity_dropdown(int $cmid, string $modname): string {
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
        $cansupportmodify = in_array($modname, mock_template_ai_service::SUPPORTED, true);
        if (!$cansupportmodify) {
            unset($labels['modify']);
        }
        $default = $cansupportmodify ? 'modify' : 'keep';

        $html = '<button class="btn btn-sm btn-link dropdown-toggle p-0" '
            . 'style="color:#0f6cbf;text-decoration:none" '
            . 'data-toggle="dropdown" title="' . s($tips[$default]) . '">'
            . $labels[$default] . '</button>';
        $html .= '<div class="dropdown-menu dropdown-menu-right">';
        foreach ($labels as $key => $label) {
            $active = $key === $default ? 'active' : '';
            $html .= '<a class="dropdown-item ' . $active . '" href="#" '
                . 'data-act-val="' . $key . '" '
                . 'title="' . s($tips[$key]) . '">' . $label . '</a>';
        }
        $html .= '</div>';
        return $html;
    }
}

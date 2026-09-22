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

namespace local_coursegen\local\preview\glossary;

use context;
use core_text;
use local_coursegen\local\preview\json_store;
use stdClass;
use url_select;

/**
 * mod_glossary's view code, ported to run against the payload.
 *
 * Copied from mod/glossary/view.php, tabs.php, sql.php, lib.php,
 * classes/output/standard_action_bar.php and formats/dictionary (Moodle 4.5),
 * for the browse-by-letter view every glossary opens on. Method names are the
 * functions they came from. What changed: entries are read from a json_store
 * and never written; the context is handed in; every link the glossary draws
 * to itself is built by a callable handed in; the views a preview never shows
 * (search, category, author, date, approval, import, export) are left out,
 * and every button or icon that would open one of them, or edit the glossary,
 * keeps its place and its look but leads back to the preview: nothing in a
 * preview may reach the template course's real glossary.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view {
    use glossary_entry_view;
    use glossary_entry_icons;
    use glossary_action_bar;
    use glossary_tab_tools;
    use glossary_navigation;
    use glossary_paging;

    /** mod/glossary/lib.php. */
    const GLOSSARY_STANDARD_VIEW = 0;
    /** mod/glossary/lib.php. */
    const GLOSSARY_CATEGORY_VIEW = 1;
    /** mod/glossary/lib.php. */
    const GLOSSARY_DATE_VIEW = 2;
    /** mod/glossary/lib.php. */
    const GLOSSARY_AUTHOR_VIEW = 3;
    /** mod/glossary/lib.php. */
    const GLOSSARY_STANDARD = 'standard';
    /** mod/glossary/lib.php. */
    const GLOSSARY_AUTHOR = 'author';
    /** mod/glossary/lib.php. */
    const GLOSSARY_CATEGORY = 'category';
    /** mod/glossary/lib.php. */
    const GLOSSARY_DATE = 'date';

    /** @var json_store */
    protected json_store $store;
    /** @var stdClass */
    protected stdClass $glossary;
    /** @var stdClass */
    protected stdClass $cm;
    /** @var stdClass */
    protected stdClass $course;
    /** @var context */
    protected context $context;
    /** @var stdClass The glossary_formats row for the display format. */
    protected stdClass $displayformat;
    /** @var callable array of params => moodle_url, links into the preview. */
    protected $urls;

    /**
     * Constructor.
     *
     * @param stdClass $glossary
     * @param stdClass $cm
     * @param stdClass $course
     * @param context $context
     * @param json_store $store
     * @param stdClass $displayformat
     * @param callable $urls
     */
    public function __construct(
        stdClass $glossary,
        stdClass $cm,
        stdClass $course,
        context $context,
        json_store $store,
        stdClass $displayformat,
        callable $urls
    ) {
        $this->glossary = $glossary;
        $this->cm = $cm;
        $this->course = $course;
        $this->context = $context;
        $this->store = $store;
        $this->displayformat = $displayformat;
        $this->urls = $urls;
    }

    /**
     * A link the glossary would draw to its own view page.
     *
     * @param array $params What view.php would be given.
     * @return string
     */
    protected function url(array $params): string {
        return ($this->urls)($params)->out(false);
    }

    /**
     * mod/glossary/view.php from the action bar to the end, browsing by letter.
     *
     * @param string $hook The letter, ALL or SPECIAL.
     * @param int $page
     * @return string
     */
    public function page(string $hook = 'ALL', int $page = 0): string {
        global $OUTPUT;

        $mode = 'letter';
        $sortkey = '';
        $sortorder = '';
        $tab = self::GLOSSARY_STANDARD_VIEW;
        $showcommonelements = 1;
        if (!$hook) {
            $hook = 'ALL';
        }
        $entriesbypage = $this->glossary->entbypage;
        $offset = $page * $entriesbypage;

        $out = '';
        // echo $renderer->main_action_bar($actionbar);
        $out .= $OUTPUT->render_from_template(
            'mod_glossary/standard_action_menu',
            $this->standard_action_bar_data($mode, $hook, $sortkey, $sortorder, $offset, $entriesbypage, $tab)
        );

        // All this depends if whe have $showcommonelements
        // Start to print glossary controls. The approval link is drawn only on
        // a page without secondary navigation; a module page has it.
        // require("tabs.php");
        $out .= $OUTPUT->render_from_template('local_coursegen/preview_glossary_controls', [
            'alphabetmenu' => $this->glossary_print_alphabet_menu($mode, $hook, $sortkey, $sortorder),
        ]);

        // require("sql.php"); browsing by letter.
        $fullpivot = false;
        $printpivot = true;
        $pivotkey = 'concept';
        [$allentries, $count] = $this->glossary_get_entries_by_letter($hook, $offset, $entriesbypage);

        /// printing the entries
        $entriesshown = 0;
        $currentpivot = '';
        $paging = null;
        $fmtoptions = ['context' => $this->context];

        if ($allentries) {

            //Decide if we must show the ALL link in the pagebar
            $specialtext = '';
            if ($this->glossary->showall) {
                $specialtext = get_string("allentries", "glossary");
            }

            //Build paging bar
            $baseurl = $this->url(['mode' => $mode, 'hook' => $hook, 'sortkey' => $sortkey, 'sortorder' => $sortorder,
                'fullsearch' => 0]);
            $paging = self::glossary_get_paging_bar($count, $page, $entriesbypage, $baseurl . '&amp;',
                9999, 10, '&nbsp;&nbsp;', $specialtext, -1);

            $out .= $OUTPUT->render_from_template('local_coursegen/preview_container', [
                'classes' => 'paging',
                'content' => $paging,
            ]);

            foreach ($allentries as $entry) {

                // Setting the pivot for the current entry
                if ($printpivot) {
                    $pivot = $entry->{$pivotkey};
                    $upperpivot = core_text::strtoupper($pivot);
                    $pivottoshow = core_text::strtoupper(format_string($pivot, true, $fmtoptions));

                    // Reduce pivot to 1cc if necessary.
                    if (!$fullpivot) {
                        $upperpivot = core_text::substr($upperpivot, 0, 1);
                        $pivottoshow = core_text::substr($pivottoshow, 0, 1);
                    }

                    // If there's a group break.
                    if ($currentpivot != $upperpivot) {
                        $currentpivot = $upperpivot;

                        // print the group break if apply
                        $out .= $OUTPUT->render_from_template('local_coursegen/preview_glossary_category_header', [
                            'headinghtml' => $OUTPUT->heading($pivottoshow, 3),
                        ]);
                    }
                }

                /// and finally print the entry.
                $out .= $this->glossary_print_entry($entry, $mode, $hook, 1, $this->glossary->displayformat);
                $entriesshown++;
            }
        }
        if (!$entriesshown) {
            $out .= $OUTPUT->box(get_string("noentries", "glossary"), "generalbox boxaligncenter boxwidthwide");
        }

        $out .= $OUTPUT->render_from_template('local_coursegen/preview_glossary_footer', ['paging' => $paging]);

        return $out;
    }
}

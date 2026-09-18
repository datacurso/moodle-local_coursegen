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
use html_writer;
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
        $out .= '<div class="glossarycontrol" style="text-align: right">';
        $out .= '';
        $out .= '</div><br />';

        // require("tabs.php");
        $out .= html_writer::start_div('entrybox');
        $out .= $this->glossary_print_alphabet_menu($mode, $hook, $sortkey, $sortorder);
        $out .= html_writer::empty_tag('hr');
        $out .= html_writer::end_div();

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

            $out .= '<div class="paging">';
            $out .= $paging;
            $out .= '</div>';

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

                        $out .= '<div>';
                        $out .= '<table cellspacing="0" class="glossarycategoryheader">';

                        $out .= '<tr>';
                        $out .= '<th >';

                        $out .= $OUTPUT->heading($pivottoshow, 3);
                        $out .= "</th></tr></table></div>\n";
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

        if ($paging) {
            $out .= '<hr />';
            $out .= '<div class="paging">';
            $out .= $paging;
            $out .= '</div>';
        }
        $out .= '<br />';

        return $out;
    }

    /**
     * mod/glossary/lib.php glossary_get_entries_by_letter(), against the store.
     *
     * Approved entries, filtered by the first letter of the concept, ordered
     * by concept then id, one page of them.
     *
     * @param string $letter
     * @param int $from
     * @param int $limit
     * @return array [entries, count]
     */
    protected function glossary_get_entries_by_letter(string $letter, int $from, int $limit): array {
        $all = [];
        foreach ($this->store->get_records('glossary_entries', ['glossaryid' => $this->glossary->id]) as $entry) {
            if (empty($entry->approved)) {
                continue;
            }
            $first = core_text::strtoupper(core_text::substr((string) $entry->concept, 0, 1));
            if ($letter != 'ALL' && $letter != 'SPECIAL' && core_text::strlen($letter)) {
                if ($first !== core_text::strtoupper($letter)) {
                    continue;
                }
            }
            if ($letter == 'SPECIAL') {
                $alphabet = explode(',', get_string('alphabet', 'langconfig'));
                if (in_array($first, array_map([core_text::class, 'strtoupper'], $alphabet), true)) {
                    continue;
                }
            }
            $all[] = $entry;
        }
        usort($all, static function (stdClass $a, stdClass $b): int {
            $order = strcmp(core_text::strtolower((string) $a->concept), core_text::strtolower((string) $b->concept));
            return $order !== 0 ? $order : ((int) $a->id <=> (int) $b->id);
        });
        $count = count($all);
        return [array_slice($all, $from, $limit ?: null), $count];
    }

    /**
     * mod/glossary/classes/output/standard_action_bar.php export_for_template().
     *
     * @param string $mode
     * @param string $hook
     * @param string $sortkey
     * @param string $sortorder
     * @param int $offset
     * @param int $pagelimit
     * @param int $tab
     * @return array
     */
    protected function standard_action_bar_data(
        string $mode,
        string $hook,
        string $sortkey,
        string $sortorder,
        int $offset,
        int $pagelimit,
        int $tab
    ): array {
        global $OUTPUT;
        return [
            'addnewbutton' => $this->create_add_button($OUTPUT),
            'searchbox' => $this->create_search_box($mode, $hook),
            'tools' => $this->get_additional_tools($OUTPUT, $mode, $hook, $sortkey, $sortorder, $offset, $pagelimit),
            'tabjumps' => $this->generate_tab_jumps($OUTPUT, $mode, $tab),
        ];
    }

    /**
     * standard_action_bar::create_search_box().
     *
     * @param string $mode
     * @param string $hook
     * @return array
     */
    protected function create_search_box(string $mode, string $hook): array {
        global $OUTPUT;
        $fullsearchchecked = true;

        $check = [
            'name' => 'fullsearch',
            'id' => 'fullsearch',
            'value' => '1',
            'checked' => $fullsearchchecked,
            'label' => get_string("searchindefinition", "glossary"),
        ];

        $checkbox = $OUTPUT->render_from_template('core/checkbox', $check);

        $hiddenfields = [
            (object) ['name' => 'id', 'value' => $this->cm->id],
            (object) ['name' => 'mode', 'value' => 'search'],
        ];
        $data = [
            'action' => ($this->urls)([]),
            'hiddenfields' => $hiddenfields,
            'otherfields' => $checkbox,
            'inputname' => 'hook',
            'query' => ($mode == 'search') ? s($hook) : '',
            'searchstring' => get_string('search'),
        ];

        return $data;
    }

    /**
     * standard_action_bar::create_add_button().
     *
     * @param \renderer_base $output
     * @return stdClass|null
     */
    protected function create_add_button(\renderer_base $output): ?stdClass {
        // Nobody may act on an activity that does not exist: the button that
        // adds an entry is not offered.
        return null;
    }

    /**
     * standard_action_bar::get_additional_tools().
     *
     * @param \renderer_base $output
     * @param string $mode
     * @param string $hook
     * @param string $sortkey
     * @param string $sortorder
     * @param int $offset
     * @param int $pagelimit
     * @return array
     */
    protected function get_additional_tools(
        \renderer_base $output,
        string $mode,
        string $hook,
        string $sortkey,
        string $sortorder,
        int $offset,
        int $pagelimit
    ): array {
        // The tools import, export, print and feed the real glossary, and
        // every one of them leaves the page. Nobody may act on an activity
        // that does not exist: none is offered. The search box beside them
        // stays.
        return [];
    }

    /**
     * standard_action_bar::generate_tab_jumps().
     *
     * @param \renderer_base $output
     * @param string $mode
     * @param int $tab
     * @return array|null
     */
    protected function generate_tab_jumps(\renderer_base $output, string $mode, int $tab) {
        $tabs = $this->glossary_get_visible_tabs($this->displayformat);
        $validtabs = [
            self::GLOSSARY_STANDARD => [
                'mode' => 'letter',
                'descriptor' => 'standardview',
            ],
            self::GLOSSARY_CATEGORY => [
                'mode' => 'cat',
                'descriptor' => 'categoryview',
            ],
            self::GLOSSARY_DATE => [
                'mode' => 'date',
                'descriptor' => 'dateview',
            ],
            self::GLOSSARY_AUTHOR => [
                'mode' => 'author',
                'descriptor' => 'authorview',
            ],
        ];

        $baseurl = ($this->urls)([]);
        $active = null;
        $options = [];
        foreach ($validtabs as $key => $tabinfo) {
            if (in_array($key, $tabs)) {
                $baseurl->params(['mode' => $tabinfo['mode']]);
                $active = $active ?? $baseurl->out(false);
                $active = ($tabinfo['mode'] == $mode ? $baseurl->out(false) : $active);
                $options[get_string($tabinfo['descriptor'], 'glossary')] = $baseurl->out(false);
            }
        }

        if ($tab < self::GLOSSARY_STANDARD_VIEW || $tab > self::GLOSSARY_AUTHOR_VIEW) {
            $options[get_string('edit')] = '#';
        }

        if (count($options) > 1) {
            $select = new url_select(array_flip($options), $active, null);
            $select->set_label(get_string('explainalphabet', 'glossary'), ['class' => 'sr-only']);
            return $select->export_for_template($output);
        }

        return null;
    }

    /**
     * mod/glossary/lib.php glossary_get_visible_tabs().
     *
     * @param stdClass $displayformat
     * @return array
     */
    protected function glossary_get_visible_tabs($displayformat) {
        if (empty($displayformat->showtabs)) {
            // glossary_set_default_visible_tabs(): the standard tab, and the
            // rest depending on the format, which a format row always has.
            $displayformat->showtabs = self::GLOSSARY_STANDARD;
        }
        $showtabs = preg_split('/,/', $displayformat->showtabs, -1, PREG_SPLIT_NO_EMPTY);

        return $showtabs;
    }

    /**
     * mod/glossary/lib.php glossary_print_alphabet_menu(), returning what it prints.
     *
     * @param string $mode
     * @param string $hook
     * @param string $sortkey
     * @param string $sortorder
     * @return string
     */
    protected function glossary_print_alphabet_menu($mode, $hook, $sortkey = '', $sortorder = '') {
        $out = '';
        if ($mode != 'date') {
            if ($this->glossary->showalphabet) {
                $out .= '<div class="glossaryexplain">' . get_string("explainalphabet", "glossary") . '</div><br />';
            }

            $out .= $this->glossary_print_special_links($mode, $hook);

            $out .= $this->glossary_print_alphabet_links($mode, $hook, $sortkey, $sortorder);

            $out .= $this->glossary_print_all_links($mode, $hook);
        }
        return $out;
    }

    /**
     * mod/glossary/lib.php glossary_print_all_links().
     *
     * @param string $mode
     * @param string $hook
     * @return string
     */
    protected function glossary_print_all_links($mode, $hook) {
        $out = '';
        if ($this->glossary->showall) {
            $strallentries       = get_string("allentries", "glossary");
            if ($hook == 'ALL') {
                $out .= "<b>$strallentries</b>";
            } else {
                $strexplainall = strip_tags(get_string("explainall", "glossary"));
                $out .= "<a title=\"$strexplainall\" href=\"" . $this->url(['mode' => $mode, 'hook' => 'ALL']) . "\">$strallentries</a>";
            }
        }
        return $out;
    }

    /**
     * mod/glossary/lib.php glossary_print_special_links().
     *
     * @param string $mode
     * @param string $hook
     * @return string
     */
    protected function glossary_print_special_links($mode, $hook) {
        $out = '';
        if ($this->glossary->showspecial) {
            $strspecial          = get_string("special", "glossary");
            if ($hook == 'SPECIAL') {
                $out .= "<b>$strspecial</b> | ";
            } else {
                $strexplainspecial = strip_tags(get_string("explainspecial", "glossary"));
                $out .= "<a title=\"$strexplainspecial\" href=\"" . $this->url(['mode' => $mode, 'hook' => 'SPECIAL']) . "\">$strspecial</a> | ";
            }
        }
        return $out;
    }

    /**
     * mod/glossary/lib.php glossary_print_alphabet_links().
     *
     * @param string $mode
     * @param string $hook
     * @param string $sortkey
     * @param string $sortorder
     * @return string
     */
    protected function glossary_print_alphabet_links($mode, $hook, $sortkey, $sortorder) {
        $out = '';
        if ($this->glossary->showalphabet) {
            $alphabet = explode(",", get_string('alphabet', 'langconfig'));
            for ($i = 0; $i < count($alphabet); $i++) {
                if ($hook == $alphabet[$i] and $hook) {
                    $out .= "<b>$alphabet[$i]</b>";
                } else {
                    $out .= "<a href=\"" . $this->url(['mode' => $mode, 'hook' => $alphabet[$i], 'sortkey' => $sortkey, 'sortorder' => $sortorder]) . "\">$alphabet[$i]</a>";
                }
                $out .= ' | ';
            }
        }
        return $out;
    }

    /**
     * mod/glossary/lib.php glossary_print_entry(), returning what it prints.
     *
     * The dictionary format is the one ported; a glossary set to another
     * format is shown in it, which is the shape every format shares.
     *
     * @param stdClass $entry
     * @param string $mode
     * @param string $hook
     * @param int $printicons
     * @param string $displayformat
     * @return string
     */
    protected function glossary_print_entry($entry, $mode = '', $hook = '', $printicons = 1, $displayformat = -1) {
        global $USER;
        if ($entry->approved or ($USER->id == $entry->userid)) {
            return $this->glossary_show_entry_dictionary($entry, $mode, $hook, $printicons);
        }
        return '';
    }

    /**
     * mod/glossary/formats/dictionary/dictionary_format.php glossary_show_entry_dictionary().
     *
     * @param stdClass $entry
     * @param string $mode
     * @param string $hook
     * @param int $printicons
     * @param bool $aliases
     * @return string
     */
    protected function glossary_show_entry_dictionary($entry, $mode = '', $hook = '', $printicons = 1, $aliases = true) {
        global $OUTPUT;

        $out = '<table class="glossarypost dictionary" cellspacing="0">';
        $out .= '<tr valign="top">';
        $out .= '<td class="entry">';
        // glossary_print_entry_approval(): nothing outside approval mode.
        $out .= '<div class="concept">';
        $out .= $this->glossary_print_entry_concept($entry);
        $out .= '</div> ';
        $out .= $this->glossary_print_entry_definition($entry);
        // glossary_print_entry_attachment(): an entry the payload describes
        // carries no attachment.
        if (\core_tag_tag::is_enabled('mod_glossary', 'glossary_entries')) {
            $out .= $OUTPUT->tag_list([], null, 'glossary-tags');
        }
        $out .= '</td></tr>';
        $out .= '<tr valign="top"><td class="entrylowersection">';
        $out .= $this->glossary_print_entry_lower_section($entry, $mode, $hook, $printicons, $aliases);
        $out .= '</td>';
        $out .= '</tr>';
        $out .= "</table>\n";
        return $out;
    }

    /**
     * mod/glossary/lib.php glossary_print_entry_concept().
     *
     * @param stdClass $entry
     * @return string
     */
    protected function glossary_print_entry_concept($entry) {
        global $OUTPUT;

        $text = $OUTPUT->heading(format_string($entry->concept), 4);
        if (!empty($entry->highlight)) {
            $text = highlight($entry->highlight, $text);
        }
        return $text;
    }

    /**
     * mod/glossary/lib.php glossary_print_entry_definition(), with the context handed in.
     *
     * @param stdClass $entry
     * @return string
     */
    protected function glossary_print_entry_definition($entry) {
        global $GLOSSARY_EXCLUDEENTRY;

        $definition = $entry->definition;

        // Do not link self.
        $GLOSSARY_EXCLUDEENTRY = $entry->id;

        $context = $this->context;
        $definition = file_rewrite_pluginfile_urls($definition, 'pluginfile.php', $context->id, 'mod_glossary', 'entry', $entry->id);

        $options = new stdClass();
        $options->para = false;
        $options->trusted = $entry->definitiontrust ?? 0;
        $options->context = $context;
        $options->overflowdiv = true;

        $text = format_text($definition, $entry->definitionformat ?? FORMAT_HTML, $options);

        // Stop excluding concepts from autolinking
        unset($GLOSSARY_EXCLUDEENTRY);

        if (!empty($entry->highlight)) {
            $text = highlight($entry->highlight, $text);
        }
        if (isset($entry->footer)) {   // Unparsed footer info
            $text .= $entry->footer;
        }
        return $text;
    }

    /**
     * mod/glossary/lib.php glossary_print_entry_aliases(), against the store.
     *
     * @param stdClass $entry
     * @return string
     */
    protected function glossary_print_entry_aliases($entry) {
        $return = '';
        $aliases = [];
        foreach ($this->store->get_records('glossary_alias', ['entryid' => $entry->id]) as $alias) {
            $aliases[] = $alias->alias;
        }
        if ($aliases) {
            $id = "keyword-{$entry->id}";
            $return = html_writer::select($aliases, $id, '', false, ['id' => $id]);
        }
        return $return;
    }

    /**
     * mod/glossary/lib.php glossary_print_entry_icons(), the part a preview can answer.
     *
     * The icons a reader with the rights to manage entries sees. Exporting to
     * a main glossary, portfolios and comments are left out: each needs a
     * course, files or comments that only exist for an activity that does.
     *
     * @param stdClass $entry
     * @param string $mode
     * @param string $hook
     * @return string
     */
    protected function glossary_print_entry_icons($entry, $mode = '', $hook = '') {
        global $USER, $CFG, $OUTPUT;

        $context = $this->context;
        $glossary = $this->glossary;
        $cm = $this->cm;

        $output = false;   // To decide if we must really return text in "return". Activate when needed only!
        $importedentry = (($entry->sourceglossaryid ?? 0) == $glossary->id);

        $return = '<span class="commands">';
        // Differentiate links for each entry.
        $altsuffix = strip_tags(format_text($entry->concept));

        if (!$entry->approved) {
            $output = true;
            $return .= html_writer::tag('span', get_string('entryishidden', 'glossary'),
                array('class' => 'glossary-hidden-note'));
        }

        if ($entry->approved || has_capability('mod/glossary:approve', $context)) {
            $output = true;
            $return .= \html_writer::link(
                ($this->urls)(['eid' => $entry->id]),
                $OUTPUT->pix_icon('fp/link', get_string('entrylink', 'glossary', $altsuffix), 'theme'),
                ['title' => get_string('entrylink', 'glossary', $altsuffix), 'class' => 'icon']
            );
        }

        if (has_capability('mod/glossary:approve', $context) && !$glossary->defaultapproval && $entry->approved) {
            $output = true;
            $return .= '<a class="icon" title="' . get_string('disapprove', 'glossary') .
                       '" href="' . $this->url(['mode' => $mode, 'hook' => $hook]) .
                       '">' . $OUTPUT->pix_icon('t/block', get_string('disapprove', 'glossary')) . '</a>';
        }

        $iscurrentuser = (($entry->userid ?? 0) == $USER->id);

        if (has_capability('mod/glossary:manageentries', $context) or (isloggedin() and has_capability('mod/glossary:write', $context) and $iscurrentuser)) {
            $icon = 't/delete';
            $iconcomponent = 'moodle';
            if (!empty($entry->sourceglossaryid)) {
                $icon = 'minus';   // graphical metaphor (minus) for deleting an imported entry
                $iconcomponent = 'glossary';
            }

            //Decide if an entry is editable:
            // -It isn't a imported entry (so nobody can edit a imported (from secondary to main) entry)) and
            // -The user is teacher or he is a student with time permissions (edit period or editalways defined).
            $ineditperiod = ((time() - ($entry->timecreated ?? 0) <  $CFG->maxeditingtime) || $glossary->editalways);
            if (!$importedentry and (has_capability('mod/glossary:manageentries', $context) or (($entry->userid ?? 0) == $USER->id and ($ineditperiod and has_capability('mod/glossary:write', $context))))) {
                $output = true;
                $url = $this->url(['mode' => $mode, 'hook' => $hook]);
                $return .= "<a class='icon' title=\"" . get_string("delete") . "\" " .
                           "href=\"$url\">" . $OUTPUT->pix_icon($icon, get_string('deleteentrya', 'mod_glossary', $altsuffix), $iconcomponent) . '</a>';

                $url = $this->url(['mode' => $mode, 'hook' => $hook]);
                $return .= "<a class='icon' title=\"" . get_string("edit") . "\" href=\"$url\">" .
                           $OUTPUT->pix_icon('i/edit', get_string('editentrya', 'mod_glossary', $altsuffix)) . '</a>';
            } else if ($importedentry) {
                $return .= "<font size=\"-1\">" . get_string("exportedentry", "glossary") . "</font>";
            }
        }
        $return .= '</span>';

        //If we haven't calculated any REAL thing, delete result ($return)
        if (!$output) {
            $return = '';
        }
        return $return;
    }

    /**
     * mod/glossary/lib.php glossary_print_entry_lower_section(), returning what it prints.
     *
     * @param stdClass $entry
     * @param string $mode
     * @param string $hook
     * @param int $printicons
     * @param bool $aliases
     * @param bool $printseparator
     * @return string
     */
    protected function glossary_print_entry_lower_section($entry, $mode, $hook, $printicons, $aliases = true,
            $printseparator = true) {
        $out = '';
        if ($aliases) {
            $aliases = $this->glossary_print_entry_aliases($entry);
        }
        $icons   = '';
        if ($printicons) {
            $icons   = $this->glossary_print_entry_icons($entry, $mode, $hook);
        }
        if ($aliases || $icons || !empty($entry->rating)) {
            $out .= '<table>';
            if ($aliases) {
                $id = "keyword-{$entry->id}";
                $out .= '<tr valign="top"><td class="aliases">' .
                    '<label for="' . $id . '">' . get_string('aliases', 'glossary') . ': </label>' .
                    $aliases . '</td></tr>';
            }
            if ($icons) {
                $out .= '<tr valign="top"><td class="icons">' . $icons . '</td></tr>';
            }
            $out .= '</table>';

            if ($printseparator) {
                $out .= "<hr>\n";
            }
        }
        return $out;
    }

    /**
     * mod/glossary/lib.php glossary_get_paging_bar().
     *
     * @param int $totalcount
     * @param int $page
     * @param int $perpage
     * @param string $baseurl
     * @param int $maxpageallowed
     * @param int $maxdisplay
     * @param string $separator
     * @param string $specialtext
     * @param int $specialvalue
     * @param bool $previousandnext
     * @return string
     */
    public static function glossary_get_paging_bar($totalcount, $page, $perpage, $baseurl, $maxpageallowed = 99999, $maxdisplay = 20, $separator = "&nbsp;", $specialtext = "", $specialvalue = -1, $previousandnext = true) {

        $code = '';

        $showspecial = false;
        $specialselected = false;

        //Check if we have to show the special link
        if (!empty($specialtext)) {
            $showspecial = true;
        }
        //Check if we are with the special link selected
        if ($showspecial && $page == $specialvalue) {
            $specialselected = true;
        }

        //If there are results (more than 1 page)
        if ($totalcount > $perpage) {
            $code .= "<div style=\"text-align:center\">";
            $code .= "<p>" . get_string("page") . ":";

            $maxpage = (int)(($totalcount - 1) / $perpage);

            //Lower and upper limit of page
            if ($page < 0) {
                $page = 0;
            }
            if ($page > $maxpageallowed) {
                $page = $maxpageallowed;
            }
            if ($page > $maxpage) {
                $page = $maxpage;
            }

            //Calculate the window of pages
            $pagefrom = $page - ((int)($maxdisplay / 2));
            if ($pagefrom < 0) {
                $pagefrom = 0;
            }
            $pageto = $pagefrom + $maxdisplay - 1;
            if ($pageto > $maxpageallowed) {
                $pageto = $maxpageallowed;
            }
            if ($pageto > $maxpage) {
                $pageto = $maxpage;
            }

            //Some movements can be necessary if don't see enought pages
            if ($pageto - $pagefrom < $maxdisplay - 1) {
                if ($pageto - $maxdisplay + 1 > 0) {
                    $pagefrom = $pageto - $maxdisplay + 1;
                }
            }

            //Calculate first and last if necessary
            $firstpagecode = '';
            $lastpagecode = '';
            if ($pagefrom > 0) {
                $firstpagecode = "$separator<a href=\"{$baseurl}page=0\">1</a>";
                if ($pagefrom > 1) {
                    $firstpagecode .= "$separator...";
                }
            }
            if ($pageto < $maxpage) {
                if ($pageto < $maxpage - 1) {
                    $lastpagecode = "$separator...";
                }
                $lastpagecode .= "$separator<a href=\"{$baseurl}page=$maxpage\">" . ($maxpage + 1) . "</a>";
            }

            //Previous
            if ($page > 0 && $previousandnext) {
                $pagenum = $page - 1;
                $code .= "&nbsp;(<a  href=\"{$baseurl}page=$pagenum\">" . get_string("previous") . "</a>)&nbsp;";
            }

            //Add first
            $code .= $firstpagecode;

            $pagenum = $pagefrom;

            //List of maxdisplay pages
            while ($pagenum <= $pageto) {
                $pagetoshow = $pagenum + 1;
                if ($pagenum == $page && !$specialselected) {
                    $code .= "$separator<b>$pagetoshow</b>";
                } else {
                    $code .= "$separator<a href=\"{$baseurl}page=$pagenum\">$pagetoshow</a>";
                }
                $pagenum++;
            }

            //Add last
            $code .= $lastpagecode;

            //Next
            if ($page < $maxpage && $page < $maxpageallowed && $previousandnext) {
                $pagenum = $page + 1;
                $code .= "$separator(<a href=\"{$baseurl}page=$pagenum\">" . get_string("next") . "</a>)";
            }

            //Add special
            if ($showspecial) {
                $code .= '<br />';
                if ($specialselected) {
                    $code .= "$separator<b>$specialtext</b>";
                } else {
                    $code .= "$separator<a href=\"{$baseurl}page=$specialvalue\">$specialtext</a>";
                }
            }

            //End html
            $code .= "</p>";
            $code .= "</div>";
        }

        return $code;
    }
}

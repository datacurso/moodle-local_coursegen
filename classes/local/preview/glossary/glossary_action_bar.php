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

/**
 * mod_glossary's standard action bar and view tabs, built the same way
 * view.php builds them: the search box, the add-entry button (always disabled - a
 * preview writes nothing), the per-tab links, and which tabs a display
 * format shows at all. Kept apart from view.php only because together they
 * crossed the 250-line cap - both are the same "draw the page chrome"
 * concern, split for size alone.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait glossary_action_bar {

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
        $addnewbutton = $this->create_add_button($OUTPUT);
        $searchbox = $this->create_search_box($mode, $hook);
        $tools = $this->get_additional_tools($OUTPUT, $mode, $hook, $sortkey, $sortorder, $offset, $pagelimit);
        $tabjumps = $this->generate_tab_jumps($OUTPUT, $mode, $tab);
        return [
            'addnewbutton' => $addnewbutton,
            'searchbox' => $searchbox,
            'tools' => $tools,
            'tabjumps' => $tabjumps,
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

        $checklabel = get_string("searchindefinition", "glossary");
        $check = [
            'name' => 'fullsearch',
            'id' => 'fullsearch',
            'value' => '1',
            'checked' => $fullsearchchecked,
            'label' => $checklabel,
        ];

        $checkbox = $OUTPUT->render_from_template('core/checkbox', $check);

        $hiddenfields = [
            (object) ['name' => 'id', 'value' => $this->cm->id],
            (object) ['name' => 'mode', 'value' => 'search'],
        ];

        $query = '';
        if ($mode == 'search') {
            $query = s($hook);
        }
        $actionurl = ($this->urls)([]);
        $searchstring = get_string('search');
        $data = [
            'action' => $actionurl,
            'hiddenfields' => $hiddenfields,
            'otherfields' => $checkbox,
            'inputname' => 'hook',
            'query' => $query,
            'searchstring' => $searchstring,
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

}

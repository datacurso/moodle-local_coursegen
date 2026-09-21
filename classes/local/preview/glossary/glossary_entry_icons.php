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
 * mod_glossary's per-entry icon row (edit, delete, comment, and the rest a
 * reader with rights to manage entries would see), kept apart from
 * glossary_entry_view.php only because together they crossed the 250-line
 * cap - both are the same "print one entry" concern, split for size alone.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait glossary_entry_icons {
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

}

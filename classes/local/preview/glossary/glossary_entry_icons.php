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
        global $OUTPUT;

        $data = $this->glossary_entry_icons_data($entry, $mode, $hook);
        return $OUTPUT->render_from_template('local_coursegen/preview_glossary_entry_icons', $data);
    }

    /**
     * glossary_print_entry_icons(), as data for preview_glossary_entry_icons.mustache.
     *
     * @param stdClass $entry
     * @param string $mode
     * @param string $hook
     * @return array
     */
    protected function glossary_entry_icons_data($entry, string $mode, string $hook): array {
        global $OUTPUT;
        $context = $this->context;
        $glossary = $this->glossary;
        $formattedconcept = format_text($entry->concept);
        $altsuffix = strip_tags($formattedconcept);

        $data = ['show' => false, 'hidden' => false, 'hiddentext' => '', 'exportedtext' => ''];

        if (!$entry->approved) {
            $data['show'] = true;
            $data['hidden'] = true;
            $data['hiddentext'] = get_string('entryishidden', 'glossary');
        }

        if ($entry->approved || has_capability('mod/glossary:approve', $context)) {
            $data['show'] = true;
            $linkurl = ($this->urls)(['eid' => $entry->id]);
            $linkouturl = $linkurl->out(false);
            $linktitle = get_string('entrylink', 'glossary', $altsuffix);
            $linkiconhtml = $OUTPUT->pix_icon('fp/link', $linktitle, 'theme');
            $data['linkicon'] = [
                'url' => $linkouturl,
                'title' => $linktitle,
                'iconhtml' => $linkiconhtml,
            ];
        }

        if (has_capability('mod/glossary:approve', $context) && !$glossary->defaultapproval && $entry->approved) {
            $data['show'] = true;
            $disapproveurl = $this->url(['mode' => $mode, 'hook' => $hook]);
            $disapprovetitle = get_string('disapprove', 'glossary');
            $disapproveiconhtml = $OUTPUT->pix_icon('t/block', $disapprovetitle);
            $data['disapproveicon'] = [
                'url' => $disapproveurl,
                'title' => $disapprovetitle,
                'iconhtml' => $disapproveiconhtml,
            ];
        }

        $this->glossary_entry_icons_manage_data($data, $entry, $mode, $hook, $altsuffix);
        return $data;
    }

    /**
     * glossary_print_entry_icons(), the manage-entries icons (edit/delete,
     * or the "exported" note for an entry the reader may not edit).
     *
     * @param array $data Mutated in place.
     * @param stdClass $entry
     * @param string $mode
     * @param string $hook
     * @param string $altsuffix
     */
    protected function glossary_entry_icons_manage_data(array &$data, $entry, string $mode, string $hook, string $altsuffix): void {
        global $USER, $CFG, $OUTPUT;
        $context = $this->context;
        $glossary = $this->glossary;
        $sourceglossaryid = $entry->sourceglossaryid ?? 0;
        $importedentry = ($sourceglossaryid == $glossary->id);

        $canmanage = has_capability('mod/glossary:manageentries', $context);
        $entryuserid = $entry->userid ?? 0;
        $iscurrentuser = ($entryuserid == $USER->id);
        $canwriteown = isloggedin() && has_capability('mod/glossary:write', $context) && $iscurrentuser;
        if (!$canmanage && !$canwriteown) {
            return;
        }

        $icon = 't/delete';
        $iconcomponent = 'moodle';
        if (!empty($entry->sourceglossaryid)) {
            // Graphical metaphor (minus) for deleting an imported entry.
            $icon = 'minus';
            $iconcomponent = 'glossary';
        }

        // An entry is editable when it is not imported (so nobody can edit an
        // imported entry) and the reader may manage entries, or is its
        // author within the editing period (or the glossary always allows it).
        $entrytimecreated = $entry->timecreated ?? 0;
        $editingtimeelapsed = time() - $entrytimecreated;
        $ineditperiod = ($editingtimeelapsed < $CFG->maxeditingtime || $glossary->editalways);
        $editable = !$importedentry && ($canmanage || ($iscurrentuser && $ineditperiod && $canwriteown));

        if ($editable) {
            $data['show'] = true;
            $url = $this->url(['mode' => $mode, 'hook' => $hook]);
            $deletetitle = get_string('delete');
            $deletealt = get_string('deleteentrya', 'mod_glossary', $altsuffix);
            $deleteiconhtml = $OUTPUT->pix_icon($icon, $deletealt, $iconcomponent);
            $data['deleteicon'] = [
                'url' => $url,
                'title' => $deletetitle,
                'iconhtml' => $deleteiconhtml,
            ];
            $edittitle = get_string('edit');
            $editalt = get_string('editentrya', 'mod_glossary', $altsuffix);
            $editiconhtml = $OUTPUT->pix_icon('i/edit', $editalt);
            $data['editicon'] = [
                'url' => $url,
                'title' => $edittitle,
                'iconhtml' => $editiconhtml,
            ];
        } else if ($importedentry) {
            $data['show'] = true;
            $data['exportedtext'] = get_string('exportedentry', 'glossary');
        }
    }
}

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
 * mod_glossary's per-entry rendering, ported the same way view.php is: the
 * dictionary format's functions for drawing one entry - its concept, its
 * definition, its aliases, and the icon row a preview replaces every link
 * of with one that leads back into itself. Kept apart from view.php, which
 * is the browse-by-letter listing these entries are printed into.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait glossary_entry_view {
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

        // glossary_print_entry_approval(): nothing outside approval mode.
        // glossary_print_entry_attachment(): an entry the payload describes
        // carries no attachment.
        $tagshtml = '';
        if (\core_tag_tag::is_enabled('mod_glossary', 'glossary_entries')) {
            $tagshtml = $OUTPUT->tag_list([], null, 'glossary-tags');
        }

        return $OUTPUT->render_from_template('local_coursegen/preview_glossary_entry', [
            'concepthtml' => $this->glossary_print_entry_concept($entry),
            'definitionhtml' => $this->glossary_print_entry_definition($entry),
            'tagshtml' => $tagshtml,
            'lowersectionhtml' => $this->glossary_print_entry_lower_section($entry, $mode, $hook, $printicons, $aliases),
        ]);
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
        global $OUTPUT;
        $aliases = [];
        foreach ($this->store->get_records('glossary_alias', ['entryid' => $entry->id]) as $alias) {
            $aliases[] = $alias->alias;
        }
        if (!$aliases) {
            return '';
        }

        $options = [];
        foreach ($aliases as $index => $label) {
            $options[] = ['value' => $index, 'label' => $label];
        }
        return $OUTPUT->render_from_template('local_coursegen/preview_select', [
            'id' => "keyword-{$entry->id}",
            'options' => $options,
        ]);
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
        global $OUTPUT;

        $aliaseshtml = '';
        if ($aliases) {
            $aliaseshtml = $this->glossary_print_entry_aliases($entry);
        }
        $icons = '';
        if ($printicons) {
            $icons = $this->glossary_print_entry_icons($entry, $mode, $hook);
        }

        return $OUTPUT->render_from_template('local_coursegen/preview_glossary_lower_section', [
            'show' => $aliaseshtml !== '' || $icons !== '' || !empty($entry->rating),
            'aliasesid' => "keyword-{$entry->id}",
            'aliaseshtml' => $aliaseshtml,
            'icons' => $icons,
            'printseparator' => $printseparator,
        ]);
    }

}

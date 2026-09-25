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

namespace local_coursegen\local\backup;

use backup_nested_element;
use base_attribute;
use base_final_element;
use base_nested_element;
use base_processor;

/**
 * Reads a backup structure into nested arrays instead of writing it as XML.
 *
 * Every activity module declares the whole of itself in
 * backup/moodle2/backup_<mod>_stepslib.php: not only its own settings, but the
 * chapters, pages, questions, entries and answers that belong to it, each with
 * the columns that have to survive a restore. That declaration is the most
 * complete and the best maintained description of an activity that exists,
 * because it is the module's own, and because restore has to be able to
 * rebuild the activity from it.
 *
 * Backup walks that declaration with a visitor, and the walk does not know
 * what the visitor does with what it is shown: backup_nested_element::process()
 * asks only for a base_processor. The one Moodle ships writes XML into a file.
 * This one appends into arrays, which is the same information without the
 * round trip through a file and a parser.
 *
 * What it produces mirrors the XML exactly. An element's attributes and its
 * final elements become keys; a child element becomes a list under its own
 * name, with one entry per occurrence, so a book's chapters arrive as
 * chapters.chapter[] the way they are declared.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class structure_array_processor extends base_processor {
    /**
     * @var array The element currently being filled, innermost last.
     *
     * The walk is depth first and reports the opening and the closing of every
     * element, so the element a value belongs to is always the last one opened
     * and not yet closed.
     */
    protected array $stack = [];

    /** @var array The finished tree, once the outermost element closes. */
    protected array $result = [];

    /** @var array backup::VAR_* => value, read by the sources of the structure. */
    protected array $vars = [];

    /**
     * @var array Element name => the table it was read from.
     *
     * A structure says where each of its elements comes from, and that is
     * the piece the tree alone loses: a "page" under "pages" is a row of
     * lesson_pages, and code written against lesson_pages asks for it by that
     * name. An element read by a query rather than a table names the first
     * table the query reads, which is where its rows come from all the same.
     */
    protected array $tables = [];

    /**
     * @var array Element name => [column as declared => column in the table].
     *
     * An element may declare a column under another name (an "alias"), and
     * the tree carries the alias. Code written against the table wants the
     * column, so the way back is kept.
     */
    protected array $aliases = [];

    /**
     * Give one of the values a structure's sources are allowed to ask for.
     *
     * A source is written as "the rows of this table whose column matches the
     * activity being backed up", and the activity is named by these rather
     * than hardcoded, which is what lets one declaration serve every instance.
     *
     * @param int $key One of the backup::VAR_* constants.
     * @param mixed $value
     */
    public function set_var($key, $value) {
        $this->vars[$key] = $value;
    }

    /**
     * Read one of those values back.
     *
     * @param int $key
     * @return mixed
     */
    public function get_var($key) {
        return $this->vars[$key] ?? null;
    }

    /**
     * The tree, once the walk has finished.
     *
     * @return array
     */
    public function get_result(): array {
        return $this->result;
    }

    /**
     * Element name => table, for every element that was read from one.
     *
     * @return array
     */
    public function get_tables(): array {
        return $this->tables;
    }

    /**
     * Element name => declared column => table column, where they differ.
     *
     * @return array
     */
    public function get_aliases(): array {
        return $this->aliases;
    }

    /**
     * An element is starting: everything reported from here belongs to it.
     *
     * @param base_nested_element $nested
     */
    public function pre_process_nested_element(base_nested_element $nested) {
        $this->remember_source($nested);
        $node = [];
        foreach ($nested->get_attributes() as $attribute) {
            $node[$attribute->get_name()] = $attribute->get_value();
        }
        $files = [];
        if ($nested instanceof backup_nested_element) {
            $files = $nested->get_file_annotations();
        }
        $this->stack[] = [
            'name' => $nested->get_name(),
            'node' => $node,
            // Which file areas this element's text may refer to, declared by
            // the structure itself: this is what backup uses to know which
            // files to carry, and here it is what says how to name them.
            'files' => $files,
        ];
    }

    /**
     * Where this element's rows come from, the first time it is seen.
     *
     * @param base_nested_element $nested
     */
    protected function remember_source(base_nested_element $nested): void {
        $name = $nested->get_name();
        if (isset($this->tables[$name]) || !($nested instanceof backup_nested_element)) {
            return;
        }
        $table = $nested->get_source_table();
        if (!$table) {
            $sql = (string) $nested->get_source_sql();
            if ($sql !== '' && preg_match('~FROM\\s+\\{(\\w+)\\}~i', $sql, $found)) {
                $table = $found[1];
            }
        }
        if ($table) {
            $this->tables[$name] = $table;
        }
        // An alias is declared as "this column travels under that name" and
        // kept by the element as column => final element. There is no reader
        // for it, only a writer, so it is read the one way it can be.
        $property = new \ReflectionProperty(backup_nested_element::class, 'aliases');
        $property->setAccessible(true);
        foreach ((array) $property->getValue($nested) as $column => $final) {
            if (is_object($final) && method_exists($final, 'get_name')) {
                $this->aliases[$name][$final->get_name()] = $column;
            }
        }
    }

    /**
     * Nothing to do between an element's attributes and its contents.
     *
     * This is where backup annotates the files an element references, which is
     * bookkeeping for a restore that is going to happen. Nothing is being
     * restored here, so there is nothing to annotate.
     *
     * @param base_nested_element $nested
     */
    public function process_nested_element(base_nested_element $nested) {
        return;
    }

    /**
     * An element has finished: fold it into the one that contains it.
     *
     * @param base_nested_element $nested
     */
    public function post_process_nested_element(base_nested_element $nested) {
        $finished = array_pop($this->stack);
        if ($finished === null) {
            return;
        }
        $finished['node'] = $this->with_file_addresses($finished['node'], $finished['files']);

        if (!$this->stack) {
            $this->result = $finished['node'];
            return;
        }

        // One element can occur many times inside its parent - that is what a
        // book's chapters are - so every child is held as a list, whether it
        // occurred once or twenty times. A caller reading "the chapters" then
        // reads the same shape either way.
        $parent = array_pop($this->stack);
        $parent['node'][$finished['name']][] = $finished['node'];
        $this->stack[] = $parent;
    }

    /**
     * Text that names its files by a placeholder, renamed to where they are.
     *
     * A module keeps "@@PLUGINFILE@@/x.png" in its text and resolves it on the
     * way out with the file area the text belongs to. Backup keeps the
     * placeholder, because restore resolves it again; this tree is read by
     * things that own no file area, so it is resolved here, with the same
     * areas backup declares for the element and the same context the module
     * would use. Text with no placeholder is left exactly as it was.
     *
     * @param array $node
     * @param array $annotations component => filearea => info, as declared.
     * @return array
     */
    protected function with_file_addresses(array $node, array $annotations): array {
        if (!$annotations) {
            return $node;
        }
        foreach ($node as $key => $value) {
            if (!is_string($value) || strpos($value, '@@PLUGINFILE@@') === false) {
                continue;
            }
            $node[$key] = $this->rewritten_for_annotations($value, $annotations);
        }
        return $node;
    }

    /**
     * Rewrite one text value's file placeholders for every declared component/filearea.
     *
     * @param string $value
     * @param array $annotations component => filearea => info, as declared.
     * @return string
     */
    protected function rewritten_for_annotations(string $value, array $annotations): string {
        foreach ($annotations as $component => $areas) {
            $value = $this->rewritten_for_component($value, $component, $areas);
        }
        return $value;
    }

    /**
     * Rewrite one text value's file placeholders for one component's fileareas.
     *
     * @param string $value
     * @param string $component
     * @param array $areas filearea => info.
     * @return string
     */
    protected function rewritten_for_component(string $value, string $component, array $areas): string {
        foreach ($areas as $filearea => $info) {
            $contextid = $info->contextid ?? null;
            if ($contextid !== null) {
                $contextid = (int) $contextid;
            } else {
                $contextid = (int) $this->get_var(\backup::VAR_CONTEXTID);
            }
            $itemid = null;
            if (isset($info->element) && $info->element !== null) {
                $itemid = $info->element->get_value();
            }
            $value = file_rewrite_pluginfile_urls($value, 'pluginfile.php', $contextid, $component, $filearea, $itemid);
        }
        return $value;
    }

    /**
     * One value of the element being filled.
     *
     * @param base_final_element $final
     */
    public function process_final_element(base_final_element $final) {
        if (!$final->is_set() || !$this->stack) {
            return;
        }
        $index = count($this->stack) - 1;
        $this->stack[$index]['node'][$final->get_name()] = $final->get_value();
    }

    /**
     * Attributes are read when their element opens, so there is nothing here.
     *
     * The processor Moodle ships uses this to annotate ids for a later
     * restore; this one has no restore to prepare for.
     *
     * @param base_attribute $attribute
     */
    public function process_attribute(base_attribute $attribute) {
        return;
    }
}

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

use context;
use moodle_url;
use stdClass;

/**
 * What a module's own view code needs handed to it, to run against a payload.
 *
 * A module's view page reads its rows from the database, knows its course
 * module and its context, and builds links to itself. A preview runs that same
 * code against the payload, so the same four things are handed over from what
 * the payload carries: the rows through a json_store, a course module record
 * with the id the payload names, the module's own context when the activity
 * exists and the course's when it does not, and a way to build links that
 * stay inside the preview.
 *
 * Every preview that runs a module's own view code against the payload
 * extends this. What it is handed is the activity's own parameters: the tree
 * of rows travels in them, so nothing else is read to complete them.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class preview_base extends activity_preview {
    use preview_base_module_intro;

    /** @var json_store|null The activity's rows, once built. */
    protected ?json_store $store = null;

    /**
     * The module's short name, for tables and components.
     *
     * @return string
     */
    abstract protected function modname(): string;

    /**
     * The activity's rows, read from its own tree.
     *
     * @return json_store
     */
    protected function store(): json_store {
        if ($this->store === null) {
            $this->store = json_store::from_parameters($this->parameters);
        }
        return $this->store;
    }

    /**
     * The module's own row.
     *
     * @return stdClass|null Null when the result holds no row of the module.
     */
    protected function instance(): ?stdClass {
        $store = $this->store();
        $modname = $this->modname();
        $rows = $store->get_records($modname);
        if (!$rows) {
            return null;
        }
        $row = reset($rows);
        // A module's code reads the activity's name off its row, and a draft
        // may have renamed it.
        if (!empty($this->parameters['name'])) {
            $row->name = (string) $this->parameters['name'];
        }
        return $row;
    }

    /**
     * The module's own row, as the page's activity record.
     *
     * @return stdClass|null
     */
    public function activity_record(): ?stdClass {
        $instance = $this->instance();
        if ($instance === null) {
            return null;
        }
        return clone $instance;
    }

    /**
     * The course module, as the module's code expects to be handed it.
     *
     * @return stdClass
     */
    protected function cm(): stdClass {
        $instance = $this->instance();

        $courseid = $instance->course ?? 0;
        $courseid = (int) $courseid;

        $instanceid = $instance->id ?? 0;
        $instanceid = (int) $instanceid;

        $name = $instance->name ?? null;
        if ($name === null) {
            $name = $this->name();
        }
        $name = (string) $name;

        $modname = $this->modname();

        return (object) [
            'id' => $this->cmid,
            'course' => $courseid,
            'instance' => $instanceid,
            'name' => $name,
            'modname' => $modname,
        ];
    }

    /**
     * The course, as far as a module's view reads it.
     *
     * @return stdClass
     */
    protected function course(): stdClass {
        global $PAGE;
        return $PAGE->course;
    }

    /**
     * The context the module's code formats its text in.
     *
     * An activity that exists has its own, and the payload names it; one that
     * does not is formatted in its course's, which is where its filters come
     * from anyway.
     *
     * @return context
     */
    protected function context(): context {
        global $PAGE;
        $structure = $this->parameters['structure'] ?? [];
        if (!empty($structure['contextid'])) {
            $contextid = (int) $structure['contextid'];
            $context = context::instance_by_id($contextid, IGNORE_MISSING);
            if ($context) {
                return $context;
            }
        }
        return $PAGE->context;
    }

    /**
     * A link to this preview with extra parameters, where the module linked to itself.
     *
     * @param array $params
     * @return moodle_url
     */
    protected function url_to(array $params = []): moodle_url {
        $url = new moodle_url($this->here);
        foreach ($params as $name => $value) {
            $url->param($name, $value);
        }
        return $url;
    }

    /**
     * The description, as the activity header shows it by default.
     *
     * core\output\activity_header formats the activity record's intro with
     * format_module_intro() unless the module says otherwise; a module that
     * does say otherwise overrides this.
     *
     * @return string
     */
    public function header_description(): string {
        $instance = $this->instance();
        if ($instance === null) {
            return '';
        }
        $intro = $instance->intro ?? '';
        $intro = (string) $intro;
        $intro = trim($intro);
        if ($intro === '') {
            return '';
        }
        return $this->module_intro($instance);
    }

    /**
     * The activity's files, for code that asks the file storage for them.
     *
     * @return json_file_storage
     */
    protected function files(): json_file_storage {
        $files = $this->parameters['files'] ?? [];
        $files = (array) $files;
        return new json_file_storage($files);
    }

    /**
     * The plain text of an editor-field value, whatever shape it arrived in.
     *
     * A drafted field travels either as the plain string or as the
     * {text, format} pair Moodle's editors use, depending on the type and on
     * whether the value came from the draft or from the finished answer.
     *
     * @param mixed $value
     * @return string
     */
    protected static function editor_field_text($value): string {
        if (is_array($value)) {
            return (string) ($value['text'] ?? '');
        }
        return (string) ($value ?? '');
    }

    /**
     * The format of an editor-field value, whatever shape it arrived in.
     *
     * @param mixed $value
     * @return int
     */
    protected static function editor_field_format($value): int {
        if (is_array($value)) {
            return (int) ($value['format'] ?? FORMAT_HTML);
        }
        return FORMAT_HTML;
    }

    /**
     * One row of a module's configuration, which is the site's and not the activity's.
     *
     * A module can keep part of how it is set up in a table of its own that
     * describes the site's options rather than any activity: mod_glossary's
     * display formats are one. Those rows are configuration, read the way
     * get_config() is, and are the one thing this class reads from the
     * database; nothing about the activity itself comes this way.
     *
     * @param string $table
     * @param array $conditions
     * @return stdClass|false
     */
    protected function config_record(string $table, array $conditions) {
        global $DB;
        return $DB->get_record($table, $conditions);
    }

}

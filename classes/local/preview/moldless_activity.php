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

/**
 * An activity the run writes from nothing: no mould, so no tree of rows.
 *
 * An activity written into a mould carries the mould's tree with what the AI
 * wrote laid into its rows, and its preview runs the module's own view code
 * against those rows. One that has no mould (the template names the type and
 * the AI writes all of it) carries no tree, only what the AI wrote, in the
 * shape of the module's own form: the name, the description, the page's
 * text, the book's chapters.
 *
 * That is still a complete description of the activity, so for the types whose
 * view can be drawn from it the same rows are put together from it here, and
 * the preview that exists for the type draws them. A type whose view needs the
 * activity to exist (a course module to read from, a package, the question
 * bank) cannot be drawn that way, and is shown as its description instead.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moldless_activity {
    /** @var int The id the single row of the module gets; nothing else refers to it. */
    private const ROW_ID = 1;

    /**
     * Whether the finished activity was written without a mould.
     *
     * The template says which record it was built on; an activity whose
     * behaviour names none (source cmid 0) has nothing to complete a tree
     * from, and was never meant to carry one. An activity whose behaviour does
     * not say is not assumed to be one: that is a result of before the tree
     * travelled with it.
     *
     * @param array $activity One of the result's generated activities.
     * @return bool
     */
    public static function is_moldless(array $activity): bool {
        $templatebehavior = $activity['template_behavior'] ?? [];
        if (!is_array($templatebehavior) || !array_key_exists('template_source_cmid', $templatebehavior)) {
            return false;
        }
        $sourcecmid = $templatebehavior['template_source_cmid'];
        return (int) $sourcecmid <= 0 && ($templatebehavior['action'] ?? '') === 'instance';
    }

    /**
     * Whether a type can be drawn from its description alone.
     *
     * @param string $modname
     * @return bool
     */
    public static function can_draw(string $modname): bool {
        return in_array($modname, ['page', 'book', 'assign'], true);
    }

    /**
     * The parameters with the tree of rows put together from what the AI wrote.
     *
     * A type that cannot be drawn from its description is returned as it is.
     *
     * @param string $modname
     * @param array $parameters
     * @return array
     */
    public static function with_tree(string $modname, array $parameters): array {
        $builder = 'tree_of_' . $modname;
        if (!self::can_draw($modname) || !method_exists(self::class, $builder)) {
            return $parameters;
        }
        [$structure, $tables] = self::$builder($parameters);
        $parameters['structure'] = $structure;
        $parameters['structure_tables'] = $tables;
        $parameters['structure_aliases'] = [];
        return $parameters;
    }

    /**
     * The text of an editor field, whatever shape it arrived in.
     *
     * @param mixed $value
     * @return string
     */
    private static function text_of($value): string {
        if (is_array($value)) {
            return (string) ($value['text'] ?? '');
        }
        return (string) ($value ?? '');
    }

    /**
     * The columns every module row shares.
     *
     * @param array $parameters
     * @return array
     */
    private static function common_columns(array $parameters): array {
        return [
            'id' => self::ROW_ID,
            'course' => 0,
            'name' => (string) ($parameters['name'] ?? ''),
            'intro' => self::text_of($parameters['introeditor'] ?? ''),
            'introformat' => FORMAT_HTML,
        ];
    }

    /**
     * A page: its text is the editor field named after the module.
     *
     * @param array $parameters
     * @return array [structure, tables]
     */
    private static function tree_of_page(array $parameters): array {
        $options = [
            'printintro' => (int) ($parameters['printintro'] ?? 0),
            'printlastmodified' => (int) ($parameters['printlastmodified'] ?? 1),
        ];
        $row = self::common_columns($parameters) + [
            'content' => self::text_of($parameters['page'] ?? ''),
            'contentformat' => FORMAT_HTML,
            'display' => (int) ($parameters['display'] ?? 5),
            'displayoptions' => serialize($options),
            'revision' => 1,
            'timemodified' => time(),
        ];
        return [['page' => [$row]], ['page' => 'page']];
    }

    /**
     * A book: its chapters are in order, a sub-chapter following the chapter it belongs to.
     *
     * @param array $parameters
     * @return array [structure, tables]
     */
    private static function tree_of_book(array $parameters): array {
        $modsettings = $parameters['mod_settings'] ?? [];
        $chapters = [];
        $position = 0;
        foreach ((array) ($modsettings['chapters'] ?? []) as $chapter) {
            $position++;
            $chapters[] = [
                'id' => $position,
                'pagenum' => $position,
                'subchapter' => (int) ($chapter['subchapter'] ?? 0),
                'title' => (string) ($chapter['title'] ?? ''),
                'content' => self::text_of($chapter['content_editor'] ?? ($chapter['content'] ?? '')),
                'contentformat' => FORMAT_HTML,
                'hidden' => 0,
            ];
        }
        $row = self::common_columns($parameters) + [
            'numbering' => (int) ($parameters['numbering'] ?? 1),
            'navstyle' => (int) ($parameters['navstyle'] ?? 1),
            'customtitles' => (int) ($parameters['customtitles'] ?? 0),
            'revision' => 1,
            'chapters' => [['chapter' => $chapters]],
        ];
        $tables = ['book' => 'book', 'chapter' => 'book_chapters'];
        return [['book' => [$row]], $tables];
    }

    /**
     * An assignment: the settings of the form, and the submission plugins that are turned on.
     *
     * @param array $parameters
     * @return array [structure, tables]
     */
    private static function tree_of_assign(array $parameters): array {
        // The form's own settings are the module's columns (submissiondrafts, duedate, teamsubmission...), so every
        // plain value of the form is one, as the view reads whichever of them it needs.
        $row = self::common_columns($parameters) + [
            'activity' => self::text_of($parameters['activityeditor'] ?? ''),
            'activityformat' => FORMAT_HTML,
            'timelimit' => 0,
            'submissiondrafts' => 0,
            'teamsubmission' => 0,
            'duedate' => 0,
            'cutoffdate' => 0,
        ];
        foreach ($parameters as $column => $value) {
            if (is_scalar($value) && !in_array($column, ['id', 'name', 'intro', 'introformat', 'activity', 'activityformat'], true)) {
                $row[$column] = $value;
            }
        }
        $configs = [];
        foreach ($parameters as $key => $value) {
            if (!preg_match('~^(assignsubmission|assignfeedback)_([a-z0-9]+)_enabled$~', (string) $key, $match)) {
                continue;
            }
            $configs[] = [
                'id' => count($configs) + 1,
                'subtype' => $match[1],
                'plugin' => $match[2],
                'name' => 'enabled',
                'value' => (string) $value,
            ];
        }
        $row['plugin_configs'] = [['plugin_config' => $configs]];
        $tables = ['assign' => 'assign', 'plugin_config' => 'assign_plugin_config'];
        return [['assign' => [$row]], $tables];
    }
}

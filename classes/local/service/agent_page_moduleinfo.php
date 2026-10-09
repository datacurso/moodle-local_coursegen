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

namespace local_coursegen\local\service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/resourcelib.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Turns the page the template agent wrote into the data add_moduleinfo needs to create it.
 *
 * The agent returns the html it wrote plus the backup structure of the template page. The way the page is shown
 * comes from that structure and the visibility and completion from the template activity.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class agent_page_moduleinfo {
    /** @var string Availability of a new activity: the conditions of the template point at its own activities. */
    private const EMPTY_AVAILABILITY = '{"op":"&","c":[],"showc":[]}';

    /** @var int Width of the pop-up when the template page does not say. */
    private const DEFAULT_POPUP_WIDTH = 620;

    /** @var int Height of the pop-up when the template page does not say. */
    private const DEFAULT_POPUP_HEIGHT = 450;

    /** @var string[] Pieces of the backup structure that add_moduleinfo has no use for. */
    private const BACKUP_KEYS = ['structure', 'structure_tables', 'structure_aliases', 'files'];

    /**
     * The data of the page, ready for add_moduleinfo.
     *
     * @param array $parameters The parameters of the generated activity: name, content, intro and the backup structure.
     * @param int $sourcecmid The course module of the template page, 0 when it is not known.
     * @return array The module info of the new page.
     * @throws \moodle_exception When the agent left the page without a name or without text.
     */
    public static function build(array $parameters, int $sourcecmid): array {
        $rawname = $parameters['name'] ?? '';
        $name = trim((string) $rawname);
        $rawcontent = $parameters['content'] ?? '';
        $content = (string) $rawcontent;
        $visiblecontent = trim($content);
        if ($name === '' || $visiblecontent === '') {
            throw new \moodle_exception('error_missing_parameters', 'local_coursegen');
        }

        $rawintro = $parameters['intro'] ?? '';
        $intro = (string) $rawintro;
        $row = self::template_page_row($parameters);
        $display = self::display_settings($row);
        $activity = self::activity_settings($sourcecmid);

        $info = self::without_backup_keys($parameters);
        $info['modulename'] = 'page';
        $info['name'] = $name;
        $info['introeditor'] = ['text' => $intro, 'format' => FORMAT_HTML, 'itemid' => 0];
        $info['page'] = ['text' => $content, 'format' => FORMAT_HTML, 'itemid' => 0];
        $info['tags'] = [];
        $info['lang'] = '';

        return array_merge($info, $display, $activity);
    }

    /**
     * The first page record of the backup structure, empty when the structure has none.
     *
     * @param array $parameters The parameters of the generated activity.
     * @return array The page record of the template.
     */
    private static function template_page_row(array $parameters): array {
        $structure = $parameters['structure'] ?? [];
        if (!is_array($structure)) {
            return [];
        }
        $pages = $structure['page'] ?? [];
        if (!is_array($pages) || !isset($pages[0]) || !is_array($pages[0])) {
            return [];
        }
        return $pages[0];
    }

    /**
     * How the page is shown: the display mode and the options saved with the template page.
     *
     * @param array $row The page record of the template.
     * @return array The display fields of the module info.
     */
    private static function display_settings(array $row): array {
        $rawdisplay = $row['display'] ?? RESOURCELIB_DISPLAY_OPEN;
        $display = (int) $rawdisplay;
        $rawoptions = $row['displayoptions'] ?? '';
        $options = (string) $rawoptions;
        $width = self::option_of($options, 'popupwidth', (string) self::DEFAULT_POPUP_WIDTH);
        $height = self::option_of($options, 'popupheight', (string) self::DEFAULT_POPUP_HEIGHT);
        $printintro = self::option_of($options, 'printintro', '0');
        $printlastmodified = self::option_of($options, 'printlastmodified', '1');

        return [
            'display' => $display,
            'popupwidth' => (int) $width,
            'popupheight' => (int) $height,
            'printintro' => $printintro,
            'printlastmodified' => $printlastmodified,
        ];
    }

    /**
     * One whole number out of the serialized display options, read without unserializing anything.
     *
     * @param string $options The serialized options, for example a:1:{s:10:"printintro";s:1:"1";}.
     * @param string $key The name of the option.
     * @param string $default The value when the option is not there or the text is not a serialized array.
     * @return string The value of the option.
     */
    private static function option_of(string $options, string $key, string $default): string {
        $quoted = preg_quote($key, '/');
        $pattern = '/s:\d+:"' . $quoted . '";(?:s:\d+:"(\d+)"|i:(\d+));/';
        if (!preg_match($pattern, $options, $found)) {
            return $default;
        }
        $text = $found[1];
        if ($text === '' && isset($found[2])) {
            $text = $found[2];
        }
        return $text;
    }

    /**
     * Visibility and completion of the template activity; the defaults when it is gone.
     *
     * @param int $sourcecmid The course module of the template page, 0 when it is not known.
     * @return array The activity fields of the module info.
     */
    private static function activity_settings(int $sourcecmid): array {
        global $DB;

        $defaults = [
            'visible' => 1,
            'visibleoncoursepage' => 1,
            'completion' => COMPLETION_TRACKING_NONE,
            'completionview' => 0,
            'completionexpected' => 0,
            'completionunlocked' => 1,
            'showdescription' => '0',
            'cmidnumber' => '',
            'availabilityconditionsjson' => self::EMPTY_AVAILABILITY,
        ];
        if ($sourcecmid <= 0) {
            return $defaults;
        }

        $fields = 'id, visible, visibleoncoursepage, completion, completionview, showdescription';
        $record = $DB->get_record('course_modules', ['id' => $sourcecmid], $fields);
        if (!$record) {
            return $defaults;
        }

        $defaults['visible'] = (int) $record->visible;
        $defaults['visibleoncoursepage'] = (int) $record->visibleoncoursepage;
        $defaults['completion'] = (int) $record->completion;
        $defaults['completionview'] = (int) $record->completionview;
        $defaults['showdescription'] = (string) (int) $record->showdescription;
        return $defaults;
    }

    /**
     * The parameters of the activity without the pieces of the backup structure.
     *
     * @param array $parameters The parameters of the generated activity.
     * @return array The same parameters minus the backup structure.
     */
    private static function without_backup_keys(array $parameters): array {
        foreach (self::BACKUP_KEYS as $key) {
            unset($parameters[$key]);
        }
        return $parameters;
    }
}

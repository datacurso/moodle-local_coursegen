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

use cm_info;

/**
 * Tells whether an activity has at least one placeholder in its content.
 *
 * An activity can only be a template, the mold other activities are generated
 * from, if it says what the AI service has to write. The content that is
 * looked at is exactly the content the template export sends to the AI
 * service as the mold: the structure the module declares for itself, and the
 * questions of a quiz. Both come from template_activity_export, so what is
 * checked here and what the AI service receives cannot drift apart.
 *
 * Every text of that content is scanned by template_placeholder_scanner. The
 * double bracket dialect is left out of the texts whose module already uses
 * double brackets for its own syntax: the pages of a wiki, the templates of a
 * database and the text of a gap select question. Only the angle dialect
 * counts there.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_placeholder_detector {
    /** @var array Module name => the fields of its structure that use double brackets for the module's own syntax. */
    private const BRACKET_FIELDS_OF_MODULE = [
        'wiki' => ['cachedcontent', 'content'],
        'data' => [
            'singletemplate',
            'listtemplate',
            'listtemplateheader',
            'listtemplatefooter',
            'addtemplate',
            'rsstemplate',
            'rsstitletemplate',
            'csstemplate',
            'jstemplate',
            'asearchtemplate',
        ],
    ];

    /** @var array Question type => the fields of its question that use double brackets for the type's own syntax. */
    private const BRACKET_FIELDS_OF_QUESTION = [
        'gapselect' => ['questiontext'],
    ];

    /**
     * Whether an activity has at least one placeholder in its content.
     *
     * @param cm_info $cm The activity.
     * @return bool
     */
    public static function has_placeholder(cm_info $cm): bool {
        $parameters = template_activity_export::parameters_for($cm);
        $modname = (string) $cm->modname;
        return self::parameters_have_placeholder($modname, $parameters);
    }

    /**
     * Whether the parameters the export builds for an activity hold a placeholder.
     *
     * @param string $modname The module name of the activity.
     * @param array $parameters As template_activity_export::parameters_for() gives them.
     * @return bool
     */
    public static function parameters_have_placeholder(string $modname, array $parameters): bool {
        $structure = $parameters['structure'] ?? [];
        $bracketfields = self::BRACKET_FIELDS_OF_MODULE[$modname] ?? [];
        if (self::node_has_placeholder($structure, $bracketfields)) {
            return true;
        }
        $questions = $parameters['questions'] ?? [];
        return self::questions_have_placeholder($questions);
    }

    /**
     * Whether any text under a node of the structure holds a placeholder.
     *
     * @param array $node A node of the structure, or a list of nodes.
     * @param string[] $bracketfields The field names whose double brackets are the module's own.
     * @return bool
     */
    private static function node_has_placeholder(array $node, array $bracketfields): bool {
        foreach ($node as $key => $value) {
            if (self::entry_has_placeholder($key, $value, $bracketfields)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether one entry of a node holds a placeholder.
     *
     * @param int|string $key The field name, or the position in a list.
     * @param mixed $value The field value, or the nested node.
     * @param string[] $bracketfields The field names whose double brackets are the module's own.
     * @return bool
     */
    private static function entry_has_placeholder($key, $value, array $bracketfields): bool {
        if (is_array($value)) {
            return self::node_has_placeholder($value, $bracketfields);
        }
        if (!is_string($value)) {
            return false;
        }
        $isownsyntax = in_array($key, $bracketfields, true);
        $allowbrackets = !$isownsyntax;
        return template_placeholder_scanner::contains_placeholder($value, $allowbrackets);
    }

    /**
     * Whether any question of a quiz holds a placeholder.
     *
     * @param array $questions The quiz slots as the export lists them.
     * @return bool
     */
    private static function questions_have_placeholder(array $questions): bool {
        foreach ($questions as $slot) {
            if (self::slot_has_placeholder($slot)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the question of one quiz slot holds a placeholder.
     *
     * A slot filled at random from a category carries no question.
     *
     * @param array $slot One entry of the quiz questions.
     * @return bool
     */
    private static function slot_has_placeholder(array $slot): bool {
        $question = $slot['question'] ?? [];
        if (!$question) {
            return false;
        }
        $qtype = $question['qtype'] ?? '';
        $bracketfields = self::BRACKET_FIELDS_OF_QUESTION[$qtype] ?? [];
        return self::node_has_placeholder($question, $bracketfields);
    }
}

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
use local_coursegen\local\backup\activity_reader;

/**
 * One real activity's "parameters" for the course-template payload.
 *
 * A mould is the case that matters: the AI reproduces its structure piece by
 * piece, so the mould has to travel whole, with the markers its author wrote
 * still in the text that carries them.
 *
 * It used to travel as a hand-written description, and only for lessons. Every
 * other type sent its name and its place and nothing else, so a template built
 * on a book or a quiz had nothing to reproduce. The description was also ours
 * to keep correct: a list of thirty lesson columns, against the forty-two the
 * activity really has.
 *
 * Each module already describes itself completely, and that description is now
 * what travels. See activity_reader.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_activity_export {
    /**
     * Build one activity's parameters.
     *
     * Name and section stay at the top because they are not the activity's
     * content: they are where it sits in the course, which is what the answer
     * needs to place what it writes.
     *
     * @param cm_info $cm
     * @return array
     */
    public static function parameters_for(cm_info $cm): array {
        $read = activity_reader::read_with_sources($cm);
        return [
            'name' => $cm->name,
            'section' => (int) $cm->sectionnum,
            // Everything the activity is made of, as its own module declares
            // it: its settings, and every list that belongs to it, each
            // element carrying the id the module gave it.
            'structure' => $read['tree'],
            // Where each element came from, so the tree can be read back as
            // the rows the module's own code asks for.
            'structure_tables' => $read['tables'],
            'structure_aliases' => $read['aliases'],
            // The files the activity keeps, which its tree only points at: a
            // folder is its files, a file resource is one of them.
            'files' => self::files_of($cm),
        ] + self::questions_of($cm);
    }

    /**
     * The questions a quiz asks, which its own tree only points at.
     *
     * A quiz's structure names each question by a reference into the question
     * bank: the bank entry and the version. The question itself is not in the
     * quiz's tree, because a backup carries the bank separately. A payload has
     * no separate bank, so the questions travel with the quiz, each in the
     * shape the question engine builds a question from, one per slot.
     *
     * @param cm_info $cm
     * @return array Empty for anything but a quiz.
     */
    private static function questions_of(cm_info $cm): array {
        global $CFG, $DB;
        if ($cm->modname !== 'quiz') {
            return [];
        }
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $questions = [];
        $slots = $DB->get_records('quiz_slots', ['quizid' => $cm->instance], 'slot');
        foreach ($slots as $slot) {
            $reference = $DB->get_record('question_references', [
                'component' => 'mod_quiz',
                'questionarea' => 'slot',
                'itemid' => $slot->id,
            ]);
            $entry = [
                'slot' => (int) $slot->slot,
                'page' => (int) $slot->page,
                'maxmark' => (float) $slot->maxmark,
                'displaynumber' => $slot->displaynumber,
                'requireprevious' => (int) ($slot->requireprevious ?? 0),
            ];
            if ($reference) {
                $version = $reference->version
                    ? $DB->get_record('question_versions',
                        ['questionbankentryid' => $reference->questionbankentryid, 'version' => $reference->version])
                    : $DB->get_record_sql(
                        'SELECT * FROM {question_versions} WHERE questionbankentryid = :entry ORDER BY version DESC',
                        ['entry' => $reference->questionbankentryid], IGNORE_MULTIPLE);
                if ($version) {
                    // Everything the engine needs to make the question: the
                    // row, its options, its answers, its hints.
                    $entry['question'] = json_decode(
                        json_encode(\question_bank::load_question_data((int) $version->questionid)),
                        true
                    );
                }
            }
            // A slot filled at random from a category is a reference to a set
            // of questions, not to one, and no one of them can stand for it.
            $questions[] = $entry;
        }
        return ['questions' => $questions];
    }

    /**
     * Every file the module holds for this activity, as the file storage lists them.
     *
     * The same columns a stored_file answers for, so code written against one
     * can be run against these. The address is where the file is served from
     * now, which is the one thing this payload cannot be told from its rows.
     *
     * @param cm_info $cm
     * @return array
     */
    private static function files_of(cm_info $cm): array {
        global $DB;
        $context = \context_module::instance($cm->id);
        $files = [];
        // The file storage lists one area, or a named set of them, never all.
        $areas = $DB->get_fieldset_sql(
            'SELECT DISTINCT filearea FROM {files} WHERE contextid = :contextid AND component = :component',
            ['contextid' => $context->id, 'component' => 'mod_' . $cm->modname]
        );
        if (!$areas) {
            return [];
        }
        $stored = get_file_storage()->get_area_files(
            $context->id, 'mod_' . $cm->modname, $areas, false, 'filearea, itemid, filepath, filename', true
        );
        foreach ($stored as $file) {
            $isdir = $file->is_directory();
            $files[] = [
                'id' => (int) $file->get_id(),
                'contextid' => (int) $file->get_contextid(),
                'component' => $file->get_component(),
                'filearea' => $file->get_filearea(),
                'itemid' => (int) $file->get_itemid(),
                'filepath' => $file->get_filepath(),
                'filename' => $file->get_filename(),
                'isdir' => $isdir,
                'filesize' => (int) $file->get_filesize(),
                'mimetype' => $isdir ? null : $file->get_mimetype(),
                'timecreated' => (int) $file->get_timecreated(),
                'timemodified' => (int) $file->get_timemodified(),
                'sortorder' => (int) $file->get_sortorder(),
                'author' => $file->get_author(),
                'license' => $file->get_license(),
                'url' => $isdir ? null : \moodle_url::make_pluginfile_url(
                    $file->get_contextid(), $file->get_component(), $file->get_filearea(),
                    $file->get_itemid(), $file->get_filepath(), $file->get_filename()
                )->out(false),
            ];
        }
        return $files;
    }
}

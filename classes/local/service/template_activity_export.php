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
        $files = self::files_of($cm);
        $questions = self::questions_of($cm);
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
            'files' => $files,
        ] + $questions;
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

        $slots = $DB->get_records('quiz_slots', ['quizid' => $cm->instance], 'slot');
        $questions = [];
        foreach ($slots as $slot) {
            $questions[] = self::quiz_slot_entry($slot);
        }
        return ['questions' => $questions];
    }

    /**
     * One quiz slot's own entry: the slot itself, and the question it
     * references, if the question bank still has a resolvable version of it.
     *
     * A slot filled at random from a category is a reference to a set of
     * questions, not to one, and no one of them can stand for it, so it
     * carries no 'question' entry.
     *
     * @param \stdClass $slot A row of quiz_slots.
     * @return array
     */
    private static function quiz_slot_entry($slot): array {
        global $DB;

        $requireprevious = $slot->requireprevious ?? 0;
        $requireprevious = (int) $requireprevious;

        $entry = [
            'slot' => (int) $slot->slot,
            'page' => (int) $slot->page,
            'maxmark' => (float) $slot->maxmark,
            'displaynumber' => $slot->displaynumber,
            'requireprevious' => $requireprevious,
        ];

        $reference = $DB->get_record('question_references', [
            'component' => 'mod_quiz',
            'questionarea' => 'slot',
            'itemid' => $slot->id,
        ]);
        if (!$reference) {
            return $entry;
        }

        $version = self::quiz_slot_question_version($reference);
        if (!$version) {
            return $entry;
        }

        // Everything the engine needs to make the question: the
        // row, its options, its answers, its hints.
        $questionid = (int) $version->questionid;
        $questiondata = \question_bank::load_question_data($questionid);
        $questionjson = json_encode($questiondata);
        $entry['question'] = json_decode($questionjson, true);
        return $entry;
    }

    /**
     * The question_versions row a slot's reference points at: the exact
     * version it names, or the latest one when it names none in particular.
     *
     * @param \stdClass $reference A row of question_references.
     * @return \stdClass|false
     */
    private static function quiz_slot_question_version($reference) {
        global $DB;

        if ($reference->version) {
            return $DB->get_record('question_versions', [
                'questionbankentryid' => $reference->questionbankentryid,
                'version' => $reference->version,
            ]);
        }
        return $DB->get_record_sql(
            'SELECT * FROM {question_versions} WHERE questionbankentryid = :entry ORDER BY version DESC',
            ['entry' => $reference->questionbankentryid], IGNORE_MULTIPLE);
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
        $component = 'mod_' . $cm->modname;
        // The file storage lists one area, or a named set of them, never all.
        $areas = $DB->get_fieldset_sql(
            'SELECT DISTINCT filearea FROM {files} WHERE contextid = :contextid AND component = :component',
            ['contextid' => $context->id, 'component' => $component]
        );
        if (!$areas) {
            return [];
        }
        $filestorage = get_file_storage();
        $stored = $filestorage->get_area_files(
            $context->id, $component, $areas, false, 'filearea, itemid, filepath, filename', true
        );
        $files = [];
        foreach ($stored as $file) {
            $files[] = self::stored_file_entry($file);
        }
        return $files;
    }

    /**
     * One stored file's own entry, in the same columns a stored_file answers for.
     *
     * @param \stored_file $file
     * @return array
     */
    private static function stored_file_entry($file): array {
        $isdir = $file->is_directory();

        $id = (int) $file->get_id();
        $contextid = (int) $file->get_contextid();
        $component = $file->get_component();
        $filearea = $file->get_filearea();
        $itemid = (int) $file->get_itemid();
        $filepath = $file->get_filepath();
        $filename = $file->get_filename();
        $filesize = (int) $file->get_filesize();
        $timecreated = (int) $file->get_timecreated();
        $timemodified = (int) $file->get_timemodified();
        $sortorder = (int) $file->get_sortorder();
        $author = $file->get_author();
        $license = $file->get_license();

        // A directory marker has no content of its own, so it has neither a
        // mime type nor an address to be served from.
        $mimetype = null;
        $url = null;
        if (!$isdir) {
            $mimetype = $file->get_mimetype();
            $fileurl = \moodle_url::make_pluginfile_url(
                $contextid, $component, $filearea, $itemid, $filepath, $filename);
            $url = $fileurl->out(false);
        }

        return [
            'id' => $id,
            'contextid' => $contextid,
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => $itemid,
            'filepath' => $filepath,
            'filename' => $filename,
            'isdir' => $isdir,
            'filesize' => $filesize,
            'mimetype' => $mimetype,
            'timecreated' => $timecreated,
            'timemodified' => $timemodified,
            'sortorder' => $sortorder,
            'author' => $author,
            'license' => $license,
            'url' => $url,
        ];
    }
}

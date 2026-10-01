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
 * The values a module's own edit form holds for one activity.
 *
 * The backup tree of a template source speaks the vocabulary of the database columns, while the
 * code that creates an activity (add_moduleinfo) speaks the one of the edit
 * form. Instead of translating between them with a table that would have to be
 * kept by hand, the values travel also as the activity's own form holds them,
 * so every name comes from Moodle itself.
 *
 * The form is instantiated exactly as the page that edits an activity does it.
 * Moodle offers no public way to read what a form holds before it is
 * submitted, so the form's QuickForm is taken through reflection, here and
 * nowhere else.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_source_form_values {
    /**
     * Read the values the edit form of an activity holds.
     *
     * The hidden elements Moodle adds to every activity form carry the
     * identity of the activity and the plumbing of the submission, not its
     * settings, so they are left out, together with every column that is not
     * a form element. The hidden elements a module declares itself are
     * settings and stay.
     *
     * @param cm_info $cm
     * @return array Form element name => value; editors as text, format and itemid.
     * @throws \moodle_exception If the form of the module cannot be built or read.
     */
    public static function read(cm_info $cm): array {
        try {
            return self::read_in_isolated_page($cm);
        } catch (\Throwable $error) {
            $reason = $error->getMessage();
            $a = (object) ['activity' => $cm->name, 'module' => $cm->modname];
            throw new \moodle_exception('template_source_form_unreadable', 'local_coursegen', '', $a, $reason);
        }
    }

    /**
     * Read the form on a page of its own.
     *
     * The edit form changes the page it is built on: its course, its activity
     * and, once something touches it, its theme, which can no longer change
     * afterwards. The page of the request that exports is left as it was, so
     * that every template source of an export reads its form on a clean one.
     *
     * @param cm_info $cm
     * @return array
     */
    private static function read_in_isolated_page(cm_info $cm): array {
        global $PAGE, $OUTPUT, $COURSE;
        $savedpage = $PAGE;
        $savedoutput = $OUTPUT;
        $savedcourse = $COURSE;
        $PAGE = new \moodle_page();
        try {
            return self::read_from_form($cm);
        } finally {
            $PAGE = $savedpage;
            $OUTPUT = $savedoutput;
            $COURSE = $savedcourse;
        }
    }

    /**
     * Build the form, read it and release the draft areas it prepared.
     *
     * @param cm_info $cm
     * @return array
     */
    private static function read_from_form(cm_info $cm): array {
        $mform = self::prepared_form($cm);
        $quickform = self::quickform_of($mform);
        $values = self::form_values($quickform);
        $draftitemids = self::draft_itemids($quickform, $values);
        try {
            return self::without_identity($mform, $quickform, $values);
        } finally {
            self::release_drafts($draftitemids);
        }
    }

    /**
     * Every value the form holds for its elements.
     *
     * The form exports the values of the elements that have one, but leaves
     * out an unchecked checkbox and an empty value, which are values too. They
     * are completed from the defaults the form holds after being filled, for
     * every element the form has.
     *
     * @param \MoodleQuickForm $quickform
     * @return array
     */
    private static function form_values(\MoodleQuickForm $quickform): array {
        $exported = $quickform->exportValues();
        $names = self::element_names($quickform->_elements);
        $namekeys = array_flip($names);
        $held = array_intersect_key($quickform->_defaultValues, $namekeys);
        return $exported + $held;
    }

    /**
     * The names of the elements, those inside groups included.
     *
     * @param array $elements
     * @return string[]
     */
    private static function element_names(array $elements): array {
        $names = [];
        foreach ($elements as $element) {
            $names[] = $element->getName();
            $children = self::group_children($element);
            $names = array_merge($names, $children);
        }
        return $names;
    }

    /**
     * The names of the elements a group holds.
     *
     * @param \HTML_QuickForm_element $element
     * @return string[]
     */
    private static function group_children($element): array {
        if ($element->getType() !== 'group') {
            return [];
        }
        $inside = $element->getElements();
        return self::element_names($inside);
    }

    /**
     * The edit form of an activity, instantiated and filled as modedit.php does.
     *
     * @param cm_info $cm
     * @return \moodleform_mod
     */
    private static function prepared_form(cm_info $cm): \moodleform_mod {
        global $CFG;
        require_once($CFG->dirroot . '/course/modlib.php');

        $course = $cm->get_course();
        self::point_page_at($cm, $course);
        $cmrecord = $cm->get_course_module_record();
        [$cmrecord] = can_update_moduleinfo($cmrecord);
        [$cmrecord, $context, $module, $data, $section] = get_moduleinfo_data($cmrecord, $course);

        $formclass = 'mod_' . $module->name . '_mod_form';
        require_once($CFG->dirroot . '/mod/' . $module->name . '/mod_form.php');
        $mform = new $formclass($data, $section->section, $cmrecord, $course);
        $mform->set_data($data);
        return $mform;
    }

    /**
     * Give the page the url, course and activity the edit page has.
     *
     * @param cm_info $cm
     * @param \stdClass $course
     */
    private static function point_page_at(cm_info $cm, \stdClass $course): void {
        global $PAGE;
        $url = new \moodle_url('/course/modedit.php', ['update' => $cm->id]);
        $PAGE->set_url($url);
        $PAGE->set_course($course);
        $PAGE->set_cm($cm);
    }

    /**
     * The QuickForm a moodleform builds its elements in.
     *
     * The property is protected and moodleform has no getter for it, nor any
     * public method that gives the elements or the values of a form that has
     * not been submitted, so this is the only access to it.
     *
     * @param \moodleform $mform
     * @return \MoodleQuickForm
     */
    private static function quickform_of(\moodleform $mform): \MoodleQuickForm {
        $property = new \ReflectionProperty(\moodleform::class, '_form');
        return $property->getValue($mform);
    }

    /**
     * The values without the identity of the activity and the plumbing of
     * the form.
     *
     * A module can declare hidden elements of its own, and those are
     * settings: they stay.
     *
     * @param \moodleform_mod $mform
     * @param \MoodleQuickForm $quickform
     * @param array $values
     * @return array
     */
    private static function without_identity(\moodleform_mod $mform, \MoodleQuickForm $quickform, array $values): array {
        $identity = self::standard_hidden_names($mform, $quickform);
        $identitykeys = array_flip($identity);
        $withoutidentity = array_diff_key($values, $identitykeys);
        return self::without_library_plumbing($withoutidentity);
    }

    /**
     * The names of the hidden elements every activity form gets from Moodle.
     *
     * Moodle adds them in one method, so they are recorded by running that
     * method and looking at what it added to the form.
     *
     * @param \moodleform_mod $mform
     * @param \MoodleQuickForm $quickform
     * @return string[]
     */
    private static function standard_hidden_names(\moodleform_mod $mform, \MoodleQuickForm $quickform): array {
        $before = count($quickform->_elements);
        $mform->standard_hidden_coursemodule_elements();
        $added = array_slice($quickform->_elements, $before);
        return self::hidden_names($added);
    }

    /**
     * The values without what the form library adds to every form.
     *
     * These are the library's own naming conventions, not knowledge about any
     * activity module: the session key, the marker of the submission
     * (`_qf__` and the name of the form) and the state of the page, which
     * headers are expanded (`mform_isexpanded_`) and which advanced settings
     * are shown (`mform_showmore_`).
     *
     * @param array $values
     * @return array
     */
    private static function without_library_plumbing(array $values): array {
        $kept = [];
        foreach ($values as $name => $value) {
            $name = (string) $name;
            $issesskey = $name === 'sesskey';
            $ismarker = str_starts_with($name, '_qf__');
            $isexpanded = str_starts_with($name, 'mform_isexpanded_');
            $isshowmore = str_starts_with($name, 'mform_showmore_');
            if ($issesskey || $ismarker || $isexpanded || $isshowmore) {
                continue;
            }
            $kept[$name] = $value;
        }
        return $kept;
    }

    /**
     * The names of the hidden elements among some elements.
     *
     * @param array $elements
     * @return string[]
     */
    private static function hidden_names(array $elements): array {
        $names = [];
        foreach ($elements as $element) {
            $type = $element->getType();
            if ($type === 'hidden') {
                $names[] = $element->getName();
            }
        }
        return $names;
    }

    /**
     * The draft items the form prepared for its editors and file managers.
     *
     * @param \MoodleQuickForm $quickform
     * @param array $values
     * @return int[]
     */
    private static function draft_itemids(\MoodleQuickForm $quickform, array $values): array {
        $itemids = [];
        foreach ($quickform->_elements as $element) {
            $name = $element->getName();
            if (!array_key_exists($name, $values)) {
                continue;
            }
            $type = $element->getType();
            $itemid = self::draft_itemid_of($type, $values[$name]);
            if ($itemid > 0) {
                $itemids[] = $itemid;
            }
        }
        return $itemids;
    }

    /**
     * The draft item an element's value points at.
     *
     * @param string $type Element type.
     * @param mixed $value
     * @return int 0 when the element does not hold a draft item.
     */
    private static function draft_itemid_of(string $type, $value): int {
        if ($type === 'editor') {
            return (int) $value['itemid'];
        }
        if ($type === 'filemanager') {
            return (int) $value;
        }
        return 0;
    }

    /**
     * Delete the draft items of the current user.
     *
     * @param int[] $itemids
     */
    private static function release_drafts(array $itemids): void {
        global $USER;
        $usercontext = \context_user::instance($USER->id);
        $filestorage = get_file_storage();
        foreach ($itemids as $itemid) {
            $filestorage->delete_area_files($usercontext->id, 'user', 'draft', $itemid);
        }
    }
}

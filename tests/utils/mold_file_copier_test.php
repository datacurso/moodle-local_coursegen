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

namespace local_coursegen\utils;

use local_coursegen\local\service\create_mod_service;
use local_coursegen\mod_settings\glossary_settings;
use local_coursegen\mod_settings\lesson_settings;

/**
 * Files a mold references by pluginfile URL are copied into the generated activity.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\utils\mold_file_copier
 * @covers     \local_coursegen\utils\text_editor_parameter_cleaner
 * @covers     \local_coursegen\mod_settings\lesson_settings
 * @covers     \local_coursegen\mod_settings\glossary_settings
 */
final class mold_file_copier_test extends \advanced_testcase {
    /**
     * A base course with a label whose intro area holds one image file.
     *
     * @return array{0: \stdClass, 1: string} The course and the image's absolute pluginfile URL.
     */
    private function base_course_with_label_image(): array {
        global $CFG;

        $course = $this->getDataGenerator()->create_course();
        $label = $this->getDataGenerator()->create_module('label', ['course' => $course->id]);
        $context = \context_module::instance($label->cmid);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_label', 'filearea' => 'intro', 'itemid' => 0,
            'filepath' => '/', 'filename' => 'mold pic.png',
        ], 'PNGDATA');

        $url = $CFG->wwwroot . '/pluginfile.php/' . $context->id . '/mod_label/intro/mold%20pic.png';
        return [$course, $url];
    }

    /**
     * The draft files of the current user for one item id, keyed by filename.
     *
     * @param int $draftitemid
     * @return \stored_file[]
     */
    private function draft_files(int $draftitemid): array {
        global $USER;
        $files = get_file_storage()->get_area_files(
            \context_user::instance($USER->id)->id, 'user', 'draft', $draftitemid, 'id', false
        );
        $byname = [];
        foreach ($files as $file) {
            $byname[$file->get_filename()] = $file;
        }
        return $byname;
    }

    /**
     * A referenced file of the allowed course lands in the draft and the src becomes @@PLUGINFILE@@.
     */
    public function test_copies_referenced_file_into_draft_and_rewrites_src(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $url] = $this->base_course_with_label_image();
        $draftitemid = file_get_unused_draft_itemid();

        $text = '<p>Hi <img src="' . $url . '" alt="x"> <a href="' . $url . '">file</a></p>';
        $result = mold_file_copier::copy_pluginfile_urls_to_draft($text, $draftitemid, (int) $course->id);

        $this->assertSame(
            '<p>Hi <img src="@@PLUGINFILE@@/mold%20pic.png" alt="x"> <a href="@@PLUGINFILE@@/mold%20pic.png">file</a></p>',
            $result
        );
        $files = $this->draft_files($draftitemid);
        $this->assertCount(1, $files);
        $this->assertSame('PNGDATA', $files['mold pic.png']->get_content());
    }

    /**
     * A file of a course the user cannot access is left alone: no copy, unchanged src.
     */
    public function test_refuses_file_of_course_the_user_cannot_access(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $url] = $this->base_course_with_label_image();
        $this->setUser($this->getDataGenerator()->create_user());
        $draftitemid = file_get_unused_draft_itemid();

        $text = '<img src="' . $url . '">';
        $this->assertSame($text, mold_file_copier::copy_pluginfile_urls_to_draft($text, $draftitemid));
        $this->assertSame([], $this->draft_files($draftitemid));

        // Naming another course as the only allowed source refuses it too, even for admins.
        $this->setAdminUser();
        $other = $this->getDataGenerator()->create_course();
        $this->assertSame($text, mold_file_copier::copy_pluginfile_urls_to_draft($text, $draftitemid, (int) $other->id));
        $this->assertSame([], $this->draft_files($draftitemid));
        $this->assertNotEquals($other->id, $course->id);
    }

    /**
     * Without an explicit source course, a teacher who manages the course's activities may copy from it.
     */
    public function test_teacher_with_manageactivities_may_copy_without_explicit_source_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $url] = $this->base_course_with_label_image();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);
        $draftitemid = file_get_unused_draft_itemid();

        $result = mold_file_copier::copy_pluginfile_urls_to_draft('<img src="' . $url . '">', $draftitemid);

        $this->assertSame('<img src="@@PLUGINFILE@@/mold%20pic.png">', $result);
        $this->assertCount(1, $this->draft_files($draftitemid));
    }

    /**
     * An enrolled student can access the course but may not lift its files.
     */
    public function test_enrolled_student_is_refused_in_fallback(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $url] = $this->base_course_with_label_image();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);
        $this->assertTrue(can_access_course($course));
        $draftitemid = file_get_unused_draft_itemid();

        $text = '<img src="' . $url . '">';
        $this->assertSame($text, mold_file_copier::copy_pluginfile_urls_to_draft($text, $draftitemid));
        $this->assertSame([], $this->draft_files($draftitemid));
    }

    /**
     * Files outside the mold areas are never copied, even from the allowed course by an admin.
     */
    public function test_refuses_files_outside_allowed_areas(): void {
        global $CFG, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $fs = get_file_storage();

        // A submission file of an assignment in the allowed course.
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $assigncontext = \context_module::instance($assign->cmid);
        $fs->create_file_from_string([
            'contextid' => $assigncontext->id, 'component' => 'assignsubmission_file', 'filearea' => 'submission_files',
            'itemid' => 7, 'filepath' => '/', 'filename' => 'homework.pdf',
        ], 'PDF');
        $submissionurl = $CFG->wwwroot . '/pluginfile.php/' . $assigncontext->id
            . '/assignsubmission_file/submission_files/7/homework.pdf';

        // A private file in a user context.
        $usercontext = \context_user::instance($USER->id);
        $fs->create_file_from_string([
            'contextid' => $usercontext->id, 'component' => 'user', 'filearea' => 'private',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'secret.png',
        ], 'SECRET');
        $privateurl = $CFG->wwwroot . '/pluginfile.php/' . $usercontext->id . '/user/private/secret.png';

        // Both resolve to real files, so the refusal is authorization, not a lookup miss.
        $this->assertNotNull(mold_file_copier::resolve_url($submissionurl));
        $this->assertNotNull(mold_file_copier::resolve_url($privateurl));

        $draftitemid = file_get_unused_draft_itemid();
        $text = '<img src="' . $submissionurl . '"><img src="' . $privateurl . '">';
        $this->assertSame($text, mold_file_copier::copy_pluginfile_urls_to_draft($text, $draftitemid, (int) $course->id));
        $this->assertSame($text, mold_file_copier::copy_pluginfile_urls_to_draft($text, $draftitemid));
        $this->assertSame([], $this->draft_files($draftitemid));

        $this->assertTrue(mold_file_copier::is_allowed_area('mod_label', 'intro'));
        $this->assertTrue(mold_file_copier::is_allowed_area('mod_lesson', 'page_contents'));
        $this->assertFalse(mold_file_copier::is_allowed_area('mod_assign', 'submission'));
        $this->assertFalse(mold_file_copier::is_allowed_area('user', 'draft'));
    }

    /**
     * A path that climbs directories is never treated as a local generated image.
     */
    public function test_traversal_path_is_not_a_generated_image_source(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cleaned = text_editor_parameter_cleaner::clean_text_editor_objects([
            'introeditor' => [
                'text' => '<img src="/tmp/../../etc/passwd"><img src="/x/generated_images/../c.png">'
                    . '<img src="/tmp/generated_images/ok;rm.png">',
                'format' => 1,
            ],
        ]);

        // Untouched: no download was attempted (no client init debugging) and no rewrite happened.
        $this->assertSame(
            '<img src="/tmp/../../etc/passwd"><img src="/x/generated_images/../c.png">'
                . '<img src="/tmp/generated_images/ok;rm.png">',
            $cleaned['introeditor']['text']
        );
    }

    /**
     * Leftover image markers are stripped when editor text is cleaned.
     */
    public function test_cleaner_strips_leftover_image_markers(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cleaned = text_editor_parameter_cleaner::clean_text_editor_objects([
            'introeditor' => ['text' => '<p>A ⟦coursegen:image: banner de la unidad⟧ B</p>', 'format' => 1],
        ]);

        $this->assertSame('<p>A  B</p>', $cleaned['introeditor']['text']);
    }

    /**
     * A label built through the service ends up with the mold's image in its own mod_label/intro area.
     */
    public function test_label_created_through_service_owns_the_copied_file(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$basecourse, $url] = $this->base_course_with_label_image();
        $target = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $PAGE->set_course($target);

        $newcm = create_mod_service::create_from_ai_result([
            'resource_type' => 'label',
            'parameters' => [
                'modulename' => 'label',
                'name' => 'Generated label',
                'introeditor' => ['text' => '<p><img src="' . $url . '"></p>', 'format' => FORMAT_HTML],
                'visible' => 1,
                'mod_settings' => [],
            ],
        ], $target, 1, null, (int) $basecourse->id);

        $context = \context_module::instance($newcm->coursemodule);
        $files = get_file_storage()->get_area_files($context->id, 'mod_label', 'intro', 0, 'id', false);
        $this->assertCount(1, $files);
        $this->assertSame('mold pic.png', reset($files)->get_filename());
        $intro = $DB->get_field('label', 'intro', ['id' => $newcm->instance]);
        $this->assertStringContainsString('@@PLUGINFILE@@/mold%20pic.png', $intro);
    }

    /**
     * A lesson page's header image travels into mod_lesson/page_contents of the new page.
     */
    public function test_lesson_page_content_keeps_referenced_image(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$basecourse, $url] = $this->base_course_with_label_image();
        $target = $this->getDataGenerator()->create_course();
        $lesson = $this->getDataGenerator()->create_module('lesson', ['course' => $target->id]);
        $cm = (object) ['coursemodule' => $lesson->cmid, 'instance' => $lesson->id];

        (new lesson_settings($cm, ['pages' => [[
            'title' => 'Header', 'page_type' => 'content',
            'content_html' => '<p><img src="' . $url . '"> ⟦coursegen:image:x⟧</p>',
            'buttons' => [['text' => 'Next', 'jumpto' => -1]],
        ]]], (int) $basecourse->id))->add_settings();

        $page = $DB->get_record('lesson_pages', ['lessonid' => $lesson->id], '*', MUST_EXIST);
        $this->assertSame('<p><img src="@@PLUGINFILE@@/mold%20pic.png"> </p>', $page->contents);
        $files = get_file_storage()->get_area_files(
            \context_module::instance($lesson->cmid)->id, 'mod_lesson', 'page_contents', $page->id, 'id', false
        );
        $this->assertCount(1, $files);
    }

    /**
     * A glossary entry keeps the image of its definition through inlineattachmentsid.
     */
    public function test_glossary_entry_definition_keeps_referenced_image(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$basecourse, $url] = $this->base_course_with_label_image();
        $target = $this->getDataGenerator()->create_course();
        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $target->id]);
        $cm = (object) ['coursemodule' => $glossary->cmid, 'instance' => $glossary->id];

        $settings = text_editor_parameter_cleaner::clean_text_editor_objects(['entries' => [[
            'concept' => 'Term',
            'definition_editor' => ['text' => '<p><img src="' . $url . '"></p>', 'format' => FORMAT_HTML],
        ]]], (int) $basecourse->id);
        (new glossary_settings($cm, $settings, (int) $basecourse->id))->add_settings();

        $entry = $DB->get_record('glossary_entries', ['glossaryid' => $glossary->id], '*', MUST_EXIST);
        $this->assertStringContainsString('@@PLUGINFILE@@/mold%20pic.png', $entry->definition);
        $files = get_file_storage()->get_area_files(
            \context_module::instance($glossary->cmid)->id, 'mod_glossary', 'entry', $entry->id, 'id', false
        );
        $this->assertCount(1, $files);
    }
}

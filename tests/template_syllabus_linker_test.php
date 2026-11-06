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

namespace local_coursegen;

use local_coursegen\local\service\course_session_service;
use local_coursegen\local\service\template_syllabus_linker;

/**
 * Leaving the uploaded syllabus in the generated texts that link to it.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_syllabus_linker
 * @covers     \local_coursegen\local\service\course_session_service
 */
final class template_syllabus_linker_test extends \advanced_testcase {
    /** @var string A syllabus link as a template writes it. */
    private const LINK = '<a data-coursegen-syllabus="1" href="#">Descargar la guía didáctica</a>';

    /**
     * A file standing for the uploaded syllabus, in a user draft area.
     *
     * @param string $filename
     * @return \stored_file
     */
    private function syllabus(string $filename = 'Guía didáctica.pdf'): \stored_file {
        $user = $this->getDataGenerator()->create_user();
        return get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => file_get_unused_draft_itemid(),
            'filepath' => '/',
            'filename' => $filename,
        ], '%PDF-1.4 syllabus');
    }

    /**
     * The files of a file area of an activity, by name.
     *
     * @param int $cmid
     * @param string $component
     * @param string $filearea
     * @param int $itemid
     * @return string[]
     */
    private function files(int $cmid, string $component, string $filearea, int $itemid): array {
        $context = \context_module::instance($cmid);
        $files = get_file_storage()->get_area_files($context->id, $component, $filearea, $itemid, 'filename', false);
        return array_values(array_map(static fn(\stored_file $file): string => $file->get_filename(), $files));
    }

    /**
     * A page links the copy of the syllabus kept in its own content area.
     */
    public function test_a_page_links_its_own_copy_of_the_syllabus(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'content' => '<p>Plan</p>' . self::LINK,
        ]);

        template_syllabus_linker::apply($course->id, [(int) $page->cmid], $this->syllabus());

        $content = $DB->get_field('page', 'content', ['id' => $page->id], MUST_EXIST);
        $this->assertStringContainsString('href="@@PLUGINFILE@@/Gu%C3%ADa%20did%C3%A1ctica.pdf"', $content);
        $this->assertSame(['Guía didáctica.pdf'], $this->files((int) $page->cmid, 'mod_page', 'content', 0));
        $url = file_rewrite_pluginfile_urls(
            $content,
            'pluginfile.php',
            \context_module::instance($page->cmid)->id,
            'mod_page',
            'content',
            0
        );
        $this->assertStringContainsString('/mod_page/content/0/Gu%C3%ADa%20did%C3%A1ctica.pdf', $url);
    }

    /**
     * Each lesson page that links the syllabus gets a copy in its own area; the others are left alone.
     */
    public function test_each_lesson_page_keeps_its_copy(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $lesson = $this->getDataGenerator()->create_module('lesson', ['course' => $course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_lesson');
        $first = $generator->create_content($lesson);
        $second = $generator->create_content($lesson);
        $DB->set_field('lesson_pages', 'contents', '<p>' . self::LINK . '</p>', ['id' => $first->id]);
        $DB->set_field('lesson_pages', 'contents', '<p>Sin enlace</p>', ['id' => $second->id]);

        template_syllabus_linker::apply($course->id, [(int) $lesson->cmid], $this->syllabus('guia.pdf'));

        $firstcontents = $DB->get_field('lesson_pages', 'contents', ['id' => $first->id]);
        $this->assertStringContainsString('@@PLUGINFILE@@/guia.pdf', $firstcontents);
        $this->assertSame('<p>Sin enlace</p>', $DB->get_field('lesson_pages', 'contents', ['id' => $second->id]));
        $this->assertSame(['guia.pdf'], $this->files((int) $lesson->cmid, 'mod_lesson', 'page_contents', (int) $first->id));
        $this->assertSame([], $this->files((int) $lesson->cmid, 'mod_lesson', 'page_contents', (int) $second->id));
    }

    /**
     * A label links the copy kept in its intro area, and two links in one text share one copy.
     */
    public function test_a_label_keeps_one_copy_for_several_links(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $label = $this->getDataGenerator()->create_module('label', [
            'course' => $course->id,
            'intro' => self::LINK . '<p>y</p>' . self::LINK,
        ]);

        template_syllabus_linker::apply($course->id, [(int) $label->cmid], $this->syllabus('g.pdf'));

        $intro = $DB->get_field('label', 'intro', ['id' => $label->id], MUST_EXIST);
        $this->assertSame(2, substr_count($intro, '@@PLUGINFILE@@/g.pdf'));
        $this->assertSame(['g.pdf'], $this->files((int) $label->cmid, 'mod_label', 'intro', 0));
    }

    /**
     * Without an uploaded syllabus the links are taken out and no file is made.
     */
    public function test_without_a_syllabus_the_links_are_taken_out(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'content' => '<p>Plan</p>' . self::LINK,
        ]);

        template_syllabus_linker::apply($course->id, [(int) $page->cmid], null);

        $this->assertSame('<p>Plan</p>', $DB->get_field('page', 'content', ['id' => $page->id], MUST_EXIST));
        $this->assertSame([], $this->files((int) $page->cmid, 'mod_page', 'content', 0));
    }

    /**
     * An activity that is not generated, or of a module that is not searched, is not touched.
     */
    public function test_only_the_given_activities_of_searched_modules_are_touched(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $kept = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'content' => self::LINK]);
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'intro' => self::LINK]);

        template_syllabus_linker::apply($course->id, [(int) $forum->cmid], $this->syllabus());

        $this->assertSame(self::LINK, $DB->get_field('page', 'content', ['id' => $kept->id]));
        $this->assertSame(self::LINK, $DB->get_field('forum', 'intro', ['id' => $forum->id]));
    }

    /**
     * The file a template session was made from is kept with the session and read back, and replaced when kept again.
     */
    public function test_the_session_keeps_its_syllabus(): void {
        $this->resetAfterTest();

        $this->assertNull(course_session_service::syllabus_file(77));
        course_session_service::keep_syllabus(77, $this->syllabus('primera.pdf'));
        course_session_service::keep_syllabus(77, $this->syllabus('segunda.pdf'));

        $file = course_session_service::syllabus_file(77);
        $this->assertNotNull($file);
        $this->assertSame('segunda.pdf', $file->get_filename());
        $this->assertSame('%PDF-1.4 syllabus', $file->get_content());
        $this->assertNull(course_session_service::syllabus_file(78));
    }
}

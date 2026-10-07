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

use local_coursegen\local\service\activity_link_resolver;

/**
 * Resolving the link tokens of the generated activities into real URLs.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\activity_link_resolver
 *
 * @runTestsInSeparateProcesses
 */
final class activity_link_resolver_test extends \advanced_testcase {
    /**
     * The token of one uid, as the AI writes it inside an href.
     *
     * @param string $uid
     * @return string
     */
    private function link(string $uid): string {
        return '<a href="$@COURSEGENLINK*' . $uid . '@$">Go</a>';
    }

    /**
     * The URL a course module is reached by.
     *
     * @param string $modname
     * @param int $cmid
     * @return string
     */
    private function url(string $modname, int $cmid): string {
        global $CFG;
        return $CFG->wwwroot . '/mod/' . $modname . '/view.php?id=' . $cmid;
    }

    /**
     * The payload entries of the given uid => payload cmid pairs.
     *
     * @param array $cmids uid => payload cmid
     * @return array
     */
    private function payload(array $cmids): array {
        $payload = [];
        foreach ($cmids as $uid => $cmid) {
            $payload[] = ['uid' => $uid, 'cmid' => $cmid];
        }
        return $payload;
    }

    /**
     * Tokens in the content and the intro of a generated page become the URL of the target.
     */
    public function test_replaces_tokens_in_page_content_and_intro(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'content' => '<p>' . $this->link('uid-forum') . '</p>',
            'intro' => '<p>' . $this->link('uid-forum') . '</p>',
        ]);

        activity_link_resolver::resolve_for_course(
            $course->id,
            $this->payload(['uid-forum' => -3]),
            [-3 => (int) $target->cmid, -4 => (int) $page->cmid],
            []
        );

        $record = $DB->get_record('page', ['id' => $page->id], '*', MUST_EXIST);
        $expected = '<p><a href="' . $this->url('forum', (int) $target->cmid) . '">Go</a></p>';
        $this->assertSame($expected, $record->content);
        $this->assertSame($expected, $record->intro);
    }

    /**
     * An iframe that points at a file resource shows the stored file; a link to it still opens the resource.
     */
    public function test_a_src_to_a_file_resource_resolves_to_the_url_of_its_stored_file(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', [
            'course' => $course->id,
            'files' => $this->draft_with('guide new.pdf'),
        ]);
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'content' => '<a href="$@COURSEGENLINK*uid-file@$">Open</a><iframe src="$@COURSEGENLINK*uid-file@$"></iframe>',
        ]);

        activity_link_resolver::resolve_for_course(
            $course->id,
            $this->payload(['uid-file' => -3]),
            [-3 => (int) $resource->cmid, -4 => (int) $page->cmid],
            []
        );

        $record = $DB->get_record('page', ['id' => $page->id], '*', MUST_EXIST);
        $context = \context_module::instance((int) $resource->cmid);
        $revision = (int) $DB->get_field('resource', 'revision', ['id' => $resource->id]);
        $fileurl = \moodle_url::make_pluginfile_url($context->id, 'mod_resource', 'content', $revision, '/', 'guide new.pdf');
        $this->assertStringContainsString('<iframe src="' . s($fileurl->out(false)) . '">', $record->content);
        $this->assertStringContainsString('href="' . $this->url('resource', (int) $resource->cmid) . '"', $record->content);
        $this->assertStringContainsString('/pluginfile.php/' . $context->id . '/mod_resource/content/', $record->content);
    }

    /**
     * A resource that holds no file leaves the src on the page URL instead of failing.
     */
    public function test_a_src_to_a_resource_without_a_file_falls_back_to_its_page_url(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $fs = get_file_storage();
        $context = \context_module::instance((int) $resource->cmid);
        $fs->delete_area_files($context->id, 'mod_resource', 'content');
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'content' => '<iframe src="$@COURSEGENLINK*uid-file@$"></iframe>',
        ]);

        activity_link_resolver::resolve_for_course(
            $course->id,
            $this->payload(['uid-file' => -3]),
            [-3 => (int) $resource->cmid, -4 => (int) $page->cmid],
            []
        );

        $record = $DB->get_record('page', ['id' => $page->id], '*', MUST_EXIST);
        $this->assertSame('<iframe src="' . $this->url('resource', (int) $resource->cmid) . '"></iframe>', $record->content);
    }

    /**
     * A src to an activity that is not a file resource keeps the page URL.
     */
    public function test_a_src_to_another_kind_of_activity_keeps_its_page_url(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'content' => '<iframe src="$@COURSEGENLINK*uid-forum@$"></iframe>',
        ]);

        activity_link_resolver::resolve_for_course(
            $course->id,
            $this->payload(['uid-forum' => -3]),
            [-3 => (int) $target->cmid, -4 => (int) $page->cmid],
            []
        );

        $record = $DB->get_record('page', ['id' => $page->id], '*', MUST_EXIST);
        $this->assertSame('<iframe src="' . $this->url('forum', (int) $target->cmid) . '"></iframe>', $record->content);
    }

    /**
     * A draft area of the current user holding one file, for a resource created by the generator.
     *
     * @param string $filename
     * @return int The draft item id.
     */
    private function draft_with(string $filename): int {
        global $USER;
        $draftid = file_get_unused_draft_itemid();
        $record = [
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => $filename,
        ];
        get_file_storage()->create_file_from_string($record, 'pdf bytes');
        return $draftid;
    }

    /**
     * Tokens in the content of a generated label become the URL of the target.
     */
    public function test_replaces_tokens_in_a_label_intro(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $label = $this->getDataGenerator()->create_module('label', [
            'course' => $course->id,
            'intro' => '<p>' . $this->link('uid-page') . '</p>',
        ]);

        activity_link_resolver::resolve_for_course(
            $course->id,
            $this->payload(['uid-page' => -1]),
            [-1 => (int) $target->cmid, -2 => (int) $label->cmid],
            []
        );

        $intro = $DB->get_field('label', 'intro', ['id' => $label->id]);
        $this->assertSame('<p><a href="' . $this->url('page', (int) $target->cmid) . '">Go</a></p>', $intro);
    }

    /**
     * Tokens in every page of a generated lesson are resolved.
     */
    public function test_replaces_tokens_in_every_lesson_page(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $lesson = $this->getDataGenerator()->create_module('lesson', ['course' => $course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_lesson');
        $generator->create_content($lesson);
        $generator->create_content($lesson);
        $DB->set_field('lesson_pages', 'contents', '<p>' . $this->link('uid-page') . '</p>', ['lessonid' => $lesson->id]);

        activity_link_resolver::resolve_for_course(
            $course->id,
            $this->payload(['uid-page' => -1]),
            [-1 => (int) $target->cmid, -2 => (int) $lesson->cmid],
            []
        );

        $contents = $DB->get_fieldset_select('lesson_pages', 'contents', 'lessonid = ?', [$lesson->id]);
        $this->assertCount(2, $contents);
        $expected = '<p><a href="' . $this->url('page', (int) $target->cmid) . '">Go</a></p>';
        $this->assertSame([$expected, $expected], $contents);
    }

    /**
     * A kept activity is a target as well as a generated one.
     */
    public function test_kept_activities_are_link_targets(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $generated = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $kept = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'content' => $this->link('uid-generated') . $this->link('uid-kept'),
        ]);

        activity_link_resolver::resolve_for_course(
            $course->id,
            $this->payload(['uid-generated' => -2, 'uid-kept' => 31]),
            [-2 => (int) $generated->cmid, -9 => (int) $page->cmid],
            [31 => (int) $kept->cmid]
        );

        $content = $DB->get_field('page', 'content', ['id' => $page->id], MUST_EXIST);
        $this->assertStringContainsString('href="' . $this->url('forum', (int) $generated->cmid) . '"', $content);
        $this->assertStringContainsString('href="' . $this->url('assign', (int) $kept->cmid) . '"', $content);
    }

    /**
     * An unknown uid fails the run, naming the activity and the uid.
     */
    public function test_unknown_uid_throws_naming_the_activity_and_the_uid(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => 'Welcome page',
            'content' => $this->link('uid-nowhere'),
        ]);

        try {
            activity_link_resolver::resolve_for_course($course->id, [], [-1 => (int) $page->cmid], []);
            $this->fail('An unknown uid must throw.');
        } catch (\moodle_exception $exception) {
            $this->assertStringContainsString('Welcome page', $exception->getMessage());
            $this->assertStringContainsString('uid-nowhere', $exception->getMessage());
        }
    }

    /**
     * When one token cannot be resolved nothing is rewritten.
     */
    public function test_a_failure_leaves_every_text_as_it_was(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $good = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'content' => $this->link('uid-forum'),
        ]);
        $bad = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'content' => $this->link('uid-nowhere'),
        ]);

        try {
            activity_link_resolver::resolve_for_course(
                $course->id,
                $this->payload(['uid-forum' => -3]),
                [-3 => (int) $target->cmid, -4 => (int) $good->cmid, -5 => (int) $bad->cmid],
                []
            );
            $this->fail('An unknown uid must throw.');
        } catch (\moodle_exception $exception) {
            $this->assertSame($this->link('uid-forum'), $DB->get_field('page', 'content', ['id' => $good->id]));
        }
    }

    /**
     * A target with no page of its own, such as a label, cannot be linked to.
     */
    public function test_target_without_a_view_url_throws(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $label = $this->getDataGenerator()->create_module('label', ['course' => $course->id]);
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'content' => $this->link('uid-label'),
        ]);

        $this->expectException(\moodle_exception::class);

        activity_link_resolver::resolve_for_course(
            $course->id,
            $this->payload(['uid-label' => -5]),
            [-5 => (int) $label->cmid, -6 => (int) $page->cmid],
            []
        );
    }

    /**
     * Other $@...@$ forms are never decoded or reported.
     */
    public function test_other_dollar_at_forms_are_untouched(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $content = '<p>$@COURSEGENIMG*uid-1@$ and $@anything@$ and price $@5@$</p>';
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'content' => $content]);

        activity_link_resolver::resolve_for_course($course->id, [], [-1 => (int) $page->cmid], []);

        $this->assertSame($content, $DB->get_field('page', 'content', ['id' => $page->id]));
    }

    /**
     * Activities that were not generated are not read or changed.
     */
    public function test_activities_that_were_not_generated_are_untouched(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $content = $this->link('uid-forum');
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'content' => $content]);

        activity_link_resolver::resolve_for_course(
            $course->id,
            $this->payload(['uid-forum' => -3]),
            [-3 => (int) $target->cmid],
            []
        );

        $this->assertSame($content, $DB->get_field('page', 'content', ['id' => $page->id]));
    }

    /**
     * A generated activity of a module the resolver does not cover is not read or changed.
     */
    public function test_generated_activity_of_an_uncovered_module_is_untouched(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $intro = $this->link('uid-forum');
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'intro' => $intro]);

        activity_link_resolver::resolve_for_course(
            $course->id,
            $this->payload(['uid-forum' => -1]),
            [-1 => (int) $forum->cmid],
            []
        );

        $this->assertSame($intro, $DB->get_field('forum', 'intro', ['id' => $forum->id]));
    }
}

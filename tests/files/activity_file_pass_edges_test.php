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

namespace local_coursegen\local\files;

use local_coursegen\local\service\create_mod_service;
use local_coursegen\tests\fixtures\file_scenarios;
use local_coursegen\utils\generated_file_cache;
use local_coursegen\utils\generated_files_scope;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../fixtures/file_scenarios.php');
require_once($CFG->libdir . '/testing/generator/lib.php');

/**
 * The modules whose package cannot come from the service, and what happens when a file cannot be given.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\files\activity_file_pass
 * @covers     \local_coursegen\local\files\file_copy_exception
 */
final class activity_file_pass_edges_test extends \advanced_testcase {
    /**
     * The modules created from a package, with each source of files.
     *
     * @return array
     */
    public static function package_provider(): array {
        $cases = [];
        foreach (file_scenarios::PACKAGE_MODULES as $module) {
            $cases[$module . ' from the template'] = [$module, 'template'];
            $cases[$module . ' from the AI service'] = [$module, 'generated'];
        }
        return $cases;
    }

    /**
     * A text of the introduction of a module created from a package receives its file.
     *
     * @dataProvider package_provider
     * @param string $module
     * @param string $kind
     */
    public function test_the_intro_of_a_package_module_receives_its_file(string $module, string $kind): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $sourcecourse = $this->getDataGenerator()->create_course();
        $course = $this->getDataGenerator()->create_course();
        $dest = $this->getDataGenerator()->create_module($module, ['course' => $course->id]);
        $generated = [];
        $sourceid = null;
        if ($kind === 'template') {
            $source = $this->getDataGenerator()->create_module($module, ['course' => $sourcecourse->id]);
            $sourceid = (int) $sourcecourse->id;
            $sourcecontext = \context_module::instance($source->cmid);
            get_file_storage()->create_file_from_string([
                'contextid' => $sourcecontext->id, 'component' => 'mod_' . $module, 'filearea' => 'intro', 'itemid' => 0,
                'filepath' => '/', 'filename' => 'pic.png',
            ], 'PICTURE');
            $reference = \moodle_url::make_pluginfile_url($sourcecontext->id, 'mod_' . $module, 'intro', 0, '/', 'pic.png');
            $address = $reference->out(false);
        } else {
            $entry = ['filename' => 'pic.png', 'mimetype' => 'image/png', 'size' => 7, 'thread_id' => 't', 'file_id' => 'f.png'];
            get_file_storage()->create_file_from_string(generated_file_cache::file_record($entry), 'PICTURE');
            $generated = [$entry];
            $address = '@@PLUGINFILE@@/pic.png';
        }
        $DB->set_field($module, 'intro', '<p><img src="' . $address . '"></p>', ['id' => $dest->id]);
        $activity = (object) [
            'id' => (int) $dest->cmid, 'instance' => (int) $dest->id, 'modname' => $module, 'course' => (int) $course->id,
        ];
        $pass = activity_file_pass::for_new_activity($sourceid);

        generated_files_scope::run($generated, static function () use ($pass, $activity) {
            $pass->run($activity, 'Intro');
        });

        $context = \context_module::instance($dest->cmid);
        $stored = get_file_storage()->get_file($context->id, 'mod_' . $module, 'intro', 0, '/', 'pic.png');
        $this->assertNotFalse($stored);
        $this->assertSame('PICTURE', $stored->get_content());
        $this->assertSame('<p><img src="@@PLUGINFILE@@/pic.png"></p>', $DB->get_field($module, 'intro', ['id' => $dest->id]));
    }

    /**
     * A page result whose content is the given text.
     *
     * @param string $content
     * @return array
     */
    private function page_result(string $content): array {
        $parameters = file_scenarios::base_params('page', [
            'name' => 'Page', 'page' => ['text' => $content, 'format' => 1], 'display' => 5, 'printintro' => 0,
            'printlastmodified' => 1, 'printheading' => 1,
        ]);
        return ['resource_type' => 'page', 'parameters' => $parameters];
    }

    /**
     * A placeholder that no source holds stops the creation, names the file and leaves no half made activity.
     */
    public function test_a_placeholder_without_a_file_raises_and_leaves_no_activity(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $PAGE->set_course($course);

        try {
            create_mod_service::create_from_ai_result($this->page_result('<img src="@@PLUGINFILE@@/ghost.png">'), $course, 1);
            $this->fail('The creation had to fail');
        } catch (file_copy_exception $exception) {
            $this->assertSame('error_file_missing', $exception->errorcode);
            $this->assertSame('ghost.png', $exception->a['file']);
            $this->assertStringContainsString('page.content', $exception->a['where']);
        }

        $this->assertFalse($DB->record_exists('page', ['course' => $course->id]));
    }

    /**
     * The address of a file that is not there stops the creation.
     */
    public function test_an_address_of_a_missing_file_raises(): void {
        global $CFG, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $PAGE->set_course($course);
        $label = $this->getDataGenerator()->create_module('label', ['course' => $course->id]);
        $context = \context_module::instance($label->cmid);
        $address = $CFG->wwwroot . '/pluginfile.php/' . $context->id . '/mod_label/intro/0/nothere.png';

        $this->expectException(file_copy_exception::class);

        create_mod_service::create_from_ai_result($this->page_result('<img src="' . $address . '">'), $course, 1);
    }

    /**
     * A file of a course that is not the template's is not copied.
     */
    public function test_a_file_of_another_course_than_the_template_raises(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $template = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $course = $this->getDataGenerator()->create_course();
        $PAGE->set_course($course);
        $label = $this->getDataGenerator()->create_module('label', ['course' => $other->id]);
        $context = \context_module::instance($label->cmid);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_label', 'filearea' => 'intro', 'itemid' => 0,
            'filepath' => '/', 'filename' => 'o.png',
        ], 'OTHER');
        $address = \moodle_url::make_pluginfile_url($context->id, 'mod_label', 'intro', 0, '/', 'o.png');

        $this->expectException(file_copy_exception::class);

        $result = $this->page_result('<img src="' . $address->out(false) . '">');
        create_mod_service::create_from_ai_result($result, $course, 1, null, (int) $template->id);
    }

    /**
     * A link to a file that belongs to another place than the text's own is a link, and stays one.
     */
    public function test_a_link_to_a_file_of_another_component_is_kept(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $template = $this->getDataGenerator()->create_course();
        $course = $this->getDataGenerator()->create_course();
        $PAGE->set_course($course);
        $label = $this->getDataGenerator()->create_module('label', ['course' => $template->id]);
        $context = \context_module::instance($label->cmid);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_label', 'filearea' => 'intro', 'itemid' => 0,
            'filepath' => '/', 'filename' => 'doc.pdf',
        ], 'PDF');
        $address = \moodle_url::make_pluginfile_url($context->id, 'mod_label', 'intro', 0, '/', 'doc.pdf')->out(false);
        $text = '<a href="' . $address . '">doc</a>';

        $cm = create_mod_service::create_from_ai_result($this->page_result($text), $course, 1, null, (int) $template->id);

        $this->assertSame($text, $DB->get_field('page', 'content', ['id' => $cm->instance]));
    }

    /**
     * A placeholder in a field the module shows as written cannot be served, so it is refused.
     */
    public function test_a_placeholder_in_a_field_shown_as_written_raises(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $PAGE->set_course($course);
        $parameters = file_scenarios::base_params('data', [
            'name' => 'D', 'approval' => 0, 'comments' => 0, 'requiredentries' => 0, 'requiredentriestoview' => 0,
            'maxentries' => 0, 'rssarticles' => 0, 'manageapproved' => 1,
            'mod_settings' => [
                'fields' => [['type' => 'text', 'name' => 'T']],
                'templates' => ['singletemplate' => '<img src="@@PLUGINFILE@@/x.png">'],
            ],
        ]);

        $this->expectException(file_copy_exception::class);

        create_mod_service::create_from_ai_result(['resource_type' => 'data', 'parameters' => $parameters], $course, 1);
    }

    /**
     * An area that already holds a different file under the same name is not overwritten.
     */
    public function test_a_different_file_with_the_same_name_raises(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $context = \context_module::instance($page->cmid);
        $record = [
            'contextid' => $context->id, 'component' => 'mod_page', 'filearea' => 'content', 'itemid' => 0,
            'filepath' => '/', 'filename' => 'a.png',
        ];
        get_file_storage()->create_file_from_string($record, 'OLD');
        $entry = ['filename' => 'a.png', 'mimetype' => 'image/png', 'size' => 3, 'thread_id' => 't', 'file_id' => 'f.png'];
        get_file_storage()->create_file_from_string(generated_file_cache::file_record($entry), 'NEW');
        $DB->set_field('page', 'content', '<img src="@@PLUGINFILE@@/a.png">', ['id' => $page->id]);
        $activity = (object) [
            'id' => (int) $page->cmid, 'instance' => (int) $page->id, 'modname' => 'page', 'course' => (int) $course->id,
        ];
        $pass = activity_file_pass::for_new_activity(null);

        $this->expectException(file_copy_exception::class);

        generated_files_scope::run([$entry], static function () use ($pass, $activity) {
            $pass->run($activity, 'Page');
        });
    }

    /**
     * Leftover image markers are removed from the texts of the activity.
     */
    public function test_leftover_image_markers_are_removed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $text = '<p>A ⟦coursegen:image: banner⟧ B [[coursegen:image: two]] C {{image: three}}</p>';
        $DB->set_field('page', 'content', $text, ['id' => $page->id]);
        $activity = (object) [
            'id' => (int) $page->cmid, 'instance' => (int) $page->id, 'modname' => 'page', 'course' => (int) $course->id,
        ];

        activity_file_pass::for_new_activity(null)->run($activity, 'Page');

        $this->assertSame('<p>A  B  C </p>', $DB->get_field('page', 'content', ['id' => $page->id]));
    }
}

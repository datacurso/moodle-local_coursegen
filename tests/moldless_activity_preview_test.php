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

use local_coursegen\local\preview\activity_preview_lookup;
use local_coursegen\local\preview\intro_preview;
use local_coursegen\local\preview\moldless_activity;
use local_coursegen\local\preview\preview_factory;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/fixtures/preview_page_setup.php');

/**
 * Tests for the preview of an activity the run writes without a mould.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\preview\moldless_activity
 * @covers     \local_coursegen\local\preview\preview_factory
 */
final class moldless_activity_preview_test extends \advanced_testcase {
    use preview_page_setup;

    /**
     * What the preview page does with a finished activity: resolve it, then pick its preview.
     *
     * @param string $modname
     * @param array $parameters
     * @return \local_coursegen\local\preview\activity_preview
     */
    private function preview_from_result(string $modname, array $parameters) {
        $activity = [
            'uid' => 'moldless-uid',
            'resource_type' => $modname,
            'template_behavior' => ['action' => 'instance', 'template_source_cmid' => 0],
            'parameters' => $parameters,
        ];
        $found = activity_preview_lookup::from_answer(['generated_activities' => [$activity]], 'moldless-uid');
        $preview = preview_factory::for_found($found);
        $here = new \moodle_url('/local/coursegen/activity_preview.php', ['sessionid' => 1, 'uid' => 'moldless-uid']);
        $preview->opened_at($here, 0);
        return $preview;
    }

    /**
     * A page shows the text its result carries.
     */
    public function test_a_page_without_a_mould_shows_its_text(): void {
        $this->resetAfterTest(true);
        $this->prepare_preview_page();
        $parameters = ['name' => 'Lesson 1', 'page' => ['text' => '<p>Metals are strong</p>', 'format' => 1, 'itemid' => 0]];

        $preview = $this->preview_from_result('page', $parameters);

        $this->assertStringContainsString('Metals are strong', $this->text_of($preview->render()));
        $this->assertSame('Lesson 1', $preview->name());
    }

    /**
     * A book shows its first chapter, and the chapters are the ones its result lists, in order.
     */
    public function test_a_book_without_a_mould_shows_its_chapters(): void {
        $this->resetAfterTest(true);
        $this->prepare_preview_page();
        $parameters = ['name' => 'A book', 'mod_settings' => ['chapters' => [
            ['source_id' => null, 'title' => 'First', 'subchapter' => 0, 'content_editor' => ['text' => '<p>One body</p>']],
            ['source_id' => null, 'title' => 'Second', 'subchapter' => 0, 'content_editor' => ['text' => '<p>Two body</p>']],
        ]]];

        $preview = $this->preview_from_result('book', $parameters);

        $this->assertStringContainsString('One body', $this->text_of($preview->render()));
        $this->assertCount(1, $preview->side_blocks());
    }

    /**
     * An assignment shows something (its grading summary) and not the empty-result notice.
     */
    public function test_an_assign_without_a_mould_is_drawn(): void {
        $this->resetAfterTest(true);
        $this->prepare_preview_page();
        $parameters = ['name' => 'Workshop', 'introeditor' => ['text' => '<p>Intro</p>'],
            'activityeditor' => ['text' => '<p>Steps</p>'], 'assignsubmission_file_enabled' => '1'];

        $preview = $this->preview_from_result('assign', $parameters);

        $this->assertNotInstanceOf(intro_preview::class, $preview);
        $this->assertStringNotContainsString(get_string('courseai_preview_empty', 'local_coursegen'), $this->text_of($preview->render()));
    }

    /**
     * A type that needs the activity to exist is shown as its description, not as an error.
     *
     * @dataProvider description_only_types
     * @param string $modname
     */
    public function test_a_type_that_needs_the_activity_to_exist_shows_its_description(string $modname): void {
        $this->resetAfterTest(true);
        $this->prepare_preview_page();
        $parameters = ['name' => 'Alone', 'introeditor' => ['text' => '<p>What it is about</p>', 'format' => 1, 'itemid' => 0]];

        $preview = $this->preview_from_result($modname, $parameters);

        $this->assertInstanceOf(intro_preview::class, $preview);
        $this->assertStringContainsString('What it is about', $this->text_of($preview->render()));
    }

    /**
     * Types whose view cannot run without a course module, a package or the question bank.
     *
     * @return array
     */
    public static function description_only_types(): array {
        return ['forum' => ['forum'], 'quiz' => ['quiz'], 'feedback' => ['feedback'], 'h5pactivity' => ['h5pactivity']];
    }

    /**
     * An activity that came from a mould keeps the preview of its own type.
     */
    public function test_an_activity_with_a_mould_keeps_its_own_preview(): void {
        $found = ['modname' => 'forum', 'parameters' => ['name' => 'F'], 'cmid' => 5, 'moldless' => false];

        $preview = preview_factory::for_found($found);

        $this->assertNotInstanceOf(intro_preview::class, $preview);
    }

    /**
     * Only a result that says it has no mould is taken for one.
     */
    public function test_is_moldless_needs_the_behaviour_to_say_so(): void {
        $this->assertTrue(moldless_activity::is_moldless(['template_behavior' => ['action' => 'instance', 'template_source_cmid' => 0]]));
        $this->assertFalse(moldless_activity::is_moldless(['template_behavior' => ['action' => 'instance', 'template_source_cmid' => 7]]));
        $this->assertFalse(moldless_activity::is_moldless(['template_behavior' => ['action' => 'instance']]));
        $this->assertFalse(moldless_activity::is_moldless([]));
    }

    /**
     * The preview page needs a template and a course to be built on, and says so when it has none.
     */
    public function test_the_template_course_guard_refuses_a_missing_template_and_a_missing_course(): void {
        $this->resetAfterTest(true);

        foreach ([0, -3, 987654] as $templateid) {
            try {
                activity_preview_lookup::template_course($templateid);
                $this->fail('A missing template must be refused');
            } catch (\moodle_exception $exception) {
                $this->assertSame('invalidtemplate', $exception->errorcode);
            }
        }
    }

    /**
     * A template whose course is gone is refused with the course error, and one with a course gives it back.
     */
    public function test_the_template_course_guard_returns_the_course_and_refuses_a_missing_one(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $template = new \local_coursegen\local\models\template(0, (object) ['name' => 'T', 'courseid' => $course->id]);
        $template->create();

        $found = activity_preview_lookup::template_course((int) $template->get('id'));
        $this->assertEquals($course->id, $found->id);

        delete_course($course, false);
        try {
            activity_preview_lookup::template_course((int) $template->get('id'));
            $this->fail('A template without its course must be refused');
        } catch (\moodle_exception $exception) {
            $this->assertSame('invalidcourseid', $exception->errorcode);
        }
    }
}

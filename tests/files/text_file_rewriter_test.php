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

/**
 * The one way Moodle stores a reference to a file, whichever way a text spoke of it.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\files\text_file_rewriter
 * @covers     \local_coursegen\local\files\rewritten_text
 */
final class text_file_rewriter_test extends \advanced_testcase {
    /**
     * A stored file in the intro area of a label.
     *
     * @param string $name
     * @param string $content
     * @param string $filepath
     * @return \stored_file
     */
    private function label_file(string $name, string $content, string $filepath = '/'): \stored_file {
        $course = $this->getDataGenerator()->create_course();
        $label = $this->getDataGenerator()->create_module('label', ['course' => $course->id]);
        $context = \context_module::instance($label->cmid);
        return get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_label', 'filearea' => 'intro', 'itemid' => 0,
            'filepath' => $filepath, 'filename' => $name,
        ], $content);
    }

    /**
     * A source that holds the given files by the value of their reference.
     *
     * @param \stored_file[] $files Reference value => file.
     * @return file_source
     */
    private function source_of(array $files): file_source {
        return new class ($files) implements file_source {
            /** @var \stored_file[] */
            private array $files;

            /**
             * Constructor.
             *
             * @param \stored_file[] $files
             */
            public function __construct(array $files) {
                $this->files = $files;
            }

            #[\Override]
            public function find(file_reference $reference): ?\stored_file {
                return $this->files[$reference->value] ?? null;
            }
        };
    }

    /**
     * The areas of a label row.
     *
     * @return file_area[]
     */
    private function label_areas(): array {
        return [new file_area(1, 'mod_label', 'intro', 0)];
    }

    /**
     * The address of a file of the declared area becomes a placeholder and the file is handed back.
     *
     * @dataProvider attribute_provider
     * @param string $attribute
     */
    public function test_an_address_becomes_a_placeholder_for_every_attribute(string $attribute): void {
        global $CFG;
        $this->resetAfterTest();
        $file = $this->label_file('my guide.pdf', 'PDF');
        $address = $CFG->wwwroot . '/pluginfile.php/1/mod_label/intro/0/my%20guide.pdf';
        $rewriter = new text_file_rewriter($this->source_of([$address => $file]));

        $result = $rewriter->rewrite('<object ' . $attribute . '="' . $address . '"></object>', 'here', $this->label_areas());

        $this->assertSame('<object ' . $attribute . '="@@PLUGINFILE@@/my%20guide.pdf"></object>', $result->text);
        $this->assertSame(['/my guide.pdf' => $file], $result->files);
    }

    /**
     * The attributes that can hold a file.
     *
     * @return array
     */
    public static function attribute_provider(): array {
        return [['src'], ['href'], ['data'], ['poster']];
    }

    /**
     * An address of a file that belongs to another area than the row's is a link and is left as it is.
     */
    public function test_an_address_of_another_area_is_left_alone(): void {
        global $CFG;
        $this->resetAfterTest();
        $file = $this->label_file('doc.pdf', 'PDF');
        $address = $CFG->wwwroot . '/pluginfile.php/1/mod_label/intro/0/doc.pdf';
        $rewriter = new text_file_rewriter($this->source_of([$address => $file]));
        $text = '<a href="' . $address . '">doc</a>';

        $result = $rewriter->rewrite($text, 'here', [new file_area(1, 'mod_page', 'content', 0)]);

        $this->assertSame($text, $result->text);
        $this->assertSame([], $result->files);
    }

    /**
     * An address that no source resolves stops the rewriting, naming the address and where it was.
     */
    public function test_an_address_no_source_holds_raises(): void {
        global $CFG;
        $address = $CFG->wwwroot . '/pluginfile.php/1/mod_label/intro/0/gone.png';
        $rewriter = new text_file_rewriter($this->source_of([]));

        try {
            $rewriter->rewrite('<img src="' . $address . '">', 'the page', $this->label_areas());
            $this->fail('The rewriting had to fail');
        } catch (file_copy_exception $exception) {
            $this->assertSame('error_file_missing', $exception->errorcode);
            $this->assertSame($address, $exception->a['file']);
            $this->assertSame('the page', $exception->a['where']);
        }
    }

    /**
     * Addresses of other sites and placeholders are not touched.
     */
    public function test_foreign_addresses_and_placeholders_are_left_alone(): void {
        $rewriter = new text_file_rewriter($this->source_of([]));
        $text = '<img src="https://example.org/pluginfile.php/1/x/y/z.png"><img src="@@PLUGINFILE@@/a.png">';

        $result = $rewriter->rewrite($text, 'here', $this->label_areas());

        $this->assertSame($text, $result->text);
    }

    /**
     * Two different files that share a name are told apart by the path the text gives them.
     */
    public function test_two_files_with_the_same_name_get_different_paths(): void {
        global $CFG;
        $this->resetAfterTest();
        $first = $this->label_file('pic.png', 'ONE');
        $second = $this->label_file('pic.png', 'TWO');
        $one = $CFG->wwwroot . '/pluginfile.php/1/mod_label/intro/0/one/pic.png';
        $two = $CFG->wwwroot . '/pluginfile.php/1/mod_label/intro/0/two/pic.png';
        $rewriter = new text_file_rewriter($this->source_of([$one => $first, $two => $second]));

        $result = $rewriter->rewrite('<img src="' . $one . '"><img src="' . $two . '">', 'here', $this->label_areas());

        $this->assertSame('<img src="@@PLUGINFILE@@/pic.png"><img src="@@PLUGINFILE@@/pic-2.png">', $result->text);
        $this->assertSame('ONE', $result->files['/pic.png']->get_content());
        $this->assertSame('TWO', $result->files['/pic-2.png']->get_content());
    }

    /**
     * The same file used twice is one file.
     */
    public function test_the_same_file_twice_is_one_file(): void {
        global $CFG;
        $this->resetAfterTest();
        $file = $this->label_file('pic.png', 'ONE');
        $address = $CFG->wwwroot . '/pluginfile.php/1/mod_label/intro/0/pic.png';
        $rewriter = new text_file_rewriter($this->source_of([$address => $file]));

        $result = $rewriter->rewrite('<img src="' . $address . '"><a href="' . $address . '">x</a>', 'here', $this->label_areas());

        $this->assertSame('<img src="@@PLUGINFILE@@/pic.png"><a href="@@PLUGINFILE@@/pic.png">x</a>', $result->text);
        $this->assertCount(1, $result->files);
    }

    /**
     * The path of an image of the AI service, in an img tag or in markdown, becomes a placeholder.
     */
    public function test_the_path_of_a_generated_image_becomes_a_placeholder(): void {
        $this->resetAfterTest();
        $file = $this->label_file('chart.png', 'IMG');
        $path = '/data/generated_images/chart.png';
        $rewriter = new text_file_rewriter($this->source_of([$path => $file]));

        $html = $rewriter->rewrite('<img src="' . $path . '" alt="c">', 'here');
        $markdown = $rewriter->rewrite('![A chart](' . $path . ')', 'here');

        $this->assertSame('<img src="@@PLUGINFILE@@/chart.png" alt="c">', $html->text);
        $this->assertSame(
            '<img src="@@PLUGINFILE@@/chart.png" alt="A chart" style="max-width:100%;height:auto;" />',
            $markdown->text
        );
    }

    /**
     * An image path of the AI service that no source fetches stops the rewriting.
     */
    public function test_a_generated_image_that_cannot_be_fetched_raises(): void {
        $rewriter = new text_file_rewriter($this->source_of([]));

        $this->expectException(file_copy_exception::class);

        $rewriter->rewrite('<img src="/data/generated_images/chart.png">', 'here');
    }

    /**
     * Paths that are not an image of the AI service are not fetched.
     *
     * @dataProvider foreign_path_provider
     * @param string $path
     */
    public function test_other_paths_are_not_images_of_the_service(string $path): void {
        $rewriter = new text_file_rewriter($this->source_of([]));
        $text = '<img src="' . $path . '">';

        $this->assertSame($text, $rewriter->rewrite($text, 'here')->text);
    }

    /**
     * Paths that climb, use odd characters, or are addresses.
     *
     * @return array
     */
    public static function foreign_path_provider(): array {
        return [
            'climbing' => ['/tmp/../../etc/passwd'],
            'climbing in the folder' => ['/x/generated_images/../c.png'],
            'odd characters' => ['/tmp/generated_images/ok;rm.png'],
            'an address' => ['https://example.org/a.png'],
            'inline data' => ['data:image/png;base64,AAAA'],
            'relative' => ['images/a.png'],
        ];
    }

    /**
     * Markers the AI service left in a text are removed, in every dialect.
     */
    public function test_leftover_markers_are_removed(): void {
        $rewriter = new text_file_rewriter($this->source_of([]));
        $text = "<p>A ⟦coursegen:image:one⟧ B [[coursegen:image: two]] C {{image: three}} D</p>\n{{image: alone}}\n<p>E</p>";

        $result = $rewriter->rewrite($text, 'here');

        $this->assertSame("<p>A  B  C  D</p>\n\n<p>E</p>", $result->text);
    }

    /**
     * The placeholders of a text, as the paths they name.
     */
    public function test_placeholder_paths_lists_each_file_once(): void {
        $text = '<img src="@@PLUGINFILE@@/a%20b.png"><a href="@@PLUGINFILE@@/sub/c.pdf?x=1">c</a>'
            . '<img src="@@PLUGINFILE@@/a%20b.png">';

        $this->assertSame(['/a b.png', '/sub/c.pdf'], text_file_rewriter::placeholder_paths($text));
        $this->assertSame([], text_file_rewriter::placeholder_paths('<p>none</p>'));
    }

    /**
     * Paths are encoded one segment at a time.
     */
    public function test_encode_path_keeps_the_folders(): void {
        $this->assertSame('/sub%20dir/a%20b.png', text_file_rewriter::encode_path('/sub dir/a b.png'));
    }
}

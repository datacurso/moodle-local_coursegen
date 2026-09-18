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

namespace local_coursegen\local\preview;

/**
 * One activity type's read-only view, drawn from the AI's own answer.
 *
 * An activity that has not been created yet has no course module, no instance
 * and no context, so none of the module's own view code can run: every one of
 * those pages starts by loading the thing from the database. What they draw,
 * though, is a shape, and that shape is what a preview has to reproduce from
 * the description the AI returned.
 *
 * So each subclass mirrors one module's view page: the same headings, the same
 * boxes, the same order. Nothing it produces is interactive, because there is
 * nothing to interact with.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class activity_preview {
    /** @var array The activity's parameters, as the AI returned them. */
    protected array $parameters;

    /** @var \moodle_url Where this preview is being read, for a type that has pages. */
    protected \moodle_url $here;

    /** @var int Which of them is being read. */
    protected int $page = 0;

    /**
     * Constructor.
     *
     * @param array $parameters The activity's own parameters from the answer.
     * @param array $source The activity as the payload describes it, with its
     *                      structure, for a preview that runs the module's own
     *                      code against it. Unused by previews that draw from
     *                      the parameters alone.
     */
    public function __construct(array $parameters, array $source = []) {
        $this->parameters = $parameters;
        $this->here = new \moodle_url('/local/coursegen/activity_preview.php');
    }

    /**
     * Say where this preview is, and which of its pages is being read.
     *
     * An activity that is read one page at a time has to be able to link to
     * its own other pages, which it cannot do without knowing its own address.
     *
     * @param \moodle_url $here
     * @param int $page
     */
    public function opened_at(\moodle_url $here, int $page): void {
        $this->here = $here;
        $this->page = $page;
    }

    /**
     * This preview's address, showing a different page of it.
     *
     * @param int $page
     * @return \moodle_url
     */
    protected function page_url(int $page): \moodle_url {
        $url = new \moodle_url($this->here);
        $url->param('page', $page);
        return $url;
    }

    /**
     * The activity's name, as it will appear in the course.
     *
     * @return string
     */
    public function name(): string {
        return (string) ($this->parameters['name'] ?? '');
    }

    /**
     * The activity's view, as HTML.
     *
     * @return string
     */
    abstract public function render(): string;

    /**
     * What the module puts in the page header's button slot, if anything -
     * mod_wiki puts its search box there. Rendered HTML, or an empty string.
     *
     * @return string
     */
    public function header_button(): string {
        return '';
    }

    /**
     * The activity's own row, for the page to be told what it is about.
     *
     * A preview drawn from the module's own rows has one; a preview drawn from
     * the answer alone has none, and the page shows no activity header for it.
     *
     * @return \stdClass|null
     */
    public function activity_record(): ?\stdClass {
        return null;
    }

    /**
     * What the module puts in the activity header in place of its name, if anything.
     *
     * Most modules leave the header to the theme, which prints the name. A
     * module that sets the header's title itself - a workshop puts its name
     * beside a help icon - says so here; an empty string leaves it to the
     * theme.
     *
     * @return string
     */
    public function header_title(): string {
        return '';
    }

    /**
     * What belongs in the activity header, under the name.
     *
     * Most modules put their description there, and a module that shows it
     * somewhere else, or not at all, overrides this to say so.
     *
     * @return string
     */
    public function header_description(): string {
        $intro = trim($this->text('introeditor'));
        if ($intro === '') {
            $intro = trim($this->text('intro'));
        }
        return $intro === '' ? '' : $this->content($intro);
    }

    /**
     * Whether this module's own page is read at the narrower width.
     *
     * A module decides this for itself, and about half of them choose it, so
     * a preview that assumed either way would be wrong about half the types.
     * Each preview answers the way its own module's view page does.
     *
     * @return bool
     */
    public function limited_width(): bool {
        return false;
    }

    /**
     * The side blocks this activity's page carries, if any.
     *
     * A lesson's menu is the one that matters today; the rest of the types have
     * none, which is why this answers with nothing by default.
     *
     * @return \block_contents[]
     */
    public function side_blocks(): array {
        return [];
    }

    /**
     * One list of things the activity is made of, from its settings.
     *
     * A module's parts travel under mod_settings: a book's chapters, a quiz's
     * questions, a forum's discussions. Every type reads its own key, and a
     * type whose generator produced none reads an empty list rather than a
     * missing one.
     *
     * @param string $key The settings key holding the list.
     * @return array
     */
    protected function items(string $key): array {
        $items = ($this->parameters['mod_settings'] ?? [])[$key] ?? [];
        return is_array($items) ? $items : [];
    }

    /**
     * One editor field of an item, whatever shape it arrived in.
     *
     * The same field reaches here as `content`, as `content_editor`, or as the
     * {text, format} pair inside either, depending on the type and on whether
     * the value came from the draft or from the finished answer.
     *
     * @param array $item
     * @param string $key The field's base name, without the editor suffix.
     * @return string
     */
    protected function field(array $item, string $key): string {
        foreach ([$key . '_editor', $key] as $candidate) {
            $value = $item[$candidate] ?? null;
            if (is_array($value) && isset($value['text'])) {
                return (string) $value['text'];
            }
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }
        return '';
    }

    /**
     * What is shown when the answer describes nothing to show.
     *
     * @return string
     */
    protected function nothing_yet(): string {
        global $OUTPUT;

        return $OUTPUT->notification(
            get_string('courseai_preview_empty', 'local_coursegen'),
            \core\output\notification::NOTIFY_INFO
        );
    }

    /**
     * One text field of the answer, whatever shape it arrived in.
     *
     * A module's text is an editor field, and an editor field travels either as
     * the plain string or as the {text, format} pair Moodle's forms use. Both
     * reach here depending on the field and the generator that filled it.
     *
     * @param string $key
     * @return string
     */
    protected function text(string $key): string {
        $value = $this->parameters[$key] ?? '';
        if (is_array($value)) {
            return (string) ($value['text'] ?? '');
        }
        return (string) $value;
    }

    /**
     * Content written by the AI, cleaned for display.
     *
     * Everything shown in a preview went through a language model, so it is
     * cleaned the same way Moodle cleans any user-submitted HTML before it
     * reaches a page.
     *
     * @param string $html
     * @return string
     */
    protected function content(string $html): string {
        return format_text($html, FORMAT_HTML, ['noclean' => false, 'context' => \context_system::instance()]);
    }
}

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
 * The preview of an activity that the generator of its type wrote from scratch.
 *
 * It shows what the activity will be made of, read from its settings: the description, each list of parts (the
 * chapters of a book, the questions of a quiz, the entries of a glossary) with the title and the text of every part,
 * and the plain facts of its parameters. One class serves every type; what each type shows is declared in
 * {@see settings_preview_declarations}.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class settings_preview extends activity_preview {
    /** @var string Name of the module the preview is of. */
    private string $modname;

    /**
     * Constructor.
     *
     * @param string $modname Name of the module.
     * @param array $parameters The parameters of the activity, as the generator of its type answered them.
     * @param int $cmid Course module id of the template activity it is built into, 0 when none.
     */
    public function __construct(string $modname, array $parameters, int $cmid = 0) {
        parent::__construct($parameters, $cmid);
        $this->modname = $modname;
    }

    /**
     * Draw what the activity is made of.
     *
     * @return string
     */
    public function render(): string {
        global $OUTPUT;

        $intro = $this->header_description();
        $facts = $this->facts();
        $groups = $this->groups();
        if ($intro === '' && $facts === [] && $groups === []) {
            $message = get_string('courseai_preview_empty', 'local_coursegen');
            return $OUTPUT->notification($message, \core\output\notification::NOTIFY_INFO);
        }
        $context = [
            'hasintro' => $intro !== '',
            'intro' => $intro,
            'facts' => $facts,
            'groups' => $groups,
        ];
        return $OUTPUT->render_from_template('local_coursegen/settings_preview', $context);
    }

    /**
     * The facts of the parameters this type shows, labelled.
     *
     * @return array[] {label: string, value: string} for each fact that has a value.
     */
    private function facts(): array {
        $declared = settings_preview_declarations::facts_of($this->modname);
        $facts = [];
        foreach ($declared as [$key, $label]) {
            $rawvalue = $this->text($key);
            $value = trim($rawvalue);
            if ($value !== '') {
                $name = get_string($label, 'local_coursegen');
                $facts[] = ['label' => $name, 'value' => $value];
            }
        }
        return $facts;
    }

    /**
     * The lists of parts this type shows, each with its items; a list with no items is left out.
     *
     * @return array[]
     */
    private function groups(): array {
        $declared = settings_preview_declarations::groups_of($this->modname);
        $groups = [];
        foreach ($declared as $group) {
            $items = $this->items_of($group);
            if ($items !== []) {
                $name = get_string($group['label'], 'local_coursegen');
                $groups[] = ['label' => $name, 'items' => $items];
            }
        }
        return $groups;
    }

    /**
     * The items of one list, ready to draw.
     *
     * @param array $group One entry of the declarations.
     * @return array[] {title: string, hasbody: bool, body: string} for each item.
     */
    private function items_of(array $group): array {
        $list = $this->list_at($group);
        $items = [];
        foreach ($list as $item) {
            $items[] = $this->item($group, $item);
        }
        return $items;
    }

    /**
     * One item: its title and its text.
     *
     * An item may be the text itself (an option of a choice) or a record with a title and a text.
     *
     * @param array $group One entry of the declarations.
     * @param mixed $item The item as the generator answered it.
     * @return array
     */
    private function item(array $group, $item): array {
        if (!is_array($item)) {
            $title = trim((string) $item);
            return ['title' => $title, 'hasbody' => false, 'body' => ''];
        }
        $title = $this->title_of($item, $group['title']);
        $body = $this->body_of($item, $group['body']);
        return ['title' => $title, 'hasbody' => $body !== '', 'body' => $body];
    }

    /**
     * The title of a record, empty when its type has none or the record holds none.
     *
     * @param array $item
     * @param string $key The key of the title.
     * @return string
     */
    private function title_of(array $item, string $key): string {
        if ($key === '') {
            return '';
        }
        $value = $item[$key] ?? '';
        if (is_array($value)) {
            $value = $value['text'] ?? '';
        }
        $plain = strip_tags((string) $value);
        return trim($plain);
    }

    /**
     * The text of a record, cleaned for display, empty when its type has none or the record holds none.
     *
     * @param array $item
     * @param string $base The base name of the field that holds the text.
     * @return string
     */
    private function body_of(array $item, string $base): string {
        if ($base === '') {
            return '';
        }
        $rawhtml = $this->field($item, $base);
        $html = trim($rawhtml);
        if ($html === '') {
            return '';
        }
        return $this->content($html);
    }

    /**
     * The list a group names, read from the place it lives in.
     *
     * @param array $group One entry of the declarations.
     * @return array The list; empty when the activity holds none there.
     */
    private function list_at(array $group): array {
        $place = $this->parameters;
        if ($group['root'] === settings_preview_declarations::ROOT_SETTINGS) {
            $settings = $this->parameters['mod_settings'] ?? [];
            $place = (array) $settings;
        }
        foreach ($group['path'] as $key) {
            $place = $place[$key] ?? [];
            if (!is_array($place)) {
                return [];
            }
        }
        return array_values($place);
    }
}

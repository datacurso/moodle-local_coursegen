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
 * Raw field access into an activity_preview's answer parameters, kept apart
 * from activity_preview.php only because together they crossed the 250-line
 * cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait activity_preview_fields {
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

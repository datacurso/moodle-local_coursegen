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

use local_coursegen\local\preview\assign\view;

/**
 * An assignment, drawn by mod_assign's own view code run against the payload.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_preview extends preview_base {
    /**
     * The module's short name.
     *
     * @return string
     */
    protected function modname(): string {
        return 'assign';
    }

    /**
     * A draft's description and instructions replace the mould's.
     *
     * The answer writes an assignment's description and, when it has them,
     * the activity instructions; everything else about how it is set up is
     * the mould's.
     *
     * @param json_store $store
     */
    protected function overlay(json_store $store): void {
        $rows = $store->get_records('assign');
        if (!$rows) {
            return;
        }
        $assign = reset($rows);
        foreach (['introeditor' => 'intro', 'activityeditor' => 'activity'] as $field => $column) {
            $value = $this->parameters[$field] ?? null;
            $text = is_array($value) ? (string) ($value['text'] ?? '') : (string) ($value ?? '');
            if (trim($text) !== '') {
                $store->set('assign', $assign->id, $column, $text);
                $store->set('assign', $assign->id, $column . 'format',
                    is_array($value) ? (int) ($value['format'] ?? FORMAT_HTML) : FORMAT_HTML);
            }
        }
    }

    /**
     * The assignment page, as mod/assign/view.php draws it.
     *
     * @return string
     */
    public function render(): string {
        $assign = $this->instance();
        if ($assign === null) {
            return $this->nothing_yet();
        }
        $view = new view($assign, $this->cm(), $this->course(), $this->context(), $this->store(), $this->url_to());
        return $view->page();
    }

    /**
     * The description, then the activity instructions, as assign_header puts them.
     *
     * mod_assign's header puts the description and, under it, the activity
     * instructions in one box; the preview page's activity header shows what
     * this returns.
     *
     * @return string
     */
    public function header_description(): string {
        $instance = $this->instance();
        if ($instance === null) {
            return '';
        }
        global $OUTPUT;
        $showintro = trim((string) ($instance->intro ?? '')) !== '';
        $activity = trim((string) ($instance->activity ?? '')) !== '';
        if (!$showintro && !$activity) {
            return '';
        }
        $description = $OUTPUT->box_start('generalbox boxaligncenter');
        if ($showintro) {
            $description .= $this->module_intro($instance);
        }
        if ($activity) {
            $context = $this->context();
            $text = file_rewrite_pluginfile_urls((string) $instance->activity, 'pluginfile.php', $context->id,
                'mod_assign', 'activity', 0);
            $description .= format_text($text, (int) ($instance->activityformat ?? FORMAT_HTML),
                ['context' => $context]);
        }
        $description .= $OUTPUT->box_end();
        return $description;
    }

    /**
     * This module reads its own page at the narrower width (assign::view()).
     *
     * @return bool
     */
    public function limited_width(): bool {
        return true;
    }
}

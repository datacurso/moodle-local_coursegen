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

use local_coursegen\local\preview\feedback\view;

/**
 * A feedback, drawn by mod_feedback's own view code run against the payload.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class feedback_preview extends ported_preview {
    /**
     * The module's short name.
     *
     * @return string
     */
    protected function modname(): string {
        return 'feedback';
    }

    /**
     * The plan's questions replace the mould's items.
     *
     * @param json_store $store
     */
    protected function overlay(json_store $store): void {
        $rows = $store->get_records('feedback');
        if (!$rows) {
            return;
        }
        $feedback = reset($rows);
        $intro = $this->parameters['introeditor'] ?? null;
        if (is_array($intro)) {
            $intro = $intro['text'] ?? null;
        }
        if (is_string($intro) && trim($intro) !== '') {
            $store->set('feedback', $feedback->id, 'intro', $intro);
        }
        $questions = $this->parameters['mod_settings']['questions'] ?? ($this->parameters['mod_settings']['items'] ?? []);
        if (!is_array($questions) || !$questions) {
            return;
        }
        $store->delete_records('feedback_item', ['feedback' => $feedback->id]);
        $position = 1;
        foreach ($questions as $question) {
            if (!is_array($question) || empty($question['typ'])) {
                continue;
            }
            $typ = (string) $question['typ'];
            // A label and a page break ask nothing; every other item does.
            $hasvalue = 1;
            if (in_array($typ, ['label', 'pagebreak'], true)) {
                $hasvalue = 0;
            }
            $required = 0;
            if (!empty($question['required'])) {
                $required = 1;
            }
            $store->add('feedback_item', [
                'id' => $position,
                'feedback' => $feedback->id,
                'template' => 0,
                'name' => (string) ($question['name'] ?? ''),
                'label' => (string) ($question['label'] ?? ''),
                'presentation' => '',
                'typ' => $typ,
                'hasvalue' => $hasvalue,
                'position' => $position,
                'required' => $required,
                'dependitem' => 0,
                'dependvalue' => '',
                'options' => '',
            ]);
            $position++;
        }
    }

    /**
     * The feedback page, as mod/feedback/view.php draws it.
     *
     * @return string
     */
    public function render(): string {
        $feedback = $this->instance();
        if ($feedback === null) {
            return $this->nothing_yet();
        }
        $course = $this->course();
        $cmid = (int) ($this->source['cmid'] ?? 0);
        $modinfo = get_fast_modinfo($course);
        if (!$cmid || !isset($modinfo->cms[$cmid])) {
            return $this->nothing_yet();
        }
        $view = new view(
            $feedback,
            $modinfo->get_cm($cmid),
            $course,
            $this->context(),
            $this->store(),
            $this->url_to(),
            fn($activity) => $this->module_intro($activity)
        );
        return $view->page();
    }

    /**
     * mod/feedback/view.php shows the description in its own box, not the header.
     *
     * @return string
     */
    public function header_description(): string {
        return '';
    }

    /**
     * This module reads its own page at the narrower width (mod/feedback/view.php).
     *
     * @return bool
     */
    public function limited_width(): bool {
        return true;
    }
}

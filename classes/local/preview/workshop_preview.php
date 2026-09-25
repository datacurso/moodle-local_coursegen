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

use local_coursegen\local\preview\workshop\view;

/**
 * A workshop, drawn by mod_workshop's own view code run against the payload.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class workshop_preview extends ported_preview {
    /** @var int[] The phase an answer may ask the workshop to open in, by its token (workshop_settings). */
    private const PHASE_MAP = [
        'submission' => 20,
        'assessment' => 30,
        'evaluation' => 40,
    ];

    /**
     * The module's short name.
     *
     * @return string
     */
    protected function modname(): string {
        return 'workshop';
    }

    /**
     * What the answer writes replaces the mould's: the texts, the phase and the assessment form.
     *
     * The answer writes the description and the two sets of instructions,
     * may name the phase the workshop opens in, and writes the criteria the
     * plugin creates as accumulative dimensions; the criteria go into the
     * store as the rows the plugin would insert, so the assessment form counts
     * as defined the way it would on the created workshop.
     *
     * @param json_store $store
     */
    protected function overlay(json_store $store): void {
        $rows = $store->get_records('workshop');
        if (!$rows) {
            return;
        }
        $workshop = reset($rows);
        $this->overlay_texts($store, $workshop);

        $settings = (array) ($this->parameters['mod_settings'] ?? []);
        $token = $settings['initial_phase'] ?? null;
        if (is_string($token) && isset(self::PHASE_MAP[$token])) {
            $store->set('workshop', $workshop->id, 'phase', self::PHASE_MAP[$token]);
        }

        $criteria = $settings['criteria'] ?? [];
        if (!is_array($criteria) || !$criteria) {
            return;
        }
        $store->delete_records('workshopform_accumulative', ['workshopid' => $workshop->id]);
        $this->overlay_criteria($store, $workshop, $criteria);
    }

    /**
     * Replace the workshop's own text fields with what the answer wrote.
     *
     * @param json_store $store
     * @param \stdClass $workshop
     */
    private function overlay_texts(json_store $store, \stdClass $workshop): void {
        foreach (['introeditor' => 'intro', 'instructauthors' => 'instructauthors',
                'instructreviewers' => 'instructreviewers'] as $field => $column) {
            $value = $this->parameters[$field] ?? ($this->parameters[$field . 'editor'] ?? null);
            $text = self::editor_field_text($value);
            if (trim($text) !== '') {
                $store->set('workshop', $workshop->id, $column, $text);
                $store->set('workshop', $workshop->id, $column . 'format', self::editor_field_format($value));
            }
        }
    }

    /**
     * Store the answer's assessment criteria as accumulative dimensions.
     *
     * @param json_store $store
     * @param \stdClass $workshop
     * @param array $criteria
     */
    private function overlay_criteria(json_store $store, \stdClass $workshop, array $criteria): void {
        $sort = 1;
        foreach ($criteria as $criterion) {
            $criterion = (array) $criterion;
            $description = trim((string) ($criterion['description'] ?? ''));
            if ($description === '') {
                continue;
            }
            $store->add('workshopform_accumulative', (object) [
                'id' => $sort,
                'workshopid' => $workshop->id,
                'sort' => $sort,
                'description' => $description,
                'descriptionformat' => FORMAT_HTML,
                'grade' => (int) ($criterion['max_points'] ?? 10),
                'weight' => 1,
            ]);
            $sort++;
        }
    }

    /**
     * The workshop page, as mod/workshop/view.php draws it.
     *
     * @return string
     */
    public function render(): string {
        $view = $this->view();
        if ($view === null) {
            return $this->nothing_yet();
        }
        return $view->page();
    }

    /**
     * The heading with its help, as view.php puts it in the activity header.
     *
     * @return string
     */
    public function header_title(): string {
        $view = $this->view();
        if ($view === null) {
            return '';
        }
        return $view->heading();
    }

    /**
     * A workshop shows its description on its own page, not in the header (view.php).
     *
     * @return string
     */
    public function header_description(): string {
        return '';
    }

    /**
     * The ported view, over the payload's rows.
     *
     * @return view|null Null when the payload holds no workshop.
     */
    protected function view(): ?view {
        global $USER;
        $workshop = $this->instance();
        if ($workshop === null) {
            return null;
        }
        return new view($workshop, $this->cm(), $this->course(), $this->context(), $this->store(), $this->url_to(),
            (int) $USER->id);
    }
}

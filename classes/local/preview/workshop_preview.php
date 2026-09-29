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
class workshop_preview extends preview_base {
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
        $this->overlay_text_fields($store, $workshop);

        $rawsettings = $this->parameters['mod_settings'] ?? [];
        $settings = (array) $rawsettings;
        $token = $settings['initial_phase'] ?? null;
        if (is_string($token) && isset(self::PHASE_MAP[$token])) {
            $store->set('workshop', $workshop->id, 'phase', self::PHASE_MAP[$token]);
        }

        $criteria = $settings['criteria'] ?? [];
        if (!is_array($criteria) || !$criteria) {
            return;
        }
        $this->overlay_criteria($store, $workshop, $criteria);
    }

    /**
     * The description and the two sets of instructions the answer writes,
     * replacing the mould's; each may come as an editor array or a plain string.
     *
     * @param json_store $store
     * @param \stdClass $workshop
     */
    protected function overlay_text_fields(json_store $store, \stdClass $workshop): void {
        $fields = ['introeditor' => 'intro', 'instructauthors' => 'instructauthors',
            'instructreviewers' => 'instructreviewers'];
        foreach ($fields as $field => $column) {
            $value = $this->field_value($field);
            $text = $this->field_text($value);
            if (trim($text) === '') {
                continue;
            }
            $store->set('workshop', $workshop->id, $column, $text);
            $store->set('workshop', $workshop->id, $column . 'format', $this->field_format($value));
        }
    }

    /**
     * The answer's own value for one field, from its editor key or its plain key.
     *
     * @param string $field
     * @return mixed
     */
    protected function field_value(string $field) {
        $value = $this->parameters[$field] ?? null;
        if ($value !== null) {
            return $value;
        }
        $editorvalue = $this->parameters[$field . 'editor'] ?? null;
        return $editorvalue;
    }

    /**
     * A field value's text, whichever shape it was written in.
     *
     * @param mixed $value
     * @return string
     */
    protected function field_text($value): string {
        if (!is_array($value)) {
            $rawtext = $value ?? '';
            $text = (string) $rawtext;
            return $text;
        }
        $rawtext = $value['text'] ?? '';
        $text = (string) $rawtext;
        return $text;
    }

    /**
     * A field value's format, whichever shape it was written in.
     *
     * @param mixed $value
     * @return int
     */
    protected function field_format($value): int {
        if (!is_array($value)) {
            return FORMAT_HTML;
        }
        $rawformat = $value['format'] ?? FORMAT_HTML;
        $format = (int) $rawformat;
        return $format;
    }

    /**
     * The accumulative-strategy criteria the answer writes, replacing the mould's.
     *
     * @param json_store $store
     * @param \stdClass $workshop
     * @param array $criteria
     */
    protected function overlay_criteria(json_store $store, \stdClass $workshop, array $criteria): void {
        $store->delete_records('workshopform_accumulative', ['workshopid' => $workshop->id]);
        $sort = 1;
        foreach ($criteria as $criterion) {
            $criterion = (array) $criterion;
            $rawdescription = $criterion['description'] ?? '';
            $description = trim((string) $rawdescription);
            if ($description === '') {
                continue;
            }
            $row = $this->criterion_row($workshop->id, $sort, $description, $criterion);
            $store->add('workshopform_accumulative', $row);
            $sort++;
        }
    }

    /**
     * One accumulative-dimension row, as the plugin would insert it.
     *
     * @param int $workshopid
     * @param int $sort
     * @param string $description
     * @param array $criterion
     * @return \stdClass
     */
    protected function criterion_row(int $workshopid, int $sort, string $description, array $criterion): \stdClass {
        $rawgrade = $criterion['max_points'] ?? 10;
        $grade = (int) $rawgrade;
        return (object) [
            'id' => $sort,
            'workshopid' => $workshopid,
            'sort' => $sort,
            'description' => $description,
            'descriptionformat' => FORMAT_HTML,
            'grade' => $grade,
            'weight' => 1,
        ];
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
        return $view === null ? '' : $view->heading();
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
     * The view, built over the payload's rows.
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

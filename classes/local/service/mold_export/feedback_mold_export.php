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

namespace local_coursegen\local\service\mold_export;

use cm_info;
use context_module;
use stdClass;

/**
 * A mod_feedback mold: settings, the after-submit editor and every question with its presentation decoded.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class feedback_mold_export extends base_mold_export {
    /** @var string[] Settings columns copied verbatim. */
    private const SETTINGS = [
        'anonymous', 'multiple_submit', 'email_notification', 'autonumbering', 'publish_stats', 'timeopen',
        'timeclose', 'site_after_submit', 'completionsubmit',
    ];

    /** @var string[] Item types the service cannot reproduce. */
    private const SKIPPED_TYPES = ['pagebreak', 'captcha'];

    #[\Override]
    protected static function payload(cm_info $cm, stdClass $record): array {
        $context = context_module::instance($cm->id);
        return array_merge(
            static::common($cm, $record),
            static::instance_columns($record, self::SETTINGS),
            [
                'page_after_submit_editor' => static::editor(
                    (string) ($record->page_after_submit ?? ''),
                    (int) ($record->page_after_submitformat ?? FORMAT_HTML),
                    $context,
                    'mod_feedback',
                    'page_after_submit',
                    0
                ),
                'mod_settings' => ['questions' => static::questions($context, (int) $record->id)],
            ]
        );
    }

    /**
     * Every item of the feedback in position order, raw columns plus the decoded per-type fields.
     *
     * @param \context $context
     * @param int $feedbackid
     * @return array
     */
    private static function questions(\context $context, int $feedbackid): array {
        global $DB;

        $items = $DB->get_records('feedback_item', ['feedback' => $feedbackid], 'position ASC, id ASC');
        $questions = [];
        foreach ($items as $item) {
            if (in_array($item->typ, self::SKIPPED_TYPES, true)) {
                continue;
            }
            $question = [
                'typ' => $item->typ,
                'name' => (string) $item->name,
                'label' => (string) $item->label,
                'required' => (int) $item->required,
                'position' => (int) $item->position,
                'dependitem' => (int) $item->dependitem,
                'dependvalue' => (string) $item->dependvalue,
                'options' => (string) $item->options,
                'presentation' => (string) $item->presentation,
            ];
            if ($item->typ === 'label') {
                $question['presentation_editor'] = static::editor(
                    (string) $item->presentation,
                    FORMAT_HTML,
                    $context,
                    'mod_feedback',
                    'item',
                    (int) $item->id
                );
            }
            $questions[] = array_merge(
                $question,
                feedback_presentation_decoder::decode($item->typ, (string) $item->presentation, (string) $item->options)
            );
        }
        return $questions;
    }
}

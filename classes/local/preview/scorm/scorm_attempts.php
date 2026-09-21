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

namespace local_coursegen\local\preview\scorm;

use html_writer;

/**
 * The reader's attempt standing, which the payload never carries, kept apart
 * from view.php only because together they crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait scorm_attempts {
    /**
     * Ported from scorm_get_attempt_status(), returning what it returns.
     *
     * @return string
     */
    protected function scorm_get_attempt_status(): string {
        global $OUTPUT;
        $scorm = $this->scorm;

        $attempts = $this->scorm_get_attempt_count(true);
        if (empty($attempts)) {
            $attemptcount = 0;
        } else {
            $attemptcount = count($attempts);
        }

        $result = html_writer::start_tag('p').get_string('noattemptsallowed', 'scorm').': ';
        if ($scorm->maxattempt > 0) {
            $result .= $scorm->maxattempt . html_writer::empty_tag('br');
        } else {
            $result .= get_string('unlimited').html_writer::empty_tag('br');
        }
        $result .= get_string('noattemptsmade', 'scorm').': ' . $attemptcount . html_writer::empty_tag('br');

        if ($scorm->maxattempt == 1) {
            $grademethod = $this->grade_method_label($this->grademethod_labels(), $scorm->grademethod);
        } else {
            $grademethod = $this->grade_method_label($this->whatgrade_labels(), $scorm->whatgrade);
        }

        // The grade of each attempt would follow here; there are no attempts.
        $calculatedgrade = $this->scorm_grade_user();
        if ($scorm->grademethod !== GRADESCOES && !empty($scorm->maxgrade)) {
            $calculatedgrade = $calculatedgrade / $scorm->maxgrade;
            $calculatedgrade = number_format($calculatedgrade * 100, 0) .'%';
        }
        $result .= get_string('grademethod', 'scorm'). ': ' . ($grademethod ?? '');
        if (empty($attempts)) {
            $result .= html_writer::empty_tag('br').get_string('gradereported', 'scorm').
                        ': '.get_string('none').html_writer::empty_tag('br');
        } else {
            $result .= html_writer::empty_tag('br').get_string('gradereported', 'scorm').
                        ': '.$calculatedgrade.html_writer::empty_tag('br');
        }
        $result .= html_writer::end_tag('p');
        if ($attemptcount >= $scorm->maxattempt && $scorm->maxattempt > 0) {
            $result .= html_writer::tag('p', get_string('exceededmaxattempts', 'scorm'), ['class' => 'exceededmaxattempts']);
        }
        // The button that deletes the reader's attempts is offered only to a
        // reader who has some; none are carried, so it is never offered.
        return $result;
    }

    /**
     * mod/scorm/locallib.php's GRADEHIGHEST..GRADESCOES constants, each
     * mapped to the lang string key that names it - read when the scorm
     * allows one attempt.
     *
     * @return array
     */
    protected function grademethod_labels(): array {
        return [
            GRADEHIGHEST => 'gradehighest',
            GRADEAVERAGE => 'gradeaverage',
            GRADESUM => 'gradesum',
            GRADESCOES => 'gradescoes',
        ];
    }

    /**
     * mod/scorm/locallib.php's HIGHESTATTEMPT..LASTATTEMPT constants,
     * likewise - read when the scorm allows more than one attempt.
     *
     * @return array
     */
    protected function whatgrade_labels(): array {
        return [
            HIGHESTATTEMPT => 'highestattempt',
            AVERAGEATTEMPT => 'averageattempt',
            FIRSTATTEMPT => 'firstattempt',
            LASTATTEMPT => 'lastattempt',
        ];
    }

    /**
     * The language string a grading-method constant names, mapped rather
     * than switched on.
     *
     * @param array $labels grademethod_labels() or whatgrade_labels().
     * @param mixed $value
     * @return string|null Null for a value the map does not describe.
     */
    protected function grade_method_label(array $labels, $value): ?string {
        if (!array_key_exists($value, $labels)) {
            return null;
        }
        return get_string($labels[$value], 'scorm');
    }

    /**
     * scorm_get_attempt_count(): the reader's attempts, which the payload does not carry.
     *
     * @param bool $returnobjects
     * @return array|int
     */
    protected function scorm_get_attempt_count(bool $returnobjects = false) {
        $attempts = $this->store->get_records('scorm_attempt',
            ['userid' => $this->user->id, 'scormid' => $this->scorm->id], 'attempt');
        if ($returnobjects) {
            $found = [];
            foreach ($attempts as $attempt) {
                $found[$attempt->attempt] = (object) ['attemptnumber' => $attempt->attempt];
            }
            return $found;
        }
        return count($attempts);
    }

    /**
     * scorm_grade_user(): the grade over attempts the payload does not carry.
     *
     * @return int
     */
    protected function scorm_grade_user(): int {
        return 0;
    }

    /**
     * scorm_get_last_attempt(): one, as it answers for a reader with no attempts.
     *
     * @return int
     */
    protected function scorm_get_last_attempt(): int {
        $last = 0;
        foreach ($this->store->get_records('scorm_attempt', ['userid' => $this->user->id, 'scormid' => $this->scorm->id]) as $a) {
            $last = max($last, (int) $a->attempt);
        }
        return $last ?: 1;
    }
}

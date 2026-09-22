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

namespace local_coursegen\local\preview\workshop;

use workshop;

/**
 * The workshop class's own small queries, reading the store instead
 * of the database, kept apart from view.php only because together they
 * crossed the 250-line cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait workshop_queries {
    /**
     * workshop_strategy::form_ready(): whether the grading strategy has any dimensions.
     *
     * Every strategy keeps its dimensions in a table named after it, and is
     * ready once it has one; that is what its own form_ready() counts.
     *
     * @return bool
     */
    protected function form_ready(): bool {
        $strategy = clean_param((string) ($this->workshop->strategy ?? ''), PARAM_PLUGIN);
        if ($strategy === '') {
            return false;
        }
        return $this->store->count_records('workshopform_' . $strategy, ['workshopid' => $this->workshop->id]) > 0;
    }

    /**
     * workshop::assessing_examples_allowed().
     *
     * @return bool
     */
    protected function assessing_examples_allowed(): bool {
        if (empty($this->workshop->useexamples)) {
            return false;
        }
        if (workshop::EXAMPLES_VOLUNTARY == $this->workshop->examplesmode) {
            return true;
        }
        if (workshop::EXAMPLES_BEFORE_SUBMISSION == $this->workshop->examplesmode
                && workshop::PHASE_SUBMISSION == $this->workshop->phase) {
            return true;
        }
        if (workshop::EXAMPLES_BEFORE_ASSESSMENT == $this->workshop->examplesmode
                && workshop::PHASE_ASSESSMENT == $this->workshop->phase) {
            return true;
        }
        return false;
    }

    /**
     * workshop::creating_submission_allowed(), for a reader who may ignore deadlines.
     *
     * @param int $userid
     * @return bool
     */
    protected function creating_submission_allowed(int $userid): bool {
        $now = time();
        $ignoredeadlines = has_capability('mod/workshop:ignoredeadlines', $this->context, $userid);

        if ($this->workshop->latesubmissions) {
            if ($this->workshop->phase != workshop::PHASE_SUBMISSION && $this->workshop->phase != workshop::PHASE_ASSESSMENT) {
                // Late submissions are allowed in the submission and assessment phase only.
                return false;
            }
            if (!$ignoredeadlines && !empty($this->workshop->submissionstart) && $this->workshop->submissionstart > $now) {
                // Late submissions are not allowed before the submission start.
                return false;
            }
            return true;

        } else {
            if ($this->workshop->phase != workshop::PHASE_SUBMISSION) {
                // Submissions are allowed during the submission phase only.
                return false;
            }
            if (!$ignoredeadlines && !empty($this->workshop->submissionstart) && $this->workshop->submissionstart > $now) {
                // If enabled, submitting is not allowed before the date/time defined in the mod_form.
                return false;
            }
            if (!$ignoredeadlines && !empty($this->workshop->submissionend) && $now > $this->workshop->submissionend ) {
                // If enabled, submitting is not allowed after the date/time defined in the mod_form
                // unless late submission is allowed.
                return false;
            }
            return true;
        }
    }

    /**
     * workshop::get_submission_by_author(), over the store.
     *
     * @param int $authorid
     * @return \stdClass|false
     */
    protected function get_submission_by_author(int $authorid) {
        return $this->store->get_record('workshop_submissions',
            ['workshopid' => $this->workshop->id, 'example' => 0, 'authorid' => $authorid]);
    }

    /**
     * workshop::get_examples_for_manager(), over the store.
     *
     * @return array
     */
    protected function get_examples_for_manager(): array {
        return $this->store->get_records('workshop_submissions', ['workshopid' => $this->workshop->id, 'example' => 1]);
    }

    /**
     * workshop_user_plan::get_examples(): the reader's example submissions with their grade.
     *
     * @return array
     */
    protected function get_examples(): array {
        $examples = [];
        foreach ($this->get_examples_for_manager() as $example) {
            $assessment = $this->store->get_record('workshop_assessments',
                ['submissionid' => $example->id, 'reviewerid' => $this->userid, 'weight' => 0]);
            $example->grade = $assessment ? ($assessment->grade ?? null) : null;
            $examples[$example->id] = $example;
        }
        return $examples;
    }

    /**
     * workshop::get_assessments_by_reviewer(), over the store.
     *
     * @param int $reviewerid
     * @return array
     */
    protected function get_assessments_by_reviewer(int $reviewerid): array {
        $assessments = [];
        foreach ($this->store->get_records('workshop_assessments', ['reviewerid' => $reviewerid]) as $assessment) {
            $submission = $this->store->get_record('workshop_submissions',
                ['id' => $assessment->submissionid, 'workshopid' => $this->workshop->id, 'example' => 0]);
            if (!$submission) {
                continue;
            }
            $assessment->authorid = $submission->authorid;
            $assessments[$assessment->id] = $assessment;
        }
        return $assessments;
    }

    /**
     * workshop::count_potential_authors(): who could submit, which is who is enrolled.
     *
     * The enrolled are the template course's, not the template's, and the
     * plan counts them only to say how many submissions to expect.
     *
     * @param bool $musthavesubmission
     * @return int
     */
    protected function count_potential_authors(bool $musthavesubmission = true): int {
        return 0;
    }

    /**
     * workshop::count_potential_reviewers(), likewise.
     *
     * @param bool $musthavesubmission
     * @return int
     */
    protected function count_potential_reviewers(bool $musthavesubmission = true): int {
        return 0;
    }
}

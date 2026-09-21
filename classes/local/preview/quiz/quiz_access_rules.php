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

namespace local_coursegen\local\preview\quiz;

/**
 * The quizaccess rules whose messages depend only on the quiz's own row,
 * kept apart from view.php only because together they crossed the 250-line
 * cap.
 *
 * @package    local_coursegen
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait quiz_access_rules {
    /**
     * access_manager::describe_rules(), for the rules a quiz's own row settles.
     *
     * The rules are made in the order core_component lists the quizaccess
     * plugins, and each says its piece. The ones read from the quiz's row:
     * numattempts, password and timelimit. The rest either say nothing
     * (delaybetweenattempts, ipaddress, offlineattempts, openclosedate,
     * securewindow for a previewing reader) or are settled by their own
     * tables (seb), which a payload does not carry.
     *
     * @param bool $canignoretimelimits
     * @return string[]
     */
    protected function describe_rules(bool $canignoretimelimits): array {
        $quiz = $this->quiz;
        $result = [];

        // quizaccess_numattempts.
        if ((int) $quiz->attempts !== 0) {
            $result[] = get_string('attemptsallowedn', 'quizaccess_numattempts', $quiz->attempts);
        }
        // quizaccess_password.
        if (!empty($quiz->password)) {
            $result[] = get_string('requirepasswordmessage', 'quizaccess_password');
        }
        // quizaccess_timelimit.
        if (!empty($quiz->timelimit) && !$canignoretimelimits) {
            $result[] = get_string('quiztimelimit', 'quizaccess_timelimit', format_time($quiz->timelimit));
        }
        return $result;
    }

    /**
     * access_manager::prevent_access(), for the rules a quiz's own row settles.
     *
     * @return string[]
     */
    protected function prevent_access(): array {
        $quiz = $this->quiz;
        $result = [];

        // quizaccess_ipaddress.
        if (!empty($quiz->subnet) && !address_in_subnet(getremoteaddr(), $quiz->subnet)) {
            $result[] = get_string('subnetwrong', 'quizaccess_ipaddress');
        }

        // quizaccess_openclosedate.
        $message = get_string('notavailable', 'quizaccess_openclosedate');
        if ($this->timenow < $quiz->timeopen) {
            $result[] = $message;
        } else if (!empty($quiz->timeclose) && $this->timenow > $quiz->timeclose) {
            if (($quiz->overduehandling ?? '') !== 'graceperiod'
                    || $this->timenow > $quiz->timeclose + (int) ($quiz->graceperiod ?? 0)) {
                $result[] = $message;
            }
        }
        return $result;
    }

    /**
     * access_manager::is_finished(), for the rules a quiz's own row settles.
     *
     * @param int $numprevattempts
     * @param mixed $lastattempt
     * @return bool
     */
    protected function is_finished(int $numprevattempts, $lastattempt): bool {
        $quiz = $this->quiz;
        // quizaccess_numattempts.
        if ((int) $quiz->attempts !== 0 && $numprevattempts >= $quiz->attempts) {
            return true;
        }
        // quizaccess_openclosedate.
        if (!empty($quiz->timeclose) && $this->timenow > $quiz->timeclose) {
            return true;
        }
        return false;
    }

    /**
     * access_manager::attempt_must_be_in_popup().
     *
     * @return bool
     */
    protected function attempt_must_be_in_popup(): bool {
        return ($this->quiz->browsersecurity ?? '') === 'securewindow';
    }

    /**
     * mod/quiz/locallib.php quiz_get_grading_option_name().
     *
     * @param mixed $option
     * @return string
     */
    protected function quiz_get_grading_option_name($option): string {
        $strings = [
            self::QUIZ_GRADEHIGHEST => get_string('gradehighest', 'quiz'),
            self::QUIZ_GRADEAVERAGE => get_string('gradeaverage', 'quiz'),
            self::QUIZ_ATTEMPTFIRST => get_string('attemptfirst', 'quiz'),
            self::QUIZ_ATTEMPTLAST  => get_string('attemptlast', 'quiz'),
        ];
        return $strings[(string) $option] ?? '';
    }

    /**
     * mod/quiz/lib.php quiz_get_grade_format().
     *
     * @return int
     */
    protected function quiz_get_grade_format(): int {
        $quiz = $this->quiz;
        if (empty($quiz->questiondecimalpoints)) {
            $quiz->questiondecimalpoints = -1;
        }
        if ((int) $quiz->questiondecimalpoints === -1) {
            return (int) $quiz->decimalpoints;
        }
        return (int) $quiz->questiondecimalpoints;
    }
}

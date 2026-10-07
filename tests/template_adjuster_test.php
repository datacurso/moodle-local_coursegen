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

namespace local_coursegen\local\service;

/**
 * The change request of a teacher to a completed template run.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\service\template_adjuster
 */
final class template_adjuster_test extends \advanced_testcase {
    /**
     * An adjuster whose service records what it receives.
     *
     * @return array The adjuster and the mock of the service.
     */
    private function adjuster(): array {
        $api = $this->createMock(template_ai_api_service::class);
        $api->method('adjust')->willReturn(['round' => 1, 'aids' => []]);
        return [new template_adjuster($api), $api];
    }

    /**
     * A request for the whole result is sent trimmed and without an activity.
     */
    public function test_a_request_for_the_whole_result_is_sent_trimmed(): void {
        [$adjuster, $api] = $this->adjuster();
        $api->expects($this->once())->method('adjust')->with('t-1', 'adj1', 'Make it shorter', '');

        $adjuster->adjust('t-1', 'adj1', "  Make it shorter \n", '');
    }

    /**
     * A request from the row of an activity names that activity.
     */
    public function test_a_request_from_a_row_names_the_activity(): void {
        [$adjuster, $api] = $this->adjuster();
        $api->expects($this->once())->method('adjust')->with('t-1', 'adj2', 'More examples', 't:11342');

        $adjuster->adjust('t-1', 'adj2', 'More examples', 't:11342');
    }

    /**
     * A blank request never reaches the service.
     */
    public function test_a_blank_request_is_refused(): void {
        [$adjuster, $api] = $this->adjuster();
        $api->expects($this->never())->method('adjust');

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('templateadjustblank', 'local_coursegen'));
        $adjuster->adjust('t-1', 'adj3', "  \n ", '');
    }

    /**
     * A request over the limit never reaches the service.
     */
    public function test_a_request_that_is_too_long_is_refused(): void {
        [$adjuster, $api] = $this->adjuster();
        $api->expects($this->never())->method('adjust');

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('templateadjusttoolong', 'local_coursegen', template_adjuster::MAX_CHARS));
        $adjuster->adjust('t-1', 'adj4', str_repeat('x', template_adjuster::MAX_CHARS + 1), '');
    }

    /**
     * An activity id that is not a draft id of the template is refused.
     */
    public function test_an_unknown_kind_of_activity_id_is_refused(): void {
        [$adjuster, $api] = $this->adjuster();
        $api->expects($this->never())->method('adjust');

        $this->expectException(\moodle_exception::class);
        $adjuster->adjust('t-1', 'adj5', 'Change it', 'page 7');
    }

    /**
     * The refusals of the service are told in words the teacher can act on.
     *
     * @dataProvider refusals_provider
     * @param int $httpcode What the service answered.
     * @param string $stringid Language string that explains it.
     */
    public function test_a_refusal_of_the_service_is_explained(int $httpcode, string $stringid): void {
        $api = $this->createMock(template_ai_api_service::class);
        $api->method('adjust')->willThrowException(
            new \moodle_exception('httperror', 'aiprovider_datacurso', '', $httpcode)
        );

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string($stringid, 'local_coursegen'));
        (new template_adjuster($api))->adjust('t-1', 'adj6', 'Change it', '');
    }

    /**
     * What the service answers and what the teacher is told.
     *
     * @return array
     */
    public static function refusals_provider(): array {
        return [
            'unknown run' => [404, 'templateadjustgone'],
            'run not completed or rounds used' => [409, 'templateadjustbusy'],
            'nothing to change there' => [422, 'templateadjustrefused'],
        ];
    }

    /**
     * An error that is not a refusal is not hidden.
     */
    public function test_any_other_error_goes_up_as_it_came(): void {
        $api = $this->createMock(template_ai_api_service::class);
        $api->method('adjust')->willThrowException(
            new \moodle_exception('httperror', 'aiprovider_datacurso', '', 500)
        );

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('httperror', 'aiprovider_datacurso', 500));
        (new template_adjuster($api))->adjust('t-1', 'adj7', 'Change it', '');
    }
}

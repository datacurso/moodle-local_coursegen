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

namespace local_coursegen;

use core\context\course;
use core\context\system;
use core\exception\required_capability_exception;
use local_coursegen\local\access;

/**
 * Tests for the shared permission gates of the AI generation endpoints.
 *
 * @package    local_coursegen
 * @category   test
 * @copyright  2026 Wilber Narvaez <https://datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_coursegen\local\access
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursegen\local\access::class)]
final class access_test extends \advanced_testcase {
    /**
     * Run a gate and return the localized name of the capability it rejected, or null when it passed.
     *
     * @param callable $gate The gate call.
     * @return string|null
     */
    private function rejected_capability(callable $gate): ?string {
        try {
            $gate();
            return null;
        } catch (required_capability_exception $e) {
            return (string) $e->a;
        }
    }

    /**
     * Create a user holding exactly the given capabilities in the given context.
     *
     * @param \core\context $context Context of the role assignment.
     * @param string[] $capabilities Capabilities allowed by the role.
     * @return \stdClass The user.
     */
    private function user_with(\core\context $context, array $capabilities): \stdClass {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $roleid = $generator->create_role();
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $context, true);
        }
        role_assign($roleid, $user->id, $context);

        return $user;
    }

    /**
     * The course creation gate passes with both capabilities and rejects the first missing one.
     *
     * @dataProvider course_creation_provider
     * @param string[] $granted Capabilities granted at system level.
     * @param string|null $expected Capability expected to be rejected, null when the gate must pass.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('course_creation_provider')]
    public function test_require_course_creation(array $granted, ?string $expected): void {
        $this->resetAfterTest();

        $context = system::instance();
        $this->setUser($this->user_with($context, $granted));

        $rejected = $this->rejected_capability(static function () use ($context): void {
            access::require_course_creation($context);
        });

        $this->assertSame($expected === null ? null : get_capability_string($expected), $rejected);
    }

    /**
     * Capability combinations for the course creation gate.
     *
     * @return array<string, array{0: string[], 1: string|null}>
     */
    public static function course_creation_provider(): array {
        return [
            'both' => [['moodle/course:create', 'local/coursegen:createcoursewithai'], null],
            'none' => [[], 'moodle/course:create'],
            'only core' => [['moodle/course:create'], 'local/coursegen:createcoursewithai'],
            'only plugin' => [['local/coursegen:createcoursewithai'], 'moodle/course:create'],
        ];
    }

    /**
     * The activity creation gate passes with both capabilities and rejects the first missing one.
     *
     * @dataProvider activity_creation_provider
     * @param string[] $granted Capabilities granted in the course.
     * @param string|null $expected Capability expected to be rejected, null when the gate must pass.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('activity_creation_provider')]
    public function test_require_activity_creation(array $granted, ?string $expected): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $context = course::instance($course->id);
        $this->setUser($this->user_with($context, $granted));

        $rejected = $this->rejected_capability(static function () use ($context): void {
            access::require_activity_creation($context);
        });

        $this->assertSame($expected === null ? null : get_capability_string($expected), $rejected);
    }

    /**
     * Capability combinations for the activity creation gate.
     *
     * @return array<string, array{0: string[], 1: string|null}>
     */
    public static function activity_creation_provider(): array {
        return [
            'both' => [['moodle/course:manageactivities', 'local/coursegen:createactivitywithai'], null],
            'none' => [[], 'moodle/course:manageactivities'],
            'only core' => [['moodle/course:manageactivities'], 'local/coursegen:createactivitywithai'],
            'only plugin' => [['local/coursegen:createactivitywithai'], 'moodle/course:manageactivities'],
        ];
    }

    /**
     * The activity gate is checked in the given course: an editing teacher of another course is rejected.
     */
    public function test_require_activity_creation_is_course_bound(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $teacher = $generator->create_user();
        $owncourse = $generator->create_course();
        $othercourse = $generator->create_course();
        $generator->enrol_user($teacher->id, $owncourse->id, 'editingteacher');
        $this->setUser($teacher);

        access::require_activity_creation(course::instance($owncourse->id));

        $rejected = $this->rejected_capability(static function () use ($othercourse): void {
            access::require_activity_creation(course::instance($othercourse->id));
        });
        $this->assertSame(get_capability_string('moodle/course:manageactivities'), $rejected);
    }

    /**
     * The gates check the documented capability pairs, in order.
     */
    public function test_capability_lists_are_documented(): void {
        $this->assertSame(['moodle/course:create', 'local/coursegen:createcoursewithai'], access::COURSE_CREATION);
        $this->assertSame(
            ['moodle/course:manageactivities', 'local/coursegen:createactivitywithai'],
            access::ACTIVITY_CREATION
        );
    }
}

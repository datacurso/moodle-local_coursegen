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

namespace local_coursegen\local;

/**
 * Value object with the outcome of a URL content currency check.
 *
 * @package    local_coursegen
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class url_validation_result {

    /** @var bool Whether the URL content is considered current and usable. */
    private bool $valid;

    /** @var string Language string key describing why the URL was rejected ('' when valid). */
    private string $reason;

    /** @var array|null Params for the reason language string. */
    private ?array $reasonparams;

    /** @var array Details of every check performed. */
    private array $details;

    /**
     * Constructor.
     *
     * @param bool $valid Whether the content is considered current and usable.
     * @param string $reason Language string key describing the rejection ('' when valid).
     * @param array|null $reasonparams Params for the reason language string.
     * @param array $details Details of every check performed.
     */
    public function __construct(bool $valid, string $reason, ?array $reasonparams = null, array $details = []) {
        $this->valid = $valid;
        $this->reason = $reason;
        $this->reasonparams = $reasonparams;
        $this->details = $details;
    }

    /**
     * Whether the URL content is considered current and usable.
     *
     * @return bool
     */
    public function is_valid(): bool {
        return $this->valid;
    }

    /**
     * Language string key describing why the URL was rejected.
     *
     * @return string
     */
    public function get_reason(): string {
        return $this->reason;
    }

    /**
     * Params for the reason language string.
     *
     * @return array|null
     */
    public function get_reason_params(): ?array {
        return $this->reasonparams;
    }

    /**
     * Details of every check performed.
     *
     * @return array
     */
    public function get_details(): array {
        return $this->details;
    }
}
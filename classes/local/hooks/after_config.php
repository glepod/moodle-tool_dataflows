<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace tool_dataflows\local\hooks;

/**
 * After config hook
 *
 * @package     tool_dataflows
 * @copyright   2024 Catalyst IT Australia
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class after_config {

    /**
     * Hook to be run after initial site config.
     *
     * Triggered as soon as practical on every moodle bootstrap after config has
     * been loaded. The $USER object is available at this point too.
     *
     * This currently ensures all vendor libraries are loaded.
     *
     * @param \core\hook\after_config $hook
     * return void
     */
    public static function callback(\core\hook\after_config $hook): void {
        global $CFG;

        require_once ($CFG->dirroot. '/admin/tool/dataflows/lib.php');
    }
}

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

/**
 * Version metadata for the Grade Lookup report.
 *
 * @package    report_gradelookup
 * @copyright  2026 Sternfast LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'report_gradelookup';   // Full frankenstyle name.
$plugin->version   = 2026090300;               // YYYYMMDDXX.
$plugin->requires  = 2023100900;               // Moodle 4.3 LTS baseline (core AJAX user selector + core_user\fields).
$plugin->supported = [403, 500];               // Tested on 4.3 LTS line through 5.0.
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.4.1';

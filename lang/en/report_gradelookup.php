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
 * English strings for the Grade Lookup report.
 *
 * @package    report_gradelookup
 * @copyright  2026 Sternfast LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Grade Lookup';
$string['gradelookup:view'] = 'View any learner\'s cross-course grade record and certificates';
$string['privacy:metadata'] = 'The Grade Lookup report only displays existing data (grades, grade history and certificates). It does not store any personal data of its own.';

// Unified search.
$string['searchbutton'] = 'Search';
$string['filterlearner'] = 'Find learners';
$string['filtercourse'] = 'Filter by course';
$string['filtercohort'] = 'Filter by cohort';
$string['anylearner'] = 'Any learner';
$string['anycourse'] = 'Any course';
$string['anycohort'] = 'Any cohort';
$string['hiddenlabel'] = 'hidden';
$string['daterange'] = 'Active-time window (optional)';
$string['datefrom'] = 'From';
$string['dateto'] = 'To';

// Active-filter chips.
$string['chip_learner'] = 'Learner: {$a}';
$string['chip_course'] = 'Course: {$a}';
$string['chip_cohort'] = 'Cohort: {$a}';
$string['chip_dates'] = 'Active {$a->from} to {$a->to}';
$string['removefilter'] = 'Remove filter';
$string['clearfilters'] = 'Clear all filters';

// Aggregate summary (stat tiles).
$string['stat_learners'] = 'Learners';
$string['stat_avggrade'] = 'Average grade';
$string['stat_grade'] = 'Grade';
$string['stat_grademeta'] = 'median {$a->median}% · {$a->min}–{$a->max}%';
$string['stat_grademeta_att'] = 'median {$a->median}% · {$a->n} of {$a->total} attempted';
$string['stat_median'] = 'Median grade %';
$string['stat_graderange'] = 'Grade range';
$string['stat_attempted'] = 'Learners attempted (grade > 0)';
$string['stat_courses'] = 'Courses';
$string['stat_completed'] = 'Completed';
$string['stat_activetime'] = 'Active time';
$string['summaryincourse'] = 'In: {$a}';
$string['stat_avgtime'] = 'Avg. active time';
$string['stat_totaltime'] = 'total {$a}';
$string['stat_totaltime_x'] = 'Total active time';
$string['stat_timecapped'] = 'set too large';
$string['stat_completion'] = 'Completion';
$string['stat_certs'] = 'Certificates';
$string['col_activetime'] = 'Active time';
$string['backtoresults'] = 'Back to results';
$string['downloadexcel'] = 'Download (Excel)';

// Excel export.
$string['sheet_summary'] = 'Summary';
$string['sheet_learners'] = 'Learners';
$string['generatedon'] = 'Generated';
$string['metric'] = 'Metric';
$string['value'] = 'Value';

// Results.
$string['usersfound'] = '{$a} learner(s) found';
$string['nomatches'] = 'No learners matched. Try a different name, or adjust the course and cohort filters.';
$string['listcapped'] = 'Showing the first {$a->shown} of {$a->total}. Narrow your search to see the rest.';
$string['viewrecord'] = 'View record';

// Empty state.
$string['emptytitle'] = 'Find learners';
$string['emptystate'] = 'Pick one or more learners, courses or cohorts to begin — combine them to narrow the results, and add an optional active-between date range. Records appear even for learners who have been unenrolled.';

// Learner record.
$string['recordfor'] = 'Learner record: {$a}';
$string['viewprofile'] = 'View profile';
$string['grades'] = 'Grades — all courses';
$string['certificates'] = 'Certificates';
$string['nogrades'] = 'No grades found for this learner in any course.';
$string['nocertificates'] = 'No certificates have been issued to this learner.';
$string['customcertnotinstalled'] = 'The certificate module (mod_customcert) is not installed, so certificates are not shown.';

// Grade detail (single course).
$string['gradedetailfor'] = 'Grade detail: {$a->user} — {$a->course}';
$string['gradedetailhelp'] = 'Every graded item in this course. Where a learner is unenrolled and the live grade was purged, the item is recovered from grade history.';
$string['gradedetailtitle'] = 'Open the grade breakdown for this course';
$string['backtorecord'] = 'Back to record';
$string['viewingradebook'] = 'View in Moodle gradebook';
$string['nogradeitems'] = 'No graded items found for this learner in this course.';
$string['unnameditem'] = 'Grade item';

// Columns.
$string['col_course'] = 'Course';
$string['col_grade'] = 'Grade';
$string['col_grade_help'] = 'The course-total grade, taken from the live gradebook where available and recovered from grade history otherwise (which survives unenrolment). Click a grade to see the full item-by-item breakdown for that course.';
$string['col_percent'] = 'Percent';
$string['col_completed'] = 'Completed';
$string['col_certificate'] = 'Certificate';
$string['col_issued'] = 'Issued';
$string['col_verify'] = 'Verify';
$string['col_gradedcourses'] = 'Courses with grades';
$string['col_item'] = 'Grade item';
$string['col_range'] = 'Range';
$string['col_updated'] = 'Last updated';
$string['notcompleted'] = '—';
$string['verifylink'] = 'Verify';

// Export.
$string['downloadcsv'] = 'Download (CSV)';
$string['exportfilename'] = 'grade-lookup';

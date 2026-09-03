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
 * Grade Lookup report — one unified search (multi-select learners, courses and
 * cohorts, plus an optional active-between date range) over learners; open a learner
 * to see their cross-course grades and certificates, and drill into a course's grade
 * breakdown. Read-only; reconstructs grades from history so records survive unenrolment.
 *
 * @package    report_gradelookup
 * @copyright  2026 Sternfast LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');

use report_gradelookup\local\collector;
use report_gradelookup\form\search as search_form;

$userid   = optional_param('userid', 0, PARAM_INT);    // Single learner (record view).
$courseid = optional_param('courseid', 0, PARAM_INT);  // Single course (grade-detail view).
$format   = optional_param('format', '', PARAM_ALPHA);
$download = optional_param('download', '', PARAM_ALPHA);

$userids   = array_values(array_filter(optional_param_array('userids', [], PARAM_INT)));
$courseids = array_values(array_filter(optional_param_array('courseids', [], PARAM_INT)));
$cohortids = array_values(array_filter(optional_param_array('cohortids', [], PARAM_INT)));
$datefromiso = optional_param('datefrom', '', PARAM_RAW_TRIMMED);
$datetoiso   = optional_param('dateto', '', PARAM_RAW_TRIMMED);

admin_externalpage_setup('reportgradelookup');
$context = context_system::instance();
require_capability('report/gradelookup:view', $context);
$cancohort    = has_capability('moodle/cohort:view', $context);
$canpickusers = has_capability('moodle/user:viewalldetails', $context);
if (!$cancohort) {
    $cohortids = [];
}

$baseurl = new moodle_url('/report/gradelookup/index.php');

// Build a report URL, flattening array params (e.g. courseids => [16, 17]) into
// scalar name[i] keys — moodle_url rejects array values, but PHP + optional_param_array
// still read name[0]=…&name[1]=… back into an array.
$mkurl = function(array $params) use ($baseurl): moodle_url {
    $flat = [];
    foreach ($params as $k => $v) {
        if (is_array($v)) {
            foreach (array_values($v) as $i => $item) {
                $flat[$k . '[' . $i . ']'] = $item;
            }
        } else {
            $flat[$k] = $v;
        }
    }
    return new moodle_url($baseurl, $flat);
};

// Parse the ISO date bounds to timestamps (from = start of day, to = end of day).
$parsedate = function(string $iso, bool $endofday): int {
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return $endofday
            ? make_timestamp((int)$m[1], (int)$m[2], (int)$m[3], 23, 59, 59, 99, true)
            : make_timestamp((int)$m[1], (int)$m[2], (int)$m[3], 0, 0, 0, 99, true);
    }
    return 0;
};
$from = $parsedate($datefromiso, false);
$to   = $parsedate($datetoiso, true);
if (!$from) {
    $datefromiso = '';
}
if (!$to) {
    $datetoiso = '';
}

// Whether any facet is active (drives the results vs empty-state branch).
$hasfilter = $userids || $courseids || $cohortids;
$singlecourse = count($courseids) === 1 ? (int)reset($courseids) : 0;

// URL params that carry the current filter (for record context, back links, export).
$filterparams = [];
if ($userids) {
    $filterparams['userids'] = $userids;
}
if ($courseids) {
    $filterparams['courseids'] = $courseids;
}
if ($cohortids) {
    $filterparams['cohortids'] = $cohortids;
}
if ($datefromiso !== '') {
    $filterparams['datefrom'] = $datefromiso;
}
if ($datetoiso !== '') {
    $filterparams['dateto'] = $datetoiso;
}

/**
 * Seconds rendered compactly, e.g. "2h 05m" or "47m" (or a dash when zero/unknown).
 *
 * @param int|float|null $seconds
 * @return string
 */
$fmttime = function($seconds): string {
    $seconds = (int)$seconds;
    if ($seconds <= 0) {
        return '—';
    }
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    return $h > 0 ? $h . 'h ' . sprintf('%02d', $m) . 'm' : $m . 'm';
};

/**
 * Percent value as plain text (or an em dash when unknown). Deliberately NOT
 * colour-coded: a pass/fail colour would assume a grade threshold that varies by
 * institution and course, which this report cannot know.
 *
 * @param int|null $percent
 * @return string
 */
$percentbadge = function(?int $percent): string {
    return $percent === null ? '—' : $percent . '%';
};

// ---------------------------------------------------------------------------
// CSV export of one learner's full record (read-only, capability-gated above).
// ---------------------------------------------------------------------------
if ($userid && !$courseid && $format === 'csv') {
    $user = \core_user::get_user($userid, '*', MUST_EXIST);
    $grades = collector::get_grades($userid);
    $certs  = collector::get_certificates($userid);

    $filename = clean_filename(get_string('exportfilename', 'report_gradelookup') . '-' . $userid) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');

    // Neutralise spreadsheet formula injection: a value beginning =,+,-,@,TAB,CR is
    // prefixed with a single quote so a spreadsheet treats it as text, not a formula.
    $csvcell = function($v): string {
        $v = (string)$v;
        return ($v !== '' && preg_match('/^[=+\-@\t\r]/', $v)) ? "'" . $v : $v;
    };

    fputcsv($out, [$csvcell(fullname($user)), $csvcell($user->email)]);
    fputcsv($out, []);
    fputcsv($out, [get_string('grades', 'report_gradelookup')]);
    fputcsv($out, [
        get_string('col_course', 'report_gradelookup'),
        get_string('col_grade', 'report_gradelookup'),
        get_string('col_percent', 'report_gradelookup'),
        get_string('col_completed', 'report_gradelookup'),
    ]);
    foreach ($grades as $g) {
        fputcsv($out, [
            $csvcell($g->coursename),
            format_float($g->grade, 2) . ' / ' . format_float($g->grademax, 2),
            $g->percent === null ? '' : $g->percent . '%',
            $g->completed ? userdate($g->completed, get_string('strftimedate', 'langconfig')) : '',
        ]);
    }
    fputcsv($out, []);
    fputcsv($out, [get_string('certificates', 'report_gradelookup')]);
    fputcsv($out, [
        get_string('col_certificate', 'report_gradelookup'),
        get_string('col_course', 'report_gradelookup'),
        get_string('col_issued', 'report_gradelookup'),
        get_string('col_verify', 'report_gradelookup'),
    ]);
    foreach ($certs as $c) {
        $verify = !empty($c->verifyany)
            ? (new moodle_url('/mod/customcert/verify_certificate.php', ['code' => $c->code]))->out(false)
            : $c->code;
        fputcsv($out, [$csvcell($c->certname), $csvcell($c->coursename),
            userdate($c->timecreated, get_string('strftimedate', 'langconfig')), $csvcell($verify)]);
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------------------
// Excel export of the current result set: sheet 1 = aggregate, sheet 2 = raw list.
// Runs before any page output; MoodleExcelWorkbook::close() sends its own headers.
// ---------------------------------------------------------------------------
if ($download === 'xlsx' && $hasfilter) {
    require_once($CFG->libdir . '/excellib.class.php');

    $agg  = collector::aggregate($userids, $courseids, $cohortids, $from, $to);
    $list = collector::search_learners($userids, $courseids, $cohortids, collector::AGG_CAP);
    $timemap = $agg ? $agg->timemap : [];

    $tag = $singlecourse ? get_course($singlecourse)->shortname : 'learners';
    $filename = clean_filename(get_string('exportfilename', 'report_gradelookup') . '-' . $tag);

    $workbook = new MoodleExcelWorkbook($filename);
    $bold = $workbook->add_format(['bold' => 1]);

    // --- Sheet 1: Summary ---
    $s = $workbook->add_worksheet(get_string('sheet_summary', 'report_gradelookup'));
    $s->set_column(0, 0, 34);
    $s->set_column(1, 1, 24);
    $r = 0;
    $s->write_string($r++, 0, get_string('pluginname', 'report_gradelookup'), $bold);
    if ($courseids) {
        $names = [];
        foreach ($courseids as $cid) {
            $names[] = format_string(get_course($cid)->fullname);
        }
        $s->write_string($r, 0, get_string('filtercourse', 'report_gradelookup'));
        $s->write_string($r++, 1, implode('; ', $names));
    }
    if ($cohortids) {
        $names = [];
        foreach ($cohortids as $chid) {
            $names[] = format_string((string)$DB->get_field('cohort', 'name', ['id' => $chid]));
        }
        $s->write_string($r, 0, get_string('filtercohort', 'report_gradelookup'));
        $s->write_string($r++, 1, implode('; ', $names));
    }
    if ($datefromiso !== '' || $datetoiso !== '') {
        $s->write_string($r, 0, get_string('daterange', 'report_gradelookup'));
        $s->write_string($r++, 1, ($datefromiso ?: '…') . ' – ' . ($datetoiso ?: '…'));
    }
    $s->write_string($r, 0, get_string('generatedon', 'report_gradelookup'));
    $s->write_string($r++, 1, userdate(time()));
    $r++;
    $s->write_string($r, 0, get_string('metric', 'report_gradelookup'), $bold);
    $s->write_string($r++, 1, get_string('value', 'report_gradelookup'), $bold);
    if ($agg) {
        $rows = [
            [get_string('stat_learners', 'report_gradelookup'), $agg->count, true],
            [get_string('stat_attempted', 'report_gradelookup'), $agg->grade->n, true],
            [get_string('stat_avggrade', 'report_gradelookup'),
                $agg->grade->avg === null ? '' : round($agg->grade->avg, 1), false],
            [get_string('stat_median', 'report_gradelookup'), $agg->grade->median ?? '', false],
            [get_string('stat_graderange', 'report_gradelookup'),
                $agg->grade->n ? $agg->grade->min . '–' . $agg->grade->max . '%' : '', false],
        ];
        if ($singlecourse) {
            $rate = $agg->completion->total
                ? round($agg->completion->completed / $agg->completion->total * 100) : 0;
            $rows[] = [get_string('stat_completion', 'report_gradelookup'),
                $rate . '% (' . $agg->completion->completed . '/' . $agg->completion->total . ')', false];
        }
        $rows[] = [get_string('stat_certs', 'report_gradelookup'), $agg->certs, true];
        $rows[] = [get_string('stat_totaltime_x', 'report_gradelookup'),
            $agg->timecapped ? '' : $fmttime($agg->timetotal), false];
        $rows[] = [get_string('stat_avgtime', 'report_gradelookup'),
            $agg->timecapped ? '' : $fmttime($agg->timeavg), false];
        foreach ($rows as $row) {
            $s->write_string($r, 0, $row[0]);
            if ($row[2] && $row[1] !== '') {
                $s->write_number($r, 1, $row[1]);
            } else {
                $s->write_string($r, 1, (string)$row[1]);
            }
            $r++;
        }
    }

    // --- Sheet 2: Learners (raw list) ---
    $l = $workbook->add_worksheet(get_string('sheet_learners', 'report_gradelookup'));
    $headers = [get_string('firstname'), get_string('lastname'), get_string('email')];
    if ($singlecourse) {
        $headers[] = get_string('col_grade', 'report_gradelookup');
        $headers[] = get_string('col_percent', 'report_gradelookup');
        $headers[] = get_string('col_completed', 'report_gradelookup');
    } else {
        $headers[] = get_string('col_gradedcourses', 'report_gradelookup');
    }
    $headers[] = get_string('col_activetime', 'report_gradelookup');
    foreach ($headers as $c => $h) {
        $l->write_string(0, $c, $h, $bold);
    }
    $l->set_column(0, count($headers) - 1, 18);
    $rownum = 1;
    foreach ($list as $u) {
        $c = 0;
        $l->write_string($rownum, $c++, $u->firstname);
        $l->write_string($rownum, $c++, $u->lastname);
        $l->write_string($rownum, $c++, $u->email);
        if ($singlecourse) {
            if ($u->grade !== null) {
                $l->write_number($rownum, $c++, round($u->grade, 2));
            } else {
                $l->write_string($rownum, $c++, '');
            }
            if ($u->percent !== null) {
                $l->write_number($rownum, $c++, $u->percent);
            } else {
                $l->write_string($rownum, $c++, '');
            }
            $l->write_string($rownum, $c++,
                $u->completed ? userdate($u->completed, get_string('strftimedate', 'langconfig')) : '');
        } else {
            $l->write_number($rownum, $c++, (int)$u->gradedcourses);
        }
        $secs = $timemap[$u->id] ?? 0;
        $l->write_string($rownum, $c++, $secs ? $fmttime($secs) : '');
        $rownum++;
    }

    $workbook->close();
    die();
}

// ---------------------------------------------------------------------------
// HTML page.
// ---------------------------------------------------------------------------
echo $OUTPUT->header();

// One unified search form.
$form = new search_form($baseurl->out(false), [
    'cancohort'    => $cancohort,
    'canpickusers' => $canpickusers,
    'datefrom'     => $datefromiso,
    'dateto'       => $datetoiso,
    // Something is selected (a filter, a drilled-into record/course, or a date) — drives
    // whether the form shows a Clear button.
    'hasselection' => $hasfilter || $userid || $courseid || $datefromiso !== '' || $datetoiso !== '',
], 'get');
// When drilled into a single learner's record (or a course grade breakdown), reflect
// that selection in the search form, so it's clear whose record is shown rather than
// leaving the learner/course pickers looking empty.
$formuserids   = $userids   ?: ($userid   ? [$userid]   : []);
$formcourseids = $courseids ?: ($courseid ? [$courseid] : []);
$form->set_data(['userids' => $formuserids, 'courseids' => $formcourseids, 'cohortids' => $cohortids]);
$form->display();

/**
 * A single stat tile (big value + label + optional sub-line).
 */
$stattile = function($value, $label, $sub = '') {
    $h = html_writer::div(s($value), 'llr-stat-value');
    $h .= html_writer::div(s($label), 'llr-stat-label');
    if ($sub !== '') {
        $h .= html_writer::div(s($sub), 'llr-stat-sub');
    }
    echo html_writer::div($h, 'llr-stat');
};

/**
 * Grade sub-line: median, plus how many attempted (when some scored 0) or the range.
 */
$gradesub = function(\stdClass $grade) {
    if (!$grade->n) {
        return '';
    }
    if (!empty($grade->excluded)) {
        return get_string('stat_grademeta_att', 'report_gradelookup',
            (object)['median' => $grade->median, 'n' => $grade->n, 'total' => $grade->total]);
    }
    return get_string('stat_grademeta', 'report_gradelookup',
        (object)['median' => $grade->median, 'min' => $grade->min, 'max' => $grade->max]);
};

/**
 * Render the compact aggregate summary (over a filtered set) as a row of stat tiles.
 */
$rendertiles = function(?\stdClass $agg, int $activecourse) use ($fmttime, $stattile, $gradesub) {
    if (!$agg) {
        return;
    }
    echo html_writer::start_div('report-gradelookup-stats');
    $stattile($agg->count . ($agg->sampled ? '+' : ''), get_string('stat_learners', 'report_gradelookup'));
    if ($agg->grade->n) {
        $stattile(round($agg->grade->avg) . '%', get_string('stat_avggrade', 'report_gradelookup'),
            $gradesub($agg->grade));
    } else {
        $stattile('—', get_string('stat_avggrade', 'report_gradelookup'));
    }
    if ($agg->timecapped) {
        $stattile('—', get_string('stat_avgtime', 'report_gradelookup'),
            get_string('stat_timecapped', 'report_gradelookup'));
    } else {
        $stattile($fmttime($agg->timeavg), get_string('stat_avgtime', 'report_gradelookup'),
            get_string('stat_totaltime', 'report_gradelookup', $fmttime($agg->timetotal)));
    }
    if ($activecourse) {
        $rate = $agg->completion->total
            ? round($agg->completion->completed / $agg->completion->total * 100) : 0;
        $stattile($rate . '%', get_string('stat_completion', 'report_gradelookup'),
            $agg->completion->completed . ' / ' . $agg->completion->total);
    }
    $stattile($agg->certs, get_string('stat_certs', 'report_gradelookup'));
    echo html_writer::end_div();
};

/**
 * Render ONE learner's own summary tiles, scoped to the list context.
 */
$renderstudenttiles = function(\stdClass $su) use ($fmttime, $stattile, $gradesub) {
    echo html_writer::start_div('report-gradelookup-stats');
    if ($su->single_course) {
        $stattile($su->percent === null ? '—' : $su->percent . '%',
            get_string('stat_grade', 'report_gradelookup'),
            $su->grade === null ? '' : format_float($su->grade, 2) . ' / ' . format_float($su->grademax, 2));
        $stattile($fmttime($su->activetime), get_string('stat_activetime', 'report_gradelookup'));
        $stattile($su->completed ? get_string('yes') : get_string('no'),
            get_string('stat_completed', 'report_gradelookup'),
            $su->completed ? userdate($su->completed, get_string('strftimedate', 'langconfig')) : '');
        $stattile($su->certs, get_string('stat_certs', 'report_gradelookup'));
    } else {
        if ($su->grade->n) {
            $stattile(round($su->grade->avg) . '%', get_string('stat_avggrade', 'report_gradelookup'),
                $gradesub($su->grade));
        } else {
            $stattile('—', get_string('stat_avggrade', 'report_gradelookup'));
        }
        $stattile($su->courses, get_string('stat_courses', 'report_gradelookup'));
        $stattile($fmttime($su->activetime), get_string('stat_activetime', 'report_gradelookup'));
        $stattile($su->certs, get_string('stat_certs', 'report_gradelookup'));
    }
    echo html_writer::end_div();
};

/**
 * Render a table of learner rows. In single-course mode it shows the grade/percent/
 * completed for that course; otherwise a graded-course count. Both add active time.
 * Record links carry the filter context ($ctx) so the summary persists when drilling in.
 */
$renderlearners = function(array $users, int $activecourse, array $timemap, array $ctx)
        use ($baseurl, $percentbadge, $fmttime, $mkurl) {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable report-gradelookup-table';
    if ($activecourse) {
        $table->head = [
            get_string('fullname'), get_string('email'),
            get_string('col_grade', 'report_gradelookup'),
            get_string('col_percent', 'report_gradelookup'),
            get_string('col_completed', 'report_gradelookup'),
            get_string('col_activetime', 'report_gradelookup'), '',
        ];
    } else {
        $table->head = [
            get_string('fullname'), get_string('email'),
            get_string('col_gradedcourses', 'report_gradelookup'),
            get_string('col_activetime', 'report_gradelookup'), '',
        ];
    }

    foreach ($users as $u) {
        $recordurl = $mkurl(['userid' => $u->id] + $ctx);
        $namecell = html_writer::link($recordurl, s(fullname($u)));
        $viewbtn = html_writer::link($recordurl, get_string('viewrecord', 'report_gradelookup'),
            ['class' => 'btn btn-sm btn-primary']);
        $timecell = isset($timemap[$u->id]) && $timemap[$u->id] ? $fmttime($timemap[$u->id]) : '—';

        if ($activecourse) {
            if ($u->grade === null) {
                $gradecell = '—';
            } else {
                $gradetext = format_float($u->grade, 2) . ' / ' . format_float($u->grademax, 2);
                $gradecell = html_writer::link(
                    new moodle_url($baseurl, ['userid' => $u->id, 'courseid' => $activecourse]),
                    $gradetext, ['title' => get_string('gradedetailtitle', 'report_gradelookup')]);
            }
            $table->data[] = [
                $namecell, s($u->email), $gradecell, $percentbadge($u->percent),
                $u->completed ? userdate($u->completed, get_string('strftimedate', 'langconfig'))
                    : get_string('notcompleted', 'report_gradelookup'),
                $timecell, $viewbtn,
            ];
        } else {
            $table->data[] = [$namecell, s($u->email), $u->gradedcourses, $timecell, $viewbtn];
        }
    }
    echo html_writer::table($table);
};

if ($userid && $courseid) {
    // ===================== Single-course grade detail =====================
    $user   = \core_user::get_user($userid, '*', MUST_EXIST);
    $course = get_course($courseid);
    $items  = collector::get_course_grade_items($userid, $courseid);

    echo html_writer::start_div('report-gradelookup-record');
    echo html_writer::start_div('llr-header d-flex justify-content-between align-items-center');
    echo $OUTPUT->heading(get_string('gradedetailfor', 'report_gradelookup',
        (object)['user' => s(fullname($user)), 'course' => format_string($course->fullname)]), 3, 'mb-0');
    echo html_writer::start_div('llr-actions');
    if (collector::has_live_course_grade($userid, $courseid)) {
        echo html_writer::link(
            new moodle_url('/grade/report/user/index.php', ['id' => $courseid, 'userid' => $userid]),
            get_string('viewingradebook', 'report_gradelookup'),
            ['class' => 'btn btn-sm btn-secondary mr-1', 'target' => '_blank', 'rel' => 'noopener']);
    }
    echo html_writer::link($mkurl(['userid' => $userid] + $filterparams),
        get_string('backtorecord', 'report_gradelookup'), ['class' => 'btn btn-sm btn-link']);
    echo html_writer::end_div();
    echo html_writer::end_div();

    if (empty($items)) {
        echo $OUTPUT->notification(get_string('nogradeitems', 'report_gradelookup'), 'info');
    } else {
        echo html_writer::tag('p', get_string('gradedetailhelp', 'report_gradelookup'),
            ['class' => 'text-muted']);
        $table = new html_table();
        $table->attributes['class'] = 'generaltable report-gradelookup-table';
        $table->head = [
            get_string('col_item', 'report_gradelookup'),
            get_string('col_grade', 'report_gradelookup'),
            get_string('col_range', 'report_gradelookup'),
            get_string('col_percent', 'report_gradelookup'),
            get_string('col_updated', 'report_gradelookup'),
        ];
        foreach ($items as $it) {
            if ($it->itemtype === 'course') {
                $name = html_writer::tag('strong', get_string('coursetotal', 'grades'));
            } else if ($it->cmid && $it->modname) {
                $name = html_writer::link(
                    new moodle_url('/mod/' . $it->modname . '/view.php', ['id' => $it->cmid]),
                    format_string($it->itemname !== null && $it->itemname !== ''
                        ? $it->itemname : get_string('unnameditem', 'report_gradelookup')));
            } else {
                $name = ($it->itemname !== null && $it->itemname !== '')
                    ? format_string($it->itemname)
                    : get_string('unnameditem', 'report_gradelookup');
            }
            $row = new html_table_row([
                $name,
                format_float($it->grade, 2),
                format_float($it->grademin, 2) . '–' . format_float($it->grademax, 2),
                $percentbadge($it->percent),
                $it->date ? userdate($it->date, get_string('strftimedatetimeshort', 'langconfig')) : '—',
            ]);
            if ($it->itemtype === 'course') {
                $row->attributes['class'] = 'llr-coursetotal';
            }
            $table->data[] = $row;
        }
        echo html_writer::table($table);
    }
    echo html_writer::end_div();

} else if ($userid) {
    // ===================== Learner record =====================
    $user   = \core_user::get_user($userid, '*', MUST_EXIST);
    $grades = collector::get_grades($userid);
    $certs  = collector::get_certificates($userid);

    echo html_writer::start_div('report-gradelookup-record');
    echo html_writer::start_div('llr-header d-flex justify-content-between align-items-center');
    echo $OUTPUT->heading(get_string('recordfor', 'report_gradelookup', s(fullname($user))), 3, 'mb-0');
    echo html_writer::start_div('llr-actions');
    echo html_writer::link(new moodle_url('/user/profile.php', ['id' => $userid]),
        get_string('viewprofile', 'report_gradelookup'), ['class' => 'btn btn-sm btn-secondary mr-1']);
    echo html_writer::link(new moodle_url($baseurl, ['userid' => $userid, 'format' => 'csv']),
        get_string('downloadcsv', 'report_gradelookup'), ['class' => 'btn btn-sm btn-secondary mr-1']);
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::tag('p', s($user->email), ['class' => 'text-muted mb-1']);

    // The learner's OWN summary, scoped to the list context they were opened from.
    $su = collector::aggregate_user($userid, $courseids);
    if ($singlecourse) {
        echo html_writer::tag('div', get_string('summaryincourse', 'report_gradelookup',
            format_string(get_course($singlecourse)->fullname)), ['class' => 'llr-groupsummary-label text-muted']);
    }
    $renderstudenttiles($su);
    if ($filterparams) {
        echo html_writer::link($mkurl($filterparams),
            get_string('backtoresults', 'report_gradelookup'), ['class' => 'btn btn-sm btn-link pl-0']);
    }

    // --- Grades ---
    echo $OUTPUT->heading(get_string('grades', 'report_gradelookup'), 4);
    if (empty($grades)) {
        echo $OUTPUT->notification(get_string('nogrades', 'report_gradelookup'), 'info');
    } else {
        $table = new html_table();
        $table->attributes['class'] = 'generaltable report-gradelookup-table';
        $table->head = [
            get_string('col_course', 'report_gradelookup'),
            get_string('col_grade', 'report_gradelookup') .
                ' ' . $OUTPUT->help_icon('col_grade', 'report_gradelookup'),
            get_string('col_percent', 'report_gradelookup'),
            get_string('col_completed', 'report_gradelookup'),
        ];
        foreach ($grades as $g) {
            $coursecell = $g->coursedeleted
                ? html_writer::span($g->coursename, 'text-muted')
                : html_writer::link(new moodle_url('/course/view.php', ['id' => $g->courseid]), $g->coursename);

            $gradetext = format_float($g->grade, 2) . ' / ' . format_float($g->grademax, 2);
            $gradecell = html_writer::link(
                new moodle_url($baseurl, ['userid' => $userid, 'courseid' => $g->courseid]),
                $gradetext, ['title' => get_string('gradedetailtitle', 'report_gradelookup')]);

            $completedcell = $g->completed
                ? userdate($g->completed, get_string('strftimedate', 'langconfig'))
                : get_string('notcompleted', 'report_gradelookup');

            $table->data[] = [$coursecell, $gradecell, $percentbadge($g->percent), $completedcell];
        }
        echo html_writer::table($table);
    }

    // --- Certificates ---
    echo $OUTPUT->heading(get_string('certificates', 'report_gradelookup'), 4);
    if (!collector::certificates_available()) {
        echo $OUTPUT->notification(get_string('customcertnotinstalled', 'report_gradelookup'), 'warning');
    } else if (empty($certs)) {
        echo $OUTPUT->notification(get_string('nocertificates', 'report_gradelookup'), 'info');
    } else {
        $ctable = new html_table();
        $ctable->attributes['class'] = 'generaltable report-gradelookup-table';
        $ctable->head = [
            get_string('col_certificate', 'report_gradelookup'),
            get_string('col_course', 'report_gradelookup'),
            get_string('col_issued', 'report_gradelookup'),
            get_string('col_verify', 'report_gradelookup'),
        ];
        foreach ($certs as $c) {
            if (!empty($c->verifyany)) {
                $verifycell = html_writer::link(
                    new moodle_url('/mod/customcert/verify_certificate.php', ['code' => $c->code]),
                    get_string('verifylink', 'report_gradelookup'),
                    ['target' => '_blank', 'rel' => 'noopener']);
            } else {
                $verifycell = html_writer::span(s($c->code), 'text-monospace');
            }
            $ctable->data[] = [
                $c->certname,
                html_writer::link(new moodle_url('/course/view.php', ['id' => $c->courseid]), $c->coursename),
                userdate($c->timecreated, get_string('strftimedate', 'langconfig')),
                $verifycell,
            ];
        }
        echo html_writer::table($ctable);
    }
    echo html_writer::end_div();

} else if ($hasfilter) {
    // ===================== Faceted learner results =====================
    // Active-filter chips (one per selected learner / course / cohort + the date range).
    // $label is already-escaped HTML (each caller escapes its own name: fullname via
    // s(), course/cohort via format_string) — do NOT re-escape it here.
    $renderchip = function($label, moodle_url $url) {
        $x = html_writer::link($url, '×',
            ['class' => 'llr-chip-x', 'aria-label' => get_string('removefilter', 'report_gradelookup')]);
        echo html_writer::span($label . ' ' . $x, 'llr-chip');
    };
    $removeurl = function($type, $val) use ($mkurl, $userids, $courseids, $cohortids, $datefromiso, $datetoiso) {
        $u = $userids; $c = $courseids; $co = $cohortids; $df = $datefromiso; $dt = $datetoiso;
        if ($type === 'user') {
            $u = array_values(array_diff($u, [$val]));
        } else if ($type === 'course') {
            $c = array_values(array_diff($c, [$val]));
        } else if ($type === 'cohort') {
            $co = array_values(array_diff($co, [$val]));
        } else if ($type === 'dates') {
            $df = ''; $dt = '';
        }
        $p = [];
        if ($u) {
            $p['userids'] = $u;
        }
        if ($c) {
            $p['courseids'] = $c;
        }
        if ($co) {
            $p['cohortids'] = $co;
        }
        if ($df !== '') {
            $p['datefrom'] = $df;
        }
        if ($dt !== '') {
            $p['dateto'] = $dt;
        }
        return $mkurl($p);
    };

    echo html_writer::start_div('report-gradelookup-chips');
    foreach ($userids as $uid) {
        $u = \core_user::get_user($uid, '*', IGNORE_MISSING);
        $renderchip(get_string('chip_learner', 'report_gradelookup', $u ? s(fullname($u)) : '#' . $uid),
            $removeurl('user', $uid));
    }
    foreach ($courseids as $cid) {
        $cname = $DB->get_field('course', 'fullname', ['id' => $cid]);
        $renderchip(get_string('chip_course', 'report_gradelookup',
            $cname !== false ? format_string($cname) : '#' . $cid), $removeurl('course', $cid));
    }
    foreach ($cohortids as $chid) {
        $chname = $DB->get_field('cohort', 'name', ['id' => $chid]);
        $renderchip(get_string('chip_cohort', 'report_gradelookup',
            $chname !== false ? format_string($chname) : '#' . $chid), $removeurl('cohort', $chid));
    }
    if ($datefromiso !== '' || $datetoiso !== '') {
        $renderchip(get_string('chip_dates', 'report_gradelookup',
            (object)['from' => $datefromiso !== '' ? $datefromiso : '…',
                     'to' => $datetoiso !== '' ? $datetoiso : '…']),
            $removeurl('dates', 0));
    }
    echo html_writer::end_div();

    // Aggregate summary over the whole matched set.
    $agg = collector::aggregate($userids, $courseids, $cohortids, $from, $to);
    $rendertiles($agg, $singlecourse);

    $learners = collector::search_learners($userids, $courseids, $cohortids, 500);
    $total = $agg ? $agg->count : 0;
    $timemap = $agg ? $agg->timemap : [];

    if (empty($learners)) {
        echo $OUTPUT->notification(get_string('nomatches', 'report_gradelookup'), 'info');
    } else {
        $countmsg = ($total > count($learners))
            ? get_string('listcapped', 'report_gradelookup',
                (object)['shown' => count($learners), 'total' => $total])
            : get_string('usersfound', 'report_gradelookup', $total);
        echo html_writer::start_div('report-gradelookup-toolbar');
        echo html_writer::tag('span', $countmsg, ['class' => 'text-muted', 'aria-live' => 'polite']);
        echo html_writer::link($mkurl(['download' => 'xlsx'] + $filterparams),
            get_string('downloadexcel', 'report_gradelookup'), ['class' => 'btn btn-sm btn-secondary']);
        echo html_writer::end_div();

        $renderlearners($learners, $singlecourse, $timemap, $filterparams);
    }

} else {
    // ===================== Empty state =====================
    echo html_writer::start_div('report-gradelookup-empty');
    echo $OUTPUT->heading(get_string('emptytitle', 'report_gradelookup'), 4);
    echo html_writer::tag('p', get_string('emptystate', 'report_gradelookup'), ['class' => 'text-muted']);
    echo html_writer::end_div();
}

echo $OUTPUT->footer();

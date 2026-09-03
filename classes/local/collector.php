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

namespace report_gradelookup\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Gathers learners' cross-course grades and certificates from stable core tables.
 *
 * All data is read via the DML API from tables that have been stable across many
 * Moodle releases ({grade_grades}, {grade_grades_history}, {grade_items},
 * {course}, {course_completions}, {cohort}, {cohort_members}) plus the optional
 * mod_customcert tables, which are only queried when that plugin is installed.
 * No core code is modified and no private/internal APIs are used, so the report
 * is upgrade-safe.
 *
 * @package    report_gradelookup
 * @copyright  2026 Sternfast LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class collector {

    /** Fields selected from {user} — everything fullname() may need, plus email. */
    const USER_FIELDS = 'u.id, u.firstname, u.lastname, u.email, u.firstnamephonetic,
                         u.lastnamephonetic, u.middlename, u.alternatename';

    // ---------------------------------------------------------------------
    // Unified learner search: free-text (name/email) narrowed by optional
    // course and/or cohort facet filters.
    // ---------------------------------------------------------------------

    /**
     * Faceted learner search. Any combination of selected learners, courses and
     * cohorts (each list may hold several ids); they intersect. Results are enriched
     * with the course grade when EXACTLY ONE course is selected, otherwise with a
     * count of the courses the learner holds grades in (within the selection).
     *
     * @param array $userids Selected learner ids (empty = no learner filter).
     * @param array $courseids Selected course ids (empty = no course filter).
     * @param array $cohortids Selected cohort ids (empty = no cohort filter).
     * @param int $limit Max rows returned.
     * @return array User records keyed by id, enriched per the rules above.
     */
    public static function search_learners(array $userids, array $courseids, array $cohortids,
            int $limit = 500): array {
        global $DB;

        [$where, $params, $possible] = self::learner_where($userids, $courseids, $cohortids);
        if (!$possible) {
            return [];
        }

        $users = $DB->get_records_sql("SELECT " . self::USER_FIELDS . "
                  FROM {user} u
                 WHERE $where
              ORDER BY u.lastname ASC, u.firstname ASC", $params, 0, $limit);
        if (empty($users)) {
            return [];
        }

        $ids = array_keys($users);
        $singlecourse = count($courseids) === 1 ? (int)reset($courseids) : 0;
        if ($singlecourse) {
            $grades = self::course_grade_for_users($singlecourse, $ids);
            foreach ($users as $uid => $u) {
                $g = $grades[$uid] ?? null;
                $u->grade    = $g ? $g->grade : null;
                $u->grademax = $g ? $g->grademax : null;
                $u->percent  = $g ? $g->percent : null;
                $u->source   = $g ? $g->source : '';
                $u->completed = $g ? $g->completed : 0;
            }
        } else {
            $counts = self::graded_course_counts($ids, $courseids);
            foreach ($users as $uid => $u) {
                $u->gradedcourses = $counts[$uid] ?? 0;
            }
        }
        return $users;
    }

    /**
     * Total learners matching a faceted search (for a "showing X of Y" note).
     *
     * @param array $userids
     * @param array $courseids
     * @param array $cohortids
     * @return int
     */
    public static function count_search_learners(array $userids, array $courseids, array $cohortids): int {
        global $DB;
        [$where, $params, $possible] = self::learner_where($userids, $courseids, $cohortids);
        if (!$possible) {
            return 0;
        }
        return $DB->count_records_sql("SELECT COUNT(1) FROM {user} u WHERE $where", $params);
    }

    /**
     * Build the shared WHERE clause + params for the faceted learner search. Each
     * non-empty filter contributes a "u.id IN (…)" condition; they AND together
     * (intersection). Course/cohort filters expand to the UNION of members across
     * the selected ids.
     *
     * @param array $userids
     * @param array $courseids
     * @param array $cohortids
     * @return array [string $wheresql, array $params, bool $possible] — $possible is
     *               false when the filters can match nobody (or there is nothing to
     *               search on), in which case the caller returns an empty result.
     */
    protected static function learner_where(array $userids, array $courseids, array $cohortids): array {
        global $DB;

        $conds = ['u.deleted = 0'];
        $params = [];
        $anyfilter = false;

        if (!empty($userids)) {
            [$in, $p] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'sel');
            $conds[] = "u.id $in";
            $params += $p;
            $anyfilter = true;
        }
        if (!empty($courseids)) {
            $set = self::courses_graded_userids($courseids);
            if (empty($set)) {
                return ['', [], false];
            }
            [$in, $p] = $DB->get_in_or_equal(array_keys($set), SQL_PARAMS_NAMED, 'crs');
            $conds[] = "u.id $in";
            $params += $p;
            $anyfilter = true;
        }
        if (!empty($cohortids)) {
            $set = self::cohorts_userids($cohortids);
            if (empty($set)) {
                return ['', [], false];
            }
            [$in, $p] = $DB->get_in_or_equal(array_keys($set), SQL_PARAMS_NAMED, 'coh');
            $conds[] = "u.id $in";
            $params += $p;
            $anyfilter = true;
        }

        if (!$anyfilter) {
            return ['', [], false];
        }
        return [implode(' AND ', $conds), $params, true];
    }

    /**
     * User ids (deleted excluded) with a course-total grade (live or history) in ANY
     * of the given courses. Keyed by id.
     *
     * @param array $courseids
     * @return array [userid => object]
     */
    protected static function courses_graded_userids(array $courseids): array {
        global $DB;
        if (empty($courseids)) {
            return [];
        }
        [$in1, $p1] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'ca');
        [$in2, $p2] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cb');
        return $DB->get_records_sql("
                SELECT u.id FROM (
                    SELECT gg.userid
                      FROM {grade_grades} gg
                      JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.itemtype = 'course'
                     WHERE gi.courseid $in1 AND gg.finalgrade IS NOT NULL
                    UNION
                    SELECT ggh.userid
                      FROM {grade_grades_history} ggh
                      JOIN {grade_items} gi2 ON gi2.id = ggh.itemid AND gi2.itemtype = 'course'
                     WHERE gi2.courseid $in2 AND ggh.finalgrade IS NOT NULL
                ) x
                JOIN {user} u ON u.id = x.userid AND u.deleted = 0",
                array_merge($p1, $p2));
    }

    /**
     * User ids (deleted excluded) in ANY of the given cohorts, keyed by id.
     *
     * @param array $cohortids
     * @return array [userid => object]
     */
    protected static function cohorts_userids(array $cohortids): array {
        global $DB;
        if (empty($cohortids)) {
            return [];
        }
        [$in, $p] = $DB->get_in_or_equal($cohortids, SQL_PARAMS_NAMED, 'coh');
        return $DB->get_records_sql("
                SELECT DISTINCT u.id
                  FROM {cohort_members} cm
                  JOIN {user} u ON u.id = cm.userid AND u.deleted = 0
                 WHERE cm.cohortid $in", $p);
    }

    /**
     * Course-total grade (live preferred, else latest history) + completion date
     * for a set of users in one course.
     *
     * @param int $courseid
     * @param array $userids
     * @return array [userid => {grade, grademax, grademin, percent, source, completed}]
     */
    protected static function course_grade_for_users(int $courseid, array $userids): array {
        global $DB;
        if (empty($userids)) {
            return [];
        }
        // Each in-clause is used in its own separate statement, so one set is fine.
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');

        $live = $DB->get_records_sql("
                SELECT gg.userid, gi.grademax, gi.grademin, gg.finalgrade
                  FROM {grade_grades} gg
                  JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.itemtype = 'course'
                 WHERE gi.courseid = :cid AND gg.finalgrade IS NOT NULL AND gg.userid $insql",
                array_merge(['cid' => $courseid], $inparams));

        $hist = $DB->get_records_sql("
                SELECT ggh.id, ggh.userid, gi.grademax, gi.grademin, ggh.finalgrade, ggh.timemodified
                  FROM {grade_grades_history} ggh
                  JOIN {grade_items} gi ON gi.id = ggh.itemid AND gi.itemtype = 'course'
                 WHERE gi.courseid = :cid AND ggh.finalgrade IS NOT NULL AND ggh.userid $insql
              ORDER BY ggh.timemodified ASC",
                array_merge(['cid' => $courseid], $inparams));

        $completions = $DB->get_records_sql("
                SELECT userid, timecompleted
                  FROM {course_completions}
                 WHERE course = :cid AND userid $insql",
                array_merge(['cid' => $courseid], $inparams));

        $out = [];
        foreach ($live as $r) {
            $out[$r->userid] = (object)['grade' => (float)$r->finalgrade,
                'grademax' => (float)$r->grademax, 'grademin' => (float)$r->grademin, 'source' => 'live'];
        }
        $histlatest = [];
        foreach ($hist as $r) {
            $histlatest[$r->userid] = $r;
        }
        foreach ($histlatest as $uid => $r) {
            if (!isset($out[$uid])) {
                $out[$uid] = (object)['grade' => (float)$r->finalgrade,
                    'grademax' => (float)$r->grademax, 'grademin' => (float)$r->grademin, 'source' => 'history'];
            }
        }
        foreach ($out as $uid => $o) {
            $o->percent = self::percent($o->grade, $o->grademax, $o->grademin);
            $cc = $completions[$uid] ?? null;
            $o->completed = ($cc && $cc->timecompleted) ? (int)$cc->timecompleted : 0;
        }
        return $out;
    }

    /**
     * Distinct count of courses each user holds a course-total grade in (live or
     * history), optionally restricted to a set of courses. Keyed by userid.
     *
     * @param array $userids
     * @param array $courseids Restrict to these courses (empty = all courses).
     * @return array [userid => int]
     */
    protected static function graded_course_counts(array $userids, array $courseids = []): array {
        global $DB;
        if (empty($userids)) {
            return [];
        }
        // Two distinct sets: both appear in one SQL (the UNION), so they cannot share.
        [$insql1, $p1] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'ga');
        [$insql2, $p2] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'gb');
        $params = array_merge($p1, $p2);
        $c1 = $c2 = '';
        if (!empty($courseids)) {
            [$cin1, $cp1] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'gca');
            [$cin2, $cp2] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'gcb');
            $c1 = " AND gi.courseid $cin1";
            $c2 = " AND gi2.courseid $cin2";
            $params = array_merge($params, $cp1, $cp2);
        }
        $rows = $DB->get_records_sql("
                SELECT userid, COUNT(DISTINCT courseid) AS n FROM (
                    SELECT gg.userid, gi.courseid
                      FROM {grade_grades} gg
                      JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.itemtype = 'course'
                     WHERE gg.finalgrade IS NOT NULL AND gg.userid $insql1$c1
                    UNION
                    SELECT ggh.userid, gi2.courseid
                      FROM {grade_grades_history} ggh
                      JOIN {grade_items} gi2 ON gi2.id = ggh.itemid AND gi2.itemtype = 'course'
                     WHERE ggh.finalgrade IS NOT NULL AND ggh.userid $insql2$c2
                ) x
              GROUP BY userid", $params);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->userid] = (int)$r->n;
        }
        return $out;
    }

    /**
     * All courses (site course excluded) as id => label for a searchable picker,
     * the label carrying the short name / ID number (and a hidden marker).
     *
     * @return array [courseid => string label], ordered by full name.
     */
    public static function get_courses_for_picker(): array {
        global $DB;
        $courses = $DB->get_records_select('course', 'id <> ' . SITEID, null, 'fullname ASC',
            'id, fullname, shortname, idnumber, visible');
        $out = [];
        foreach ($courses as $c) {
            $full  = trim((string)$c->fullname);
            $id    = trim((string)$c->idnumber);
            $short = trim((string)$c->shortname);
            $label = format_string($c->fullname);
            // Show the ID number, then the short name only when it adds information
            // (i.e. it isn't just a repeat of the full name or the ID). Both stay part
            // of the option text so the picker matches by course name AND by ID.
            $meta = [];
            if ($id !== '') {
                $meta[] = s($id);
            }
            if ($short !== '' && stripos($full, $short) === false && strcasecmp($short, $id) !== 0) {
                $meta[] = s($short);
            }
            if ($meta) {
                $label .= ' (' . implode(' · ', $meta) . ')';
            }
            if (!$c->visible) {
                $label .= ' [' . get_string('hiddenlabel', 'report_gradelookup') . ']';
            }
            $out[$c->id] = $label;
        }
        return $out;
    }

    // ---------------------------------------------------------------------
    // Aggregate statistics + log-based active time (over a filtered learner set).
    // ---------------------------------------------------------------------

    /** Session-gap threshold (seconds): a gap longer than this starts a new session.
     *  Same model as Moodle's Dedication block (BLOCK_DEDICATION_DEFAULT_SESSION_LIMIT). */
    const SESSION_GAP = 3600;

    /** Max learners over which grade/certificate aggregates are computed. */
    const AGG_CAP = 2000;

    /** Max learners over which log-based active time is computed on the fly. */
    const TIME_CAP = 1200;

    /**
     * Ids of the learners matching a faceted filter (capped), for aggregation.
     *
     * @param array $userids
     * @param array $courseids
     * @param array $cohortids
     * @param int $cap
     * @return int[] user ids
     */
    public static function matched_userids(array $userids, array $courseids, array $cohortids,
            int $cap = self::AGG_CAP): array {
        global $DB;
        [$where, $params, $possible] = self::learner_where($userids, $courseids, $cohortids);
        if (!$possible) {
            return [];
        }
        $rows = $DB->get_records_sql("SELECT u.id FROM {user} u WHERE $where ORDER BY u.id", $params, 0, $cap);
        return array_keys($rows);
    }

    /**
     * Log-based active time per user, in seconds. Scoped to the given courses when
     * any are supplied, otherwise across all course activity, and optionally bounded
     * to a [from, to] window. Sessions are reconstructed from the standard log
     * exactly as Moodle's Dedication block does (a gap above SESSION_GAP closes a
     * session; each session contributes last−first event).
     *
     * @param array $userids
     * @param array $courseids Restrict to these courses (empty = all courses > 0).
     * @param int $from Unix time lower bound (0 = none).
     * @param int $to Unix time upper bound (0 = none).
     * @return array [userid => seconds]
     */
    public static function active_time_for_users(array $userids, array $courseids = [],
            int $from = 0, int $to = 0): array {
        global $DB;
        if (empty($userids)) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $conds = ["userid $insql"];
        if (!empty($courseids)) {
            [$cin, $cp] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'lc');
            $conds[] = "courseid $cin";
            $params += $cp;
        } else {
            $conds[] = 'courseid > 0';
        }
        if ($from) {
            $conds[] = 'timecreated >= :tfrom';
            $params['tfrom'] = $from;
        }
        if ($to) {
            $conds[] = 'timecreated <= :tto';
            $params['tto'] = $to;
        }
        $where = implode(' AND ', $conds);
        // Stream only three columns; never load the whole set into memory.
        $rs = $DB->get_recordset_sql("SELECT id, userid, timecreated
                  FROM {logstore_standard_log}
                 WHERE $where
              ORDER BY userid, timecreated", $params);
        $time = [];
        $prev = [];
        foreach ($rs as $r) {
            $u = (int)$r->userid;
            if (isset($prev[$u])) {
                $gap = $r->timecreated - $prev[$u];
                if ($gap > 0 && $gap <= self::SESSION_GAP) {
                    $time[$u] = ($time[$u] ?? 0) + $gap;
                }
            }
            $prev[$u] = $r->timecreated;
        }
        $rs->close();
        return $time;
    }

    /**
     * Flat list of course-total grade percentages for a set of learners, restricted
     * to the given courses when any are supplied, otherwise across every course each
     * learner has a grade in (live preferred, else latest history).
     *
     * @param array $userids
     * @param array $courseids Restrict to these courses (empty = all courses).
     * @return int[] percentages
     */
    protected static function percentages_for(array $userids, array $courseids = []): array {
        global $DB;
        if (empty($userids)) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $coursecond = '';
        if (!empty($courseids)) {
            [$cin, $cp] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'pc');
            $coursecond = " AND gi.courseid $cin";
            $params += $cp;
        }

        // Best grade per (user, course) — live preferred, else latest history.
        $best = [];    // "uid-cid" => percent
        $islive = [];  // "uid-cid" => true

        $rs = $DB->get_recordset_sql("
                SELECT gg.id, gg.userid, gi.courseid, gi.grademax, gi.grademin, gg.finalgrade
                  FROM {grade_grades} gg
                  JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.itemtype = 'course'
                 WHERE gg.finalgrade IS NOT NULL AND gg.userid $insql$coursecond", $params);
        foreach ($rs as $r) {
            $k = $r->userid . '-' . $r->courseid;
            $best[$k] = self::percent((float)$r->finalgrade, (float)$r->grademax, (float)$r->grademin);
            $islive[$k] = true;
        }
        $rs->close();

        $rs2 = $DB->get_recordset_sql("
                SELECT ggh.id, ggh.userid, gi.courseid, gi.grademax, gi.grademin, ggh.finalgrade
                  FROM {grade_grades_history} ggh
                  JOIN {grade_items} gi ON gi.id = ggh.itemid AND gi.itemtype = 'course'
                 WHERE ggh.finalgrade IS NOT NULL AND ggh.userid $insql$coursecond
              ORDER BY ggh.timemodified ASC", $params);
        foreach ($rs2 as $r) {
            $k = $r->userid . '-' . $r->courseid;
            if (empty($islive[$k])) { // never override a live grade; latest history wins.
                $best[$k] = self::percent((float)$r->finalgrade, (float)$r->grademax, (float)$r->grademin);
            }
        }
        $rs2->close();

        return array_values(array_filter($best, function($v) {
            return $v !== null;
        }));
    }

    /**
     * Completion count/total for a set of learners in a course.
     *
     * @param array $userids
     * @param int $courseid
     * @return \stdClass {completed, total}
     */
    protected static function completion_stats(array $userids, int $courseid): \stdClass {
        global $DB;
        $total = count($userids);
        if (!$courseid || empty($userids)) {
            return (object)['completed' => 0, 'total' => $total];
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $params['cid'] = $courseid;
        $completed = $DB->count_records_sql("SELECT COUNT(1) FROM {course_completions}
                 WHERE course = :cid AND timecompleted IS NOT NULL AND userid $insql", $params);
        return (object)['completed' => (int)$completed, 'total' => $total];
    }

    /**
     * Count of certificates issued to a set of learners (optionally within the given
     * courses).
     *
     * @param array $userids
     * @param array $courseids Restrict to these courses (empty = any course).
     * @return int
     */
    protected static function cert_count(array $userids, array $courseids = []): int {
        global $DB;
        if (!self::certificates_available() || empty($userids)) {
            return 0;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $where = "ci.userid $insql";
        if (!empty($courseids)) {
            [$cin, $cp] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cc');
            $where .= " AND cc.course $cin";
            $params += $cp;
        }
        return (int)$DB->count_records_sql("SELECT COUNT(1)
                  FROM {customcert_issues} ci
                  JOIN {customcert} cc ON cc.id = ci.customcertid
                 WHERE $where", $params);
    }

    /**
     * Aggregate statistics over a faceted learner set: learner count, grade stats,
     * completion (single-course only), certificates, and log-based active time
     * (total + average per learner, optionally bounded to a date range). Also
     * returns the per-user time map for reuse.
     *
     * @param array $userids
     * @param array $courseids
     * @param array $cohortids
     * @param int $from Active-time window start (0 = none).
     * @param int $to Active-time window end (0 = none).
     * @return \stdClass|null Null when the filter matches nobody.
     */
    public static function aggregate(array $userids, array $courseids, array $cohortids,
            int $from = 0, int $to = 0): ?\stdClass {
        $truecount = self::count_search_learners($userids, $courseids, $cohortids);
        if ($truecount === 0) {
            return null;
        }
        $ids = self::matched_userids($userids, $courseids, $cohortids, self::AGG_CAP);
        $idcount = count($ids);
        $singlecourse = count($courseids) === 1 ? (int)reset($courseids) : 0;

        // Grade stats are over learners who actually attempted (grade above 0);
        // never-attempted zeros are excluded so the average reflects performance.
        $pcts = self::percentages_for($ids, $courseids);
        $grade = self::grade_stats($pcts);

        $completion = self::completion_stats($ids, $singlecourse);

        $timecapped = $idcount > self::TIME_CAP;
        $timemap = $timecapped ? [] : self::active_time_for_users($ids, $courseids, $from, $to);
        $timetotal = array_sum($timemap);

        return (object)[
            'count'      => $truecount,
            'sampled'    => $truecount > $idcount,
            'idcount'    => $idcount,
            'grade'      => $grade,
            'completion' => $completion,
            'certs'      => self::cert_count($ids, $courseids),
            'timetotal'  => $timetotal,
            'timeavg'    => $idcount ? $timetotal / $idcount : 0,
            'timeactive' => count($timemap),
            'timecapped' => $timecapped,
            'timemap'    => $timemap,
            'singlecourse' => $singlecourse,
        ];
    }

    /**
     * Grade statistics over a set of percentages, counting only learners who
     * attempted (percentage above 0). Never-attempted zeros are excluded from the
     * average/median/range but reported as ->excluded / ->total for transparency.
     *
     * @param array $pcts All percentages in scope (including zeros).
     * @return \stdClass {n, total, excluded, avg, median, min, max}
     */
    protected static function grade_stats(array $pcts): \stdClass {
        $attempted = array_values(array_filter($pcts, function($p) {
            return $p > 0;
        }));
        sort($attempted);
        $n = count($attempted);
        return (object)[
            'n'        => $n,
            'total'    => count($pcts),
            'excluded' => count($pcts) - $n,
            'avg'      => $n ? array_sum($attempted) / $n : null,
            'median'   => $n ? $attempted[intdiv($n, 2)] : null,
            'min'      => $n ? $attempted[0] : null,
            'max'      => $n ? $attempted[$n - 1] : null,
        ];
    }

    /**
     * A single learner's own summary, scoped to match the list they were opened
     * from. With a course context, their grade/active time/completion/certs IN that
     * course; otherwise their across-courses summary (avg grade, courses-with-grades,
     * total active time, certificates).
     *
     * @param int $userid
     * @param int $courseid Course context (0 = across all courses).
     * @return \stdClass
     */
    public static function aggregate_user(int $userid, array $courseids = []): \stdClass {
        $singlecourse = count($courseids) === 1 ? (int)reset($courseids) : 0;
        if ($singlecourse) {
            $grades = self::course_grade_for_users($singlecourse, [$userid]);
            $g = $grades[$userid] ?? null;
            $tm = self::active_time_for_users([$userid], [$singlecourse]);
            return (object)[
                'single_course' => true,
                'percent'    => $g ? $g->percent : null,
                'grade'      => $g ? $g->grade : null,
                'grademax'   => $g ? $g->grademax : null,
                'completed'  => $g ? $g->completed : 0,
                'activetime' => (int)array_sum($tm),
                'certs'      => self::cert_count([$userid], [$singlecourse]),
            ];
        }

        $pcts = self::percentages_for([$userid], $courseids);
        $tm = self::active_time_for_users([$userid], $courseids);
        return (object)[
            'single_course' => false,
            'grade'      => self::grade_stats($pcts),
            'courses'    => count($pcts),
            'activetime' => (int)array_sum($tm),
            'certs'      => self::cert_count([$userid], $courseids),
        ];
    }

    /**
     * Course-total grades for one learner across every course (live preferred,
     * recovered from history otherwise), enriched with course name, percent and
     * completion date.
     *
     * @param int $userid
     * @return array Course-keyed list of grade objects.
     */
    public static function get_grades(int $userid): array {
        global $DB;

        // Live course-total grades (one row per course — unique itemid+userid).
        $live = $DB->get_records_sql("
                SELECT gi.courseid, gi.grademax, gi.grademin, gg.finalgrade, gg.timemodified
                  FROM {grade_grades} gg
                  JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.itemtype = 'course'
                 WHERE gg.userid = :uid AND gg.finalgrade IS NOT NULL",
                ['uid' => $userid]);

        // Historical course-total grades (ASC so the last row per course is newest).
        $hist = $DB->get_records_sql("
                SELECT ggh.id, gi.courseid, gi.grademax, gi.grademin, ggh.finalgrade, ggh.timemodified
                  FROM {grade_grades_history} ggh
                  JOIN {grade_items} gi ON gi.id = ggh.itemid AND gi.itemtype = 'course'
                 WHERE ggh.userid = :uid AND ggh.finalgrade IS NOT NULL
              ORDER BY ggh.timemodified ASC",
                ['uid' => $userid]);

        $result = [];
        foreach ($live as $r) {
            $result[$r->courseid] = self::make_grade_row($r->courseid, $r->finalgrade,
                $r->grademax, $r->grademin, $r->timemodified, 'live');
        }
        $histlatest = [];
        foreach ($hist as $r) {
            $histlatest[$r->courseid] = $r; // ASC order => last one wins.
        }
        foreach ($histlatest as $courseid => $r) {
            if (!isset($result[$courseid])) {
                $result[$courseid] = self::make_grade_row($courseid, $r->finalgrade,
                    $r->grademax, $r->grademin, $r->timemodified, 'history');
            }
        }

        if (empty($result)) {
            return [];
        }

        // Enrich with course name and completion date — in bulk (no per-row queries).
        $courseids = array_keys($result);
        [$insql, $inparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');
        $courses = $DB->get_records_select('course', "id $insql", $inparams, '',
            'id, fullname, shortname');

        $cparams = $inparams;
        $cparams['uid'] = $userid;
        $completions = $DB->get_records_select('course_completions',
            "userid = :uid AND course $insql", $cparams, '', 'course, timecompleted');

        foreach ($result as $courseid => $row) {
            $course = $courses[$courseid] ?? null;
            $row->coursename = $course ? format_string($course->fullname) : get_string('notavailable');
            $row->coursedeleted = empty($course);
            $cc = $completions[$courseid] ?? null;
            $row->completed = ($cc && $cc->timecompleted) ? (int)$cc->timecompleted : 0;
        }

        uasort($result, static function($a, $b) {
            return strcasecmp($a->coursename, $b->coursename);
        });

        return $result;
    }

    /**
     * Build a normalised course-total grade row.
     *
     * @param int $courseid
     * @param float $finalgrade
     * @param float $grademax
     * @param float $grademin
     * @param int $timemodified
     * @param string $source 'live' or 'history'
     * @return \stdClass
     */
    protected static function make_grade_row($courseid, $finalgrade, $grademax, $grademin,
            $timemodified, string $source): \stdClass {
        $grademax = (float)$grademax;
        $grademin = (float)$grademin;
        return (object)[
            'courseid' => (int)$courseid,
            'grade'    => (float)$finalgrade,
            'grademax' => $grademax,
            'grademin' => $grademin,
            'date'     => (int)$timemodified,
            'source'   => $source,
            'percent'  => self::percent((float)$finalgrade, $grademax, $grademin),
        ];
    }

    /**
     * Percentage of a grade within its range, or null when the range is degenerate.
     *
     * @param float $grade
     * @param float $grademax
     * @param float $grademin
     * @return int|null
     */
    public static function percent(float $grade, float $grademax, float $grademin): ?int {
        if ($grademax <= $grademin) {
            return null;
        }
        return (int)round(($grade - $grademin) / ($grademax - $grademin) * 100);
    }

    /**
     * Item-level grade breakdown for one learner in one course (live preferred,
     * recovered from history otherwise). This is what core's User report cannot
     * show once a learner is unenrolled and their live grades are purged.
     *
     * @param int $userid
     * @param int $courseid
     * @return array Ordered list of item objects (course total last).
     */
    public static function get_course_grade_items(int $userid, int $courseid): array {
        global $DB;

        $itemcols = "gi.id AS itemid, gi.itemname, gi.itemtype, gi.itemmodule,
                     gi.iteminstance, gi.sortorder, gi.grademax, gi.grademin";

        $live = $DB->get_records_sql("
                SELECT $itemcols, gg.finalgrade, gg.timemodified
                  FROM {grade_grades} gg
                  JOIN {grade_items} gi ON gi.id = gg.itemid
                 WHERE gg.userid = :uid AND gi.courseid = :cid AND gg.finalgrade IS NOT NULL",
                ['uid' => $userid, 'cid' => $courseid]);

        $hist = $DB->get_records_sql("
                SELECT ggh.id, $itemcols, ggh.finalgrade, ggh.timemodified
                  FROM {grade_grades_history} ggh
                  JOIN {grade_items} gi ON gi.id = ggh.itemid
                 WHERE ggh.userid = :uid AND gi.courseid = :cid AND ggh.finalgrade IS NOT NULL
              ORDER BY ggh.timemodified ASC",
                ['uid' => $userid, 'cid' => $courseid]);

        $items = [];
        foreach ($live as $r) {
            $items[$r->itemid] = self::make_item_row($r, 'live');
        }
        $histlatest = [];
        foreach ($hist as $r) {
            $histlatest[$r->itemid] = $r; // ASC => last wins.
        }
        foreach ($histlatest as $itemid => $r) {
            if (!isset($items[$itemid])) {
                $items[$itemid] = self::make_item_row($r, 'history');
            }
        }

        if (empty($items)) {
            return [];
        }

        // Resolve activity links for 'mod' items in bulk (skips deleted modules).
        $cmids = $DB->get_records_sql("
                SELECT gi.id AS itemid, cm.id AS cmid, gi.itemmodule
                  FROM {grade_items} gi
                  JOIN {modules} m ON m.name = gi.itemmodule
                  JOIN {course_modules} cm ON cm.module = m.id
                       AND cm.instance = gi.iteminstance AND cm.course = gi.courseid
                 WHERE gi.courseid = :cid AND gi.itemtype = 'mod'",
                ['cid' => $courseid]);
        foreach ($cmids as $c) {
            if (isset($items[$c->itemid])) {
                $items[$c->itemid]->cmid = (int)$c->cmid;
                $items[$c->itemid]->modname = $c->itemmodule;
            }
        }

        // Order: everything by grade_items sortorder, course total forced last.
        uasort($items, static function($a, $b) {
            if ($a->itemtype === 'course' && $b->itemtype !== 'course') {
                return 1;
            }
            if ($b->itemtype === 'course' && $a->itemtype !== 'course') {
                return -1;
            }
            return $a->sortorder <=> $b->sortorder;
        });

        return array_values($items);
    }

    /**
     * Build a normalised grade-item row.
     *
     * @param \stdClass $r Raw joined row.
     * @param string $source 'live' or 'history'
     * @return \stdClass
     */
    protected static function make_item_row(\stdClass $r, string $source): \stdClass {
        return (object)[
            'itemid'     => (int)$r->itemid,
            'itemname'   => $r->itemname,
            'itemtype'   => $r->itemtype,
            'itemmodule' => $r->itemmodule,
            'sortorder'  => (int)$r->sortorder,
            'grade'      => (float)$r->finalgrade,
            'grademax'   => (float)$r->grademax,
            'grademin'   => (float)$r->grademin,
            'percent'    => self::percent((float)$r->finalgrade, (float)$r->grademax, (float)$r->grademin),
            'date'       => (int)$r->timemodified,
            'source'     => $source,
            'cmid'       => 0,
            'modname'    => '',
        ];
    }

    /**
     * Whether the given learner has any live (non-purged) grade in the course.
     * When true the native Moodle User report can be linked; when false only the
     * reconstructed breakdown is available.
     *
     * @param int $userid
     * @param int $courseid
     * @return bool
     */
    public static function has_live_course_grade(int $userid, int $courseid): bool {
        global $DB;
        return $DB->record_exists_sql("
                SELECT 1
                  FROM {grade_grades} gg
                  JOIN {grade_items} gi ON gi.id = gg.itemid
                 WHERE gg.userid = :uid AND gi.courseid = :cid AND gg.finalgrade IS NOT NULL",
                ['uid' => $userid, 'cid' => $courseid]);
    }

    // ---------------------------------------------------------------------
    // Cohorts.
    // ---------------------------------------------------------------------

    /**
     * Whether cohorts exist on this site.
     *
     * @return bool
     */
    public static function cohorts_available(): bool {
        global $DB;
        return $DB->get_manager()->table_exists('cohort')
            && $DB->record_exists('cohort', []);
    }

    /**
     * All cohorts with their member counts, for a picker.
     *
     * @return array Objects: id, name, idnumber, members. Ordered by name.
     */
    public static function get_cohorts(): array {
        global $DB;
        if (!$DB->get_manager()->table_exists('cohort')) {
            return [];
        }
        $cohorts = $DB->get_records('cohort', null, 'name ASC', 'id, name, idnumber');
        if (empty($cohorts)) {
            return [];
        }
        $counts = $DB->get_records_sql("
                SELECT cohortid, COUNT(1) AS members
                  FROM {cohort_members}
              GROUP BY cohortid");
        foreach ($cohorts as $c) {
            $c->name = format_string($c->name);
            $c->members = isset($counts[$c->id]) ? (int)$counts[$c->id]->members : 0;
        }
        return $cohorts;
    }

    // ---------------------------------------------------------------------
    // Certificates (mod_customcert, optional).
    // ---------------------------------------------------------------------

    /**
     * Whether the certificate module is available on this site.
     *
     * @return bool
     */
    public static function certificates_available(): bool {
        global $DB;
        return $DB->get_manager()->table_exists('customcert_issues')
            && $DB->get_manager()->table_exists('customcert');
    }

    /**
     * Certificates issued to the learner (empty if mod_customcert is not installed).
     *
     * @param int $userid
     * @return array
     */
    public static function get_certificates(int $userid): array {
        global $DB;

        if (!self::certificates_available()) {
            return [];
        }

        $records = $DB->get_records_sql("
                SELECT ci.id, ci.timecreated, ci.code, ci.customcertid,
                       cc.name AS certname, cc.course AS courseid, cc.verifyany,
                       c.fullname AS coursename
                  FROM {customcert_issues} ci
                  JOIN {customcert} cc ON cc.id = ci.customcertid
                  JOIN {course} c ON c.id = cc.course
                 WHERE ci.userid = :uid
              ORDER BY ci.timecreated DESC",
                ['uid' => $userid]);

        foreach ($records as $r) {
            $r->certname = format_string($r->certname);
            $r->coursename = format_string($r->coursename);
        }
        return $records;
    }
}

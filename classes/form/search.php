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

namespace report_gradelookup\form;

defined('MOODLE_INTERNAL') || die();

use report_gradelookup\local\collector;

/**
 * One unified search: multi-select learner (AJAX), course and cohort facets, plus an
 * optional active-between date range. Rendered as a GET form so searches are
 * bookmarkable. Course/cohort are static-option autocompletes (client-side filter, no
 * ajax); the learner picker uses core's AJAX user selector; the dates are native HTML5
 * date inputs (browser-localised display, ISO on submit).
 *
 * @package    report_gradelookup
 * @copyright  2026 Sternfast LMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search extends \moodleform {

    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $cancohort    = !empty($this->_customdata['cancohort']);
        $canpickusers = !empty($this->_customdata['canpickusers']);
        $datefrom     = $this->_customdata['datefrom'] ?? '';
        $dateto       = $this->_customdata['dateto'] ?? '';

        $mform->addElement('html', \html_writer::start_div('report-gradelookup-searchform',
            ['role' => 'search']));

        // Learner — AJAX multi-select over all site users. Only offered to viewers who
        // may see full user details (the AJAX backend requires moodle/user:viewalldetails).
        if ($canpickusers) {
            $useropts = [
                'ajax'              => 'core_user/form_user_selector',
                'multiple'          => true,
                'noselectionstring' => get_string('anylearner', 'report_gradelookup'),
                'valuehtmlcallback' => function($userid) {
                    global $OUTPUT;
                    $context = \context_system::instance();
                    $fields = \core_user\fields::for_name()->with_identity($context, true);
                    $record = \core_user::get_user($userid, 'id ' . $fields->get_sql()->selects, IGNORE_MISSING);
                    if (!$record) {
                        return false; // Bookmarked URL naming a since-deleted user: drop the chip.
                    }
                    $user = (object)[
                        'id'          => $record->id,
                        'fullname'    => fullname($record, has_capability('moodle/site:viewfullnames', $context)),
                        'extrafields' => [],
                    ];
                    foreach ($fields->get_required_fields([\core_user\fields::PURPOSE_IDENTITY]) as $extrafield) {
                        $user->extrafields[] = (object)['name' => $extrafield, 'value' => s($record->$extrafield)];
                    }
                    return $OUTPUT->render_from_template('core_user/form_user_selector_suggestion', $user);
                },
            ];
            $mform->addElement('autocomplete', 'userids', get_string('filterlearner', 'report_gradelookup'),
                [], $useropts);
            $mform->setType('userids', PARAM_INT);
        }

        // Course — static multi-select.
        $mform->addElement('autocomplete', 'courseids', get_string('filtercourse', 'report_gradelookup'),
            collector::get_courses_for_picker(),
            ['multiple' => true, 'noselectionstring' => get_string('anycourse', 'report_gradelookup')]);
        $mform->setType('courseids', PARAM_INT);

        // Cohort — static multi-select (only for viewers who may see cohorts).
        if ($cancohort) {
            $cohortoptions = [];
            foreach (collector::get_cohorts() as $c) {
                // Include the cohort ID number (when it isn't just the name repeated) so
                // the picker matches by cohort name AND by ID; keep the member count.
                $meta = [];
                if ($c->idnumber !== '' && strcasecmp($c->idnumber, $c->name) !== 0) {
                    $meta[] = s($c->idnumber);
                }
                $meta[] = $c->members;
                $cohortoptions[$c->id] = $c->name . ' (' . implode(' · ', $meta) . ')';
            }
            $mform->addElement('autocomplete', 'cohortids', get_string('filtercohort', 'report_gradelookup'),
                $cohortoptions,
                ['multiple' => true, 'noselectionstring' => get_string('anycohort', 'report_gradelookup')]);
            $mform->setType('cohortids', PARAM_INT);
        }

        // Optional active-between date range — native HTML5 date inputs (localised
        // display, ISO YYYY-MM-DD on submit, read server-side with optional_param).
        $daterow = \html_writer::div(
            \html_writer::tag('label', get_string('datefrom', 'report_gradelookup'),
                ['for' => 'llr-datefrom', 'class' => 'mr-1']) .
            \html_writer::empty_tag('input', ['type' => 'date', 'id' => 'llr-datefrom',
                'name' => 'datefrom', 'value' => $datefrom, 'class' => 'form-control mr-3']) .
            \html_writer::tag('label', get_string('dateto', 'report_gradelookup'),
                ['for' => 'llr-dateto', 'class' => 'mr-1']) .
            \html_writer::empty_tag('input', ['type' => 'date', 'id' => 'llr-dateto',
                'name' => 'dateto', 'value' => $dateto, 'class' => 'form-control']),
            'llr-daterange form-inline');
        $mform->addElement('static', 'daterange', get_string('daterange', 'report_gradelookup'), $daterow);

        // Search, plus a Clear button that resets every facet back to the empty state.
        // Clear is a plain link to the base report URL (a GET form's "no parameters"
        // state), so it needs no JavaScript and works with the back button. It appears
        // only when there is something to clear.
        $buttons = [$mform->createElement('submit', 'go', get_string('searchbutton', 'report_gradelookup'))];
        if (!empty($this->_customdata['hasselection'])) {
            $clearurl = new \moodle_url('/report/gradelookup/index.php');
            $buttons[] = $mform->createElement('static', 'clearbtn', '',
                \html_writer::link($clearurl, get_string('clearfilters', 'report_gradelookup'),
                    ['class' => 'btn btn-outline-secondary', 'role' => 'button']));
        }
        $mform->addGroup($buttons, 'buttonar', '', ' ', false);

        $mform->addElement('html', \html_writer::end_div());
    }
}

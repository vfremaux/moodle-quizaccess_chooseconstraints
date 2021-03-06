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
 * Implementaton of the quizaccess_chooseconstraints plugin.
 *
 * @package     quizaccess_chooseconstraints
 * @category    quizaccess
 * @author      Valery Fremaux <valery.fremaux@gmail.com>
 * @copyright   (C) 2010 onwards Valery Fremaux (http://www.mylearningfactory.com)
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/mod/quiz/accessrule/accessrulebase.php');
require_once($CFG->dirroot.'/mod/quiz/accessrule/chooseconstraints/lib.php');

/**
 * A rule implementing the constraint check.
 *
 * @copyright  2009 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quizaccess_chooseconstraints extends quiz_access_rule_base {

    protected $attempt;

    public static function make(quiz $quizobj, $timenow, $canignoretimelimits) {
        return new self($quizobj, $timenow);
    }

    public function description() {
        if ($this->is_enabled()) {
            return get_string('chooseconstraints', 'quizaccess_chooseconstraints');
        }
    }

    public function is_preflight_check_required($attemptid) {
        global $DB, $SESSION, $USER;

        // Check this rule is enabled for this quiz.
        if (!$this->is_enabled()) {
            return false;
        }

        // Get constraints from url if user has made a choice.
        $setconstraints = optional_param('setconstraints', 0, PARAM_BOOL);
        $constraints = optional_param('constraints', '', PARAM_TEXT);

        if ($setconstraints) {
            // If constraints are received in URL, push them in session and do not require preflight.
            // Secure the constraints submissions.
            require_sesskey();
            $SESSION->qa_constraints = $constraints;
            if (function_exists('debug_trace')) {
                debug_trace("Setting constraints in session : ".$SESSION->qa_constraints);
            }

            if (!$attemptid) {
                // Recalculate everything in order to prematurely make a new attempt.
                $page = optional_param('page', -1, PARAM_INT);

                // Look for an existing attempt.
                $lastattempt = null;
                if ($attempts = quiz_get_user_attempts($this->quiz->id, $USER->id, 'all', true)) {
                    $lastattempt = end($attempts);
                }
                $attemptnumber = @$lastattempt->attempt + 1;

                /* This will setup attempt with questions, choose final questions
                 * for random questions, and for random constrained questions using the actual
                 * $SESSION->qa_constraints value.
                 */
                $attempt = quiz_prepare_and_start_new_constrained_attempt($this->quizobj, $attemptnumber, $lastattempt);
                // Redirect to the attempt page.

                /*
                 * We have now a valid attempt id now, so we can notify the DB of what constraints
                 * have been used through the session.
                 */
                $this->notify_preflight_check_passed($attempt->id);

                /*
                 * Everything has been produced and registered. We can reset the user constraint choice.
                 */
                unset($SESSION->qa_constraints);

                redirect($this->quizobj->attempt_url($attempt->id, $page));
            }
            return false;
        }

        if ($attemptid) {
            $attempt = $DB->get_record('qa_chooseconstraints_attempt', array('attemptid' => $attemptid));
            return empty($attempt->categories);
        }
        return false;
    }

    public static function add_settings_form_fields(mod_quiz_mod_form $quizform, MoodlequickForm $mform) {
        global $COURSE;

        $mform->addElement('checkbox', 'choicerootenabled', get_string('enable', 'quizaccess_chooseconstraints'));

        $thiscontext = context_course::instance($COURSE->id);
        $contexts = new question_edit_contexts($thiscontext);

        $qoptions = array();
        if ($categoriesarray = question_category_options($contexts->all(), true, 0, false, -1)) {
            foreach ($categoriesarray as $catname => $catsection) {
                $i = 0;
                foreach ($catsection as $key => $cat) {
                    if ($i == 0) {
                        $qoptions[$key] = "$catname : $cat";
                    } else {
                        $qoptions[$key] = $cat;
                    }
                    $i++;
                }
            }
        }
        $label = get_string('choicerootcategory', 'quizaccess_chooseconstraints');
        $mform->addElement('select', 'choicerootcategory', $label, $qoptions);
        $mform->disabledIf('choicerootcategory', 'choicerootenabled', 'notchecked');

        $doptions = array('0' => get_string('unlimited'), '1' => 1, '2' => 2, '3' => 3);
        $label = get_string('choicedeepness', 'quizaccess_chooseconstraints');
        $mform->addElement('select', 'choicedeepness', $label, $doptions);
        $mform->disabledIf('choicedeepness', 'choicerootenabled', 'notchecked');
    }

    /**
     * Save any submitted settings when the quiz settings form is submitted. This
     * is called from {@link quiz_after_add_or_update()} in lib.php.
     * @param object $quiz the data from the quiz form, including $quiz->id
     *      which is the id of the quiz being saved.
     */
    public static function save_settings($quiz) {
        global $DB;

        if (!empty($quiz->choicerootenabled)) {
            if ($oldrecord = $DB->get_record('qa_chooseconstraints_quiz', array('quizid' => $quiz->id))) {
                $oldrecord->enabled = 1;
                $oldrecord->choicerootcategory = $quiz->choicerootcategory;
                $oldrecord->choicedeepness = $quiz->choicedeepness;
                $DB->update_record('qa_chooseconstraints_quiz', $oldrecord);
            } else {
                $record = new Stdclass;
                $record->enabled = 1;
                $record->quizid = $quiz->id;
                $record->choicerootcategory = $quiz->choicerootcategory;
                $record->choicedeepness = $quiz->choicedeepness;
                $DB->insert_record('qa_chooseconstraints_quiz', $record);
            }
        } else {
            if ($oldrecord = $DB->get_record('qa_chooseconstraints_quiz', array('quizid' => $quiz->id))) {
                $oldrecord->enabled = 0;
                $DB->update_record('qa_chooseconstraints_quiz', $oldrecord);
            }
        }
    }

    /**
     * Delete any rule-specific settings when the quiz is deleted. This is called
     * from {@link quiz_delete_instance()} in lib.php.
     * @param object $quiz the data from the database, including $quiz->id
     *      which is the id of the quiz being deleted.
     * @since Moodle 2.7.1, 2.6.4, 2.5.7
     */
    public static function delete_settings($quiz) {
        global $DB;

        $DB->delete_records('qa_chooseconstraints_quiz', array('quizid' => $quiz->id));
        $DB->delete_records('qa_chooseconstraints_attempt', array('quizid' => $quiz->id));
    }

    /**
     * Return the bits of SQL needed to load all the settings from all the access
     * plugins in one DB query. The easiest way to understand what you need to do
     * here is probalby to read the code of {@link quiz_access_manager::load_settings()}.
     *
     * If you have some settings that cannot be loaded in this way, then you can
     * use the {@link get_extra_settings()} method instead, but that has
     * performance implications.
     *
     * @param int $quizid the id of the quiz we are loading settings for. This
     *     can also be accessed as quiz.id in the SQL. (quiz is a table alisas for {quiz}.)
     * @return array with three elements:
     *     1. fields: any fields to add to the select list. These should be alised
     *        if neccessary so that the field name starts the name of the plugin.
     *     2. joins: any joins (should probably be LEFT JOINS) with other tables that
     *        are needed.
     *     3. params: array of placeholder values that are needed by the SQL. You must
     *        used named placeholders, and the placeholder names should start with the
     *        plugin name, to avoid collisions.
     */
    public static function get_settings_sql($quizid) {
        $joinclause = 'LEFT JOIN {qa_chooseconstraints_quiz} qacs ON qacs.quizid = quiz.id ';
        return array('qacs.choicerootcategory, qacs.choicedeepness, qacs.enabled as choicerootenabled', $joinclause, array());
    }

    public function add_preflight_check_form_fields(mod_quiz_preflight_check_form $quizform, MoodleQuickForm $mform, $attemptid) {
        global $COURSE;

        $label = get_string('chooseconstraints', 'quizaccess_chooseconstraints');
        $mform->addElement('header', 'userconstraintsheader', $label);

        $thiscontext = context_course::instance($COURSE->id);
        $contexts = new question_edit_contexts($thiscontext);
        $categories = array();
        list($rootcatid, $contextid) = explode(',', $this->quiz->choicerootcategory);
        quiz_fetch_category_tree($this->quiz, $rootcatid, $categories);

        foreach ($categories as $key => $cat) {
            $mform->addElement('checkbox', 'sel_'.$key, '', ' '.$cat->name);
        }

        $mform->addElement('hidden', 'attempt', $attemptid);
        $mform->setType('attempt', PARAM_INT);
    }

    public function validate_preflight_check($data, $files, $errors, $attemptid) {
        global $SESSION;

        if ($selkeys = preg_grep('/sel_\\d+/', array_keys($data))) {
            foreach ($selkeys as $key) {
                $catids[] = str_replace('sel_', '', $key);
            }
            $SESSION->qa_constraints = implode(',', $catids);
        }

        return $errors;
    }

    public function notify_preflight_check_passed($attemptid) {
        global $DB, $SESSION;

        if ($attemptid) {
            $attempt = $DB->get_record('qa_chooseconstraints_attempt', array('attemptid' => $attemptid));

            if (!$attempt && !empty($SESSION->qa_constraints)) {
                $attempt = new StdClass;
                $attempt->attemptid = $attemptid;
                $attempt->quizid = $DB->get_field('quiz_attempts', 'quiz', array('id' => $attemptid));
                $attempt->categories = $SESSION->qa_constraints;
                $DB->insert_record('qa_chooseconstraints_attempt', $attempt);
            }
        }

        return !empty($attempt->categories) || !empty($SESSION->qa_constraints);
    }

    /**
     * This is a hack, it's the only way to store the current attempt object in accessible scope.
     * @param object $attempt the current attempt
     * @return mixed the attempt close time, or false if there is no close time.
     */
    public function end_time($attempt) {
        $this->attempt = $attempt;
        return false;
    }

    protected function is_enabled() {
        global $DB;

        $enabled = $DB->get_field('qa_chooseconstraints_quiz', 'enabled', array('quizid' => $this->quiz->id));
        return $enabled;
    }
}

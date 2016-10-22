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
 * This script deals with starting a new attempt at a quiz.
 *
 * Normally, it will end up redirecting to attempt.php - unless a password form is displayed.
 *
 * This code used to be at the top of attempt.php, if you are looking for CVS history.
 *
 * @package   mod_quiz
 * @copyright 2009 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/mod/quiz/accessrule/chooseconstraints/lib.php');

// Get submitted parameters.
$id = required_param('cmid', PARAM_INT); // Course module id
$forcenew = optional_param('forcenew', false, PARAM_BOOL); // Used to force a new preview
$page = optional_param('page', -1, PARAM_INT); // Page to jump to in the attempt.

if (!$cm = get_coursemodule_from_id('quiz', $id)) {
    print_error('invalidcoursemodule');
}
if (!$course = $DB->get_record('course', array('id' => $cm->course))) {
    print_error("coursemisconf");
}

$quizobj = quiz::create($cm->instance, $USER->id);
// This script should only ever be posted to, so set page URL to the view page.
$PAGE->set_url($quizobj->view_url());

// Check login and sesskey.
require_login($quizobj->get_course(), false, $quizobj->get_cm());
require_sesskey();
$PAGE->set_heading($quizobj->get_course()->fullname);

// If no questions have been set up yet redirect to edit.php or display an error.
if (!$quizobj->has_questions()) {
    if ($quizobj->has_capability('mod/quiz:manage')) {
        redirect($quizobj->edit_url());
    } else {
        print_error('cannotstartnoquestions', 'quiz', $quizobj->view_url());
    }
}

// Create an object to manage all the other (non-roles) access rules.
$timenow = time();
$accessmanager = $quizobj->get_access_manager($timenow);
if ($quizobj->is_preview_user() && $forcenew) {
    $accessmanager->current_attempt_finished();
}

// Check capabilities.
if (!$quizobj->is_preview_user()) {
    $quizobj->require_capability('mod/quiz:attempt');
}

// Check to see if a new preview was requested.
if ($quizobj->is_preview_user() && $forcenew) {
    // To force the creation of a new preview, we mark the current attempt (if any)
    // as finished. It will then automatically be deleted below.
    $DB->set_field('quiz_attempts', 'state', quiz_attempt::FINISHED,
            array('quiz' => $quizobj->get_quizid(), 'userid' => $USER->id));
}

// Look for an existing attempt.
$attempts = quiz_get_user_attempts($quizobj->get_quizid(), $USER->id, 'all', true);
$lastattempt = end($attempts);

// If an in-progress attempt exists, check password then redirect to it.
if ($lastattempt && ($lastattempt->state == quiz_attempt::IN_PROGRESS ||
        $lastattempt->state == quiz_attempt::OVERDUE)) {
    $currentattemptid = $lastattempt->id;
    $messages = $accessmanager->prevent_access();

    // If the attempt is now overdue, deal with that.
    $quizobj->create_attempt_object($lastattempt)->handle_if_time_expired($timenow, true);

    // And, if the attempt is now no longer in progress, redirect to the appropriate place.
    if ($lastattempt->state == quiz_attempt::ABANDONED || $lastattempt->state == quiz_attempt::FINISHED) {
        redirect($quizobj->review_url($lastattempt->id));
    }

    // If the page number was not explicitly in the URL, go to the current page.
    if ($page == -1) {
        $page = $lastattempt->currentpage;
    }

} else {
    while ($lastattempt && $lastattempt->preview) {
        $lastattempt = array_pop($attempts);
    }

    // Get number for the next or unfinished attempt.
    if ($lastattempt) {
        $attemptnumber = $lastattempt->attempt + 1;
    } else {
        $lastattempt = false;
        $attemptnumber = 1;
    }
    $currentattemptid = null;

    $messages = $accessmanager->prevent_access() +
            $accessmanager->prevent_new_attempt(count($attempts), $lastattempt);

    if ($page == -1) {
        $page = 0;
    }
}

// Check access.
$output = $PAGE->get_renderer('mod_quiz');
if (!$quizobj->is_preview_user() && $messages) {
    print_error('attempterror', 'quiz', $quizobj->view_url(),
            $output->access_messages($messages));
}

if ($accessmanager->is_preflight_check_required($currentattemptid)) {
    // Need to do some checks before allowing the user to continue.
    $mform = $accessmanager->get_preflight_check_form(
            $quizobj->start_attempt_url($page), $currentattemptid);

    if ($mform->is_cancelled()) {
        $accessmanager->back_to_view_page($output);

    } else if (!$mform->get_data()) {
        debug_trace('No preflight data. Presenting form');
        // Form not submitted successfully, re-display it and stop.
        $PAGE->set_url($quizobj->start_attempt_url($page));
        $PAGE->set_title($quizobj->get_quiz_name());
        $accessmanager->setup_attempt_page($PAGE);
        if (empty($quizobj->get_quiz()->showblocks)) {
            $PAGE->blocks->show_only_fake_blocks();
        }

        echo $output->start_attempt_page($quizobj, $mform);
        die();
    }
    // Pre-flight check passed. Data have been added to session
    $quiz = $quizobj->get_quiz();
    debug_trace('Receive preflight data '.$currentattemptid.' |C '.$SESSION->qa_constraints);
    $accessmanager->notify_preflight_check_passed($currentattemptid);
}
if ($currentattemptid) {
    if ($lastattempt->state == quiz_attempt::OVERDUE) {
        debug_trace("Redirecting to summary (overdue) ");
        redirect($quizobj->summary_url($lastattempt->id));
    } else {
        debug_trace("Redirecting on current $currentattemptid ");
        redirect($quizobj->attempt_url($currentattemptid, $page));
    }
}

// Delete any previous preview attempts belonging to this user.
quiz_delete_previews($quizobj->get_quiz(), $USER->id);

$quba = question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
$quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);

// Create the new attempt and initialize the question sessions
$timenow = time(); // Update time now, in case the server is running really slowly.

$attempt = quiz_create_attempt($quizobj, $attemptnumber, $lastattempt, $timenow, $quizobj->is_preview_user());

if (!($quizobj->get_quiz()->attemptonlast && $lastattempt)) {
    debug_trace("starting new attempt $attemptnumber ");
    $attempt = quiz_start_new_attempt_with_constraints($quizobj, $quba, $attempt, $attemptnumber, $timenow);
} else {
    debug_trace("building attempt ");
    $attempt = quiz_start_attempt_built_on_last($quba, $attempt, $lastattempt);
}

$transaction = $DB->start_delegated_transaction();

debug_trace("saving attempt ".print_r($attempt, true));
$attempt = quiz_attempt_save_started($quizobj, $quba, $attempt);

// CHANGE+
// Required by quizaccess_chooseconstraints plugin.
// This is a second chance when having a late $attempt id assignation.
$quiz = $quizobj->get_quiz();
if ($DB->get_field('qa_chooseconstraints_quiz', 'enabled', array('quizid' => $quiz->id))) {
    debug_trace("Saving constraints $attemptnumber $lastattempt ".print_r($attempt, true));
    quiz_get_save_constraints($attempt, $quiz);
}
// /CHANGE-

$transaction->allow_commit();

// Redirect to the attempt page.
redirect($quizobj->attempt_url($attempt->id, $page));
die;
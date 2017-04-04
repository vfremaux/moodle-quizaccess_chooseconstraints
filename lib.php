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
 * @package     quizaccess_chooseconstraints
 * @category    quizaccess
 * @author      Valery Fremaux <valery.fremaux@gmail.com>
 * @copyright   (C) 2010 onwards Valery Fremaux (http://www.mylearningfactory.com)
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

/*
 * Add a random question to the quiz at a given point.
 * @param object $quiz the quiz settings.
 * @param int $addonpage the page on which to add the question.
 * @param int $categoryid the question category to add the question from.
 * @param int $number the number of random questions to add.
 * @param bool $includesubcategories whether to include questoins from subcategories.
 */

require_once($CFG->dirroot.'/mod/quiz/locallib.php');
require_once($CFG->dirroot.'/question/type/randomconstrained/classes/bank/randomconstrained_question_loader.php');

function quiz_add_randomconstrained_questions($quiz, $addonpage, $number) {
    global $DB;

    /*
     * Find existing random questions in this category that are
     * not used by any quiz.
     */
    $sql = "
        SELECT
            q.id,
            q.qtype
        FROM
            {question} q
        WHERE
            qtype = 'randomconstrained' AND
            category = 1 AND
            NOT EXISTS (
                SELECT
                    *
                FROM
                    {quiz_slots}
                WHERE
                    questionid = q.id)
        ORDER BY id
    ";
    if ($existingquestions = $DB->get_records_sql($sql)) {
        // Take as many of these as needed.
        while (($existingquestion = array_shift($existingquestions)) && $number > 0) {
            quiz_add_quiz_question($existingquestion->id, $quiz, $addonpage);
            $number -= 1;
        }
    }

    if ($number <= 0) {
        return;
    }

    // More random questions are needed, create them.
    $cm = get_coursemodule_from_instance('quiz', $quiz->id);
    $modcontext = context_module::instance($cm->id);

    $defaultcategory = $DB->get_record('question_categories', array('contextid' => $modcontext->id, 'parent' => 0));

    for ($i = 0; $i < $number; $i += 1) {
        $form = new stdClass();
        $form->questiontext = array('text' => '0', 'format' => 0);
        $form->category = $defaultcategory->id;
        $form->defaultmark = 1;
        $form->hidden = 1;
        $form->stamp = make_unique_id_code(); // Set the unique code (not to be changed).
        $question = new stdClass();
        $question->category = $form->category;
        $question->qtype = 'randomconstrained';
        $question = question_bank::get_qtype('randomconstrained')->save_question($question, $form);
        if (!isset($question->id)) {
            print_error('cannotinsertrandomquestion', 'quiz');
        }
        quiz_add_quiz_question($question->id, $quiz, $addonpage);
    }
}

/**
 * get the subtree under choicerootcategory (recursive)
 */
function quiz_fetch_category_tree(&$quiz, $rootcatid, &$categories, $level = 0) {
    global $DB;

    $level ++;
    if ($subs = $DB->get_records('question_categories', array('parent' => $rootcatid), 'sortorder')) {
        foreach ($subs as $sub) {
            $categories[$sub->id] = $sub;
            if ($level < $quiz->choicedeepness) {
                userquiz_fetch_category_tree($quiz, $sub->id, $categories, $level);
            }
        }
    }
}

/**
 * Prepare and start a new attempt deleting the previous preview attempts.
 *
 * @param  quiz $quizobj quiz object
 * @param  int $attemptnumber the attempt number
 * @param  object $lastattempt last attempt object
 * @return object the new attempt
 * @since  Moodle 3.1
 */
function quiz_prepare_and_start_new_constrained_attempt(quiz $quizobj, $attemptnumber, $lastattempt) {
    global $DB, $USER;

    // Delete any previous preview attempts belonging to this user.
    quiz_delete_previews($quizobj->get_quiz(), $USER->id);

    $quba = question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
    $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);

    // Create the new attempt and initialize the question sessions
    $timenow = time(); // Update time now, in case the server is running really slowly.
    $attempt = quiz_create_attempt($quizobj, $attemptnumber, $lastattempt, $timenow, $quizobj->is_preview_user());

    if (!($quizobj->get_quiz()->attemptonlast && $lastattempt)) {
        $attempt = quiz_start_new_attempt_with_constraints($quizobj, $quba, $attempt, $attemptnumber, $timenow);
    } else {
        $attempt = quiz_start_attempt_built_on_last($quba, $attempt, $lastattempt);
    }

    $transaction = $DB->start_delegated_transaction();

    $attempt = quiz_attempt_save_started($quizobj, $quba, $attempt);

    $transaction->allow_commit();

    return $attempt;
}

/**
 * Start a normal, new, quiz attempt.
 *
 * @param quiz      $quizobj            the quiz object to start an attempt for.
 * @param question_usage_by_activity $quba
 * @param object    $attempt
 * @param integer   $attemptnumber      starting from 1
 * @param integer   $timenow            the attempt start time
 * @param array     $questionids        slot number => question id. Used for random questions, to force the choice
 *                                        of a particular actual question. Intended for testing purposes only.
 * @param array     $forcedvariantsbyslot slot number => variant. Used for questions with variants,
 *                                          to force the choice of a particular variant. Intended for testing
 *                                          purposes only.
 * @throws moodle_exception
 * @return object   modified attempt object
 */
function quiz_start_new_attempt_with_constraints($quizobj, $quba, $attempt, $attemptnumber, $timenow,
                                $questionids = array(), $forcedvariantsbyslot = array()) {
    global $SESSION, $DB;

    // Fully load all the questions in this quiz.
    $quizobj->preload_questions();
    $quizobj->load_questions();

    // Add them all to the $quba.
    $questionsinuse = array_keys($quizobj->get_questions());
    foreach ($quizobj->get_questions() as $questiondata) {
        if ($questiondata->qtype != 'random' && $questiondata->qtype != 'randomconstrained') {
            if (!$quizobj->get_quiz()->shuffleanswers) {
                $questiondata->options->shuffleanswers = false;
            }
            $question = question_bank::make_question($questiondata);

        } else {
            if (!isset($questionids[$quba->next_slot_number()])) {
                $forcequestionid = null;
            } else {
                $forcequestionid = $questionids[$quba->next_slot_number()];
            }

            if ($questiondata->qtype == 'randomconstrained') {
                $question = question_bank::get_qtype('randomcontrained')->choose_other_question(
                    $questiondata, $questionsinuse, $quizobj->get_quiz()->shuffleanswers, $forcequestionid);
            } else {
                $question = question_bank::get_qtype('random')->choose_other_question(
                    $questiondata, $questionsinuse, $quizobj->get_quiz()->shuffleanswers, $forcequestionid);
            }
            if (is_null($question)) {
                throw new moodle_exception('notenoughrandomquestions', 'quiz',
                                           $quizobj->view_url(), $questiondata);
            }
        }

        $quba->add_question($question, $questiondata->maxmark);
        $questionsinuse[] = $question->id;
    }

    // Start all the questions.
    if ($attempt->preview) {
        $variantoffset = rand(1, 100);
    } else {
        $variantoffset = $attemptnumber;
    }
    $variantstrategy = new question_variant_pseudorandom_no_repeats_strategy(
            $variantoffset, $attempt->userid, $quizobj->get_quizid());

    if (!empty($forcedvariantsbyslot)) {
        $forcedvariantsbyseed = question_variant_forced_choices_selection_strategy::prepare_forced_choices_array(
            $forcedvariantsbyslot, $quba);
        $variantstrategy = new question_variant_forced_choices_selection_strategy(
            $forcedvariantsbyseed, $variantstrategy);
    }

    $quba->start_all_questions($variantstrategy, $timenow);

    // Work out the attempt layout.
    $layout = array();
    if ($quizobj->get_quiz()->shufflequestions) {
        $slots = $quba->get_slots();
        shuffle($slots);

        $questionsonthispage = 0;
        foreach ($slots as $slot) {
            if ($questionsonthispage && $questionsonthispage == $quizobj->get_quiz()->questionsperpage) {
                $layout[] = 0;
                $questionsonthispage = 0;
            }
            $layout[] = $slot;
            $questionsonthispage += 1;
        }

    } else {
        $currentpage = null;
        foreach ($quizobj->get_questions() as $slot) {
            if ($currentpage !== null && $slot->page != $currentpage) {
                $layout[] = 0;
            }
            $layout[] = $slot->slot;
            $currentpage = $slot->page;
        }
    }

    $layout[] = 0;
    $attempt->layout = implode(',', $layout);

    return $attempt;
}

function quiz_get_save_constraints(&$attempt, &$quiz) {
    global $SESSION, $DB;

    if (!$oldrecord = $DB->get_record('qa_chooseconstraints_attempt', array('attemptid' => $attempt->id))) {
        $record = new StdClass;
        $record->attemptid = $attempt->id;
        $record->quizid = $quiz->id;
        $record->categories = $SESSION->qa_constraints;
        $DB->insert_record('qa_chooseconstraints_attempt', $record);
    } else {
        $SESSION->qa_constraints = $oldrecord->categories;
    }
}
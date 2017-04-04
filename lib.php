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

    // Usages for this user's previous quiz attempts.
    $qubaids = new \mod_quiz\question\qubaids_for_users_attempts(
            $quizobj->get_quizid(), $attempt->userid);

    // Fully load all the questions in this quiz.
    $quizobj->preload_questions();
    $quizobj->load_questions();

    $constraints = explode(',', $SESSION->qa_constraints);

    // First load all the non-random questions.
    $randomfound = false;
    $slot = 0;
    $questions = array();
    $maxmark = array();
    $page = array();
    foreach ($quizobj->get_questions() as $questiondata) {
        $slot += 1;
        $maxmark[$slot] = $questiondata->maxmark;
        $page[$slot] = $questiondata->page;
        if (($questiondata->qtype == 'random') || ($questiondata->qtype == 'randomconstrained')) {
            $randomfound = true;
            continue;
        }
        if (!$quizobj->get_quiz()->shuffleanswers) {
            $questiondata->options->shuffleanswers = false;
        }
        $questions[$slot] = question_bank::make_question($questiondata);
    }

    // Then find a question to go in place of each random question.
    if ($randomfound) {
        $slot = 0;
        $usedquestionids = array();
        foreach ($questions as $question) {
            if (isset($usedquestions[$question->id])) {
                $usedquestionids[$question->id] += 1;
            } else {
                $usedquestionids[$question->id] = 1;
            }
        }
        $randomloader = new \core_question\bank\random_question_loader($qubaids, $usedquestionids);
        $randomconstrainedloader = new \core_question\bank\randomconstrained_question_loader($qubaids, $constraints, $usedquestionids);

        foreach ($quizobj->get_questions() as $questiondata) {
            $slot += 1;

            // Discard question if not random.
            if (($questiondata->qtype != 'random') && ($questiondata->qtype != 'randomconstrained')) {
                continue;
            }

            // Deal with fixed random choices for testing.
            if (isset($questionids[$quba->next_slot_number()])) {
                if ($randomloader->is_question_available($questiondata->category,
                        (bool) $questiondata->questiontext, $questionids[$quba->next_slot_number()])) {
                    $questions[$slot] = question_bank::load_question(
                            $questionids[$quba->next_slot_number()], $quizobj->get_quiz()->shuffleanswers);
                    continue;
                } else {
                    throw new coding_exception('Forced question id not available.');
                }
            }

            // Normal case, pick one final question at random.

            // CHANGE+.
            if ($questiondata->qtype == 'randomconstrained') {
                // Special constrained.
                $choiceroot = $DB->get_field('qa_chooseconstraints_quiz', 'choicerootcategory', array('quizid' => $quizobj->get_quizid()));
                list($choicerootcategory, $context) = explode(',', $choiceroot);
                $questionid = $randomconstrainedloader->get_next_constrained_question_id($choicerootcategory);
            } else {
                // Other standard cases.
                $questionid = $randomloader->get_next_question_id($questiondata->category,
                            (bool) $questiondata->questiontext);
            }
            // CHANGE-.

            if ($questionid === null) {
                throw new moodle_exception('notenoughrandomquestions', 'quiz',
                                           $quizobj->view_url(), $questiondata);
            }

            $questions[$slot] = question_bank::load_question($questionid,
                    $quizobj->get_quiz()->shuffleanswers);
        }
    }

    // Finally add them all to the usage.
    ksort($questions);
    foreach ($questions as $slot => $question) {
        $newslot = $quba->add_question($question, $maxmark[$slot]);
        if ($newslot != $slot) {
            throw new coding_exception('Slot numbers have got confused.');
        }
    }

    // Start all the questions.
    $variantstrategy = new core_question\engine\variants\least_used_strategy($quba, $qubaids);

    if (!empty($forcedvariantsbyslot)) {
        $forcedvariantsbyseed = question_variant_forced_choices_selection_strategy::prepare_forced_choices_array(
            $forcedvariantsbyslot, $quba);
        $variantstrategy = new question_variant_forced_choices_selection_strategy(
            $forcedvariantsbyseed, $variantstrategy);
    }

    $quba->start_all_questions($variantstrategy, $timenow);

    // Work out the attempt layout.
    $sections = $quizobj->get_sections();
    foreach ($sections as $i => $section) {
        if (isset($sections[$i + 1])) {
            $sections[$i]->lastslot = $sections[$i + 1]->firstslot - 1;
        } else {
            $sections[$i]->lastslot = count($questions);
        }
    }

    $layout = array();
    foreach ($sections as $section) {
        if ($section->shufflequestions) {
            $questionsinthissection = array();
            for ($slot = $section->firstslot; $slot <= $section->lastslot; $slot += 1) {
                $questionsinthissection[] = $slot;
            }
            shuffle($questionsinthissection);
            $questionsonthispage = 0;
            foreach ($questionsinthissection as $slot) {
                if ($questionsonthispage && $questionsonthispage == $quizobj->get_quiz()->questionsperpage) {
                    $layout[] = 0;
                    $questionsonthispage = 0;
                }
                $layout[] = $slot;
                $questionsonthispage += 1;
            }

        } else {
            $currentpage = $page[$section->firstslot];
            for ($slot = $section->firstslot; $slot <= $section->lastslot; $slot += 1) {
                if ($currentpage !== null && $page[$slot] != $currentpage) {
                    $layout[] = 0;
                }
                $layout[] = $slot;
                $currentpage = $page[$slot];
            }
        }

        // Each section ends with a page break.
        $layout[] = 0;
    }
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
<?php

/**
 * Add a random question to the quiz at a given point.
 * @param object $quiz the quiz settings.
 * @param int $addonpage the page on which to add the question.
 * @param int $categoryid the question category to add the question from.
 * @param int $number the number of random questions to add.
 * @param bool $includesubcategories whether to include questoins from subcategories.
 */
function quiz_add_randomconstrained_questions($quiz, $addonpage, $number) {
    global $DB;

    // Find existing random questions in this category that are
    // not used by any quiz.
    if ($existingquestions = $DB->get_records_sql(
            "SELECT q.id, q.qtype FROM {question} q
            WHERE qtype = 'randomconstrained'
                AND category = 1
                AND NOT EXISTS (
                        SELECT *
                          FROM {quiz_slots}
                         WHERE questionid = q.id)
            ORDER BY id")) {
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
    for ($i = 0; $i < $number; $i += 1) {
        $form = new stdClass();
        $form->questiontext = array('text' => '0', 'format' => 0);
        $form->category = 1;
        $form->defaultmark = 1;
        $form->hidden = 1;
        $form->stamp = make_unique_id_code(); // Set the unique code (not to be changed).
        $question = new stdClass();
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

            $question = question_bank::get_qtype($questiondata->qtype)->choose_other_question(
                $questiondata, $questionsinuse, $quizobj->get_quiz()->shuffleanswers, $forcequestionid);
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
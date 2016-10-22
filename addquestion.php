<?php

require_once('../../../../config.php');
require_once($CFG->dirroot.'/mod/quiz/accessrule/chooseconstraints/lib.php');
require_once($CFG->dirroot . '/mod/quiz/editlib.php');
require_once($CFG->dirroot . '/question/category_class.php');

list($url, $contexts, $cmid, $cm, $quiz, $pagevars) =
        question_edit_setup('editq', '/mod/quiz/accessrule/chooseconstraints/addquestion.php', true);

require_capability('mod/quiz:manage', $contexts->lowest());

// Add random questions to the quiz.
$addonpage = optional_param('addonpage', 0, PARAM_INT);
$randomcount = required_param('randomcount', PARAM_INT);
quiz_add_randomconstrained_questions($quiz, $addonpage, $randomcount);

quiz_delete_previews($quiz);
quiz_update_sumgrades($quiz);

redirect(new moodle_url('/mod/quiz/edit.php', array('cmid' => $cmid)));
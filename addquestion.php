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
require('../../../../config.php');
require_once($CFG->dirroot.'/mod/quiz/accessrule/chooseconstraints/lib.php');
require_once($CFG->dirroot.'/mod/quiz/editlib.php');
require_once($CFG->dirroot.'/question/category_class.php');

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
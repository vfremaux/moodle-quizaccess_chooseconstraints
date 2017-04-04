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
 * @package   mod_quiz
 * @copyright 2016 Valery Fremaux
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../../config.php');
require_once($CFG->dirroot.'/mod/quiz/accessrule/chooseconstraints/lib.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');
require_once($CFG->dirroot . '/question/editlib.php');
require_once($CFG->dirroot . '/question/category_class.php');

$formurl = '/mod/quiz/accessrule/chooseconstraints/addrandomconstrained.php';
list($url, $contexts, $cmid, $cm, $quiz, $pagevars) = question_edit_setup('editq', $formurl, true);

require_capability('mod/quiz:manage', $contexts->lowest());

// Add random questions to the quiz.
$addonpage = optional_param('addonpage', 0, PARAM_INT);
$randomcount = optional_param('randomcount', 1, PARAM_INT);
quiz_add_randomconstrained_questions($quiz, $addonpage, $randomcount);

quiz_delete_previews($quiz);
quiz_update_sumgrades($quiz);

redirect(new moodle_url('/mod/quiz/edit.php', array('cmid' => $cmid)));
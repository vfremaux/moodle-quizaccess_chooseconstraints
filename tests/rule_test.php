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
 * Unit tests for the userquizaccess_password plugin.
 *
 * @package     quizaccess_chooseconstraints
 * @category    quizaccess
 * @author      Valery Fremaux <valery.fremaux@gmail.com>
 * @copyright   (C) 2010 onwards Valery Fremaux (http://www.mylearningfactory.com)
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/userquiz/accessrule/chooseconstraints/rule.php');

/**
 * Unit tests for the userquizaccess_chooseconstraints plugin.
 *
 * @copyright  2008 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class userquizaccess_chooseconstraints_testcase extends basic_testcase {

    public function test_chooseconstraints_access_rule() {
        $userquiz = new stdClass();
        $userquiz->questions = '';
        $cm = new stdClass();
        $cm->id = 0;
        $userquizobj = new userquiz($userquiz, $cm, null);
        $rule = new userquizaccess_chooseconstraints($userquizobj, 0);

        $attempt = new stdClass();
        $attempt->categoryconstraints = '';
        $this->assertTrue($rule->is_preflight_check_required($attempt));

        $attempt = new stdClass();
        $attempt->categoryconstraints = '1,2,3,4';
        $this->assertFalse($rule->is_preflight_check_required($attempt));
    }
}

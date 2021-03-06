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
 * Resource module version information
 *
 * @package    mod_resourceduedate
 * @copyright  2009 Petr Skoda  {@link http://skodak.org}, 2015 Test Valley School
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->dirroot.'/mod/resourceduedate/locallib.php');
require_once($CFG->libdir.'/completionlib.php');

$id       = optional_param('id', 0, PARAM_INT); // Course Module ID
$r        = optional_param('r', 0, PARAM_INT);  // Resource instance ID
$redirect = optional_param('redirect', 0, PARAM_BOOL);

if ($r) {
    if (!$resource = $DB->get_record('resourceduedate', array('id'=>$r))) {
	// no file uploaded, probably a file-less object
    }
    $cm = get_coursemodule_from_instance('resourceduedate', $resource->id, $resource->course, false, MUST_EXIST);

} else {
    if (!$cm = get_coursemodule_from_id('resourceduedate', $id)) {
	// no file uploaded, probably a file-less object
    }
    $resource = $DB->get_record('resourceduedate', array('id'=>$cm->instance), '*', MUST_EXIST);
}

$course = $DB->get_record('course', array('id'=>$cm->course), '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/resourceduedate:view', $context);

$params = array(
    'context' => $context,
    'objectid' => $resource->id
);
$event = \mod_resourceduedate\event\course_module_viewed::create($params);
$event->add_record_snapshot('course_modules', $cm);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('resourceduedate', $resource);
$event->trigger();

// Update 'viewed' state if required by completion system
$completion = new completion_info($course);
$completion->set_module_viewed($cm);

$PAGE->set_url('/mod/resourceduedate/view.php', array('id' => $cm->id));

if ($resource->tobemigrated) {
    resourceduedate_print_tobemigrated($resource, $cm, $course);
    die;
}

$fs = get_file_storage();
$files = $fs->get_area_files($context->id, 'mod_resourceduedate', 'content', 0, 'sortorder DESC, id ASC', false); // TODO: this is not very efficient!!
if (count($files) < 1) {
    resourceduedate_print_filenotfound($resource, $cm, $course);
    die;
} else {
    $file = reset($files);
    unset($files);
}

$resource->mainfile = $file->get_filename();
$displaytype = resourceduedate_get_final_display_type($resource);
if ($displaytype == RESOURCELIB_DISPLAY_OPEN || $displaytype == RESOURCELIB_DISPLAY_DOWNLOAD) {
    // For 'open' and 'download' links, we always redirect to the content - except
    // if the user just chose 'save and display' from the form then that would be
    // confusing
    if (!isset($_SERVER['HTTP_REFERER']) || strpos($_SERVER['HTTP_REFERER'], 'modedit.php') === false) {
        $redirect = true;
    }
}

if ($redirect) {
    // coming from course page or url index page
    // this redirect trick solves caching problems when tracking views ;-)
    $path = '/'.$context->id.'/mod_resourceduedate/content/'.$resource->revision.$file->get_filepath().$file->get_filename();
    $fullurl = moodle_url::make_file_url('/pluginfile.php', $path, $displaytype == RESOURCELIB_DISPLAY_DOWNLOAD);
    redirect($fullurl);
}

switch ($displaytype) {
    case RESOURCELIB_DISPLAY_EMBED:
        resourceduedate_display_embed($resource, $cm, $course, $file);
        break;
    case RESOURCELIB_DISPLAY_FRAME:
        resourceduedate_display_frame($resource, $cm, $course, $file);
        break;
    default:
        resourceduedate_print_workaround($resource, $cm, $course, $file);
        break;
}


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
 * @package    mod_resourceduedate
 * @copyright  2009 Petr Skoda  {@link http://skodak.org}, 2015 Test Valley School
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;


require_once( dirname( __FILE__ ) . '/locallib.php' );

/**
 * List of features supported in Resource module
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed True if module supports feature, false if not, null if doesn't know
 */
function resourceduedate_supports($feature) {
    switch($feature) {
        case FEATURE_MOD_ARCHETYPE:           return MOD_ARCHETYPE_RESOURCE;
        case FEATURE_GROUPS:                  return false;
        case FEATURE_GROUPINGS:               return false;
        case FEATURE_MOD_INTRO:               return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return true;
        case FEATURE_GRADE_HAS_GRADE:         return false;
        case FEATURE_GRADE_OUTCOMES:          return false;
        case FEATURE_BACKUP_MOODLE2:          return true;
        case FEATURE_SHOW_DESCRIPTION:        return true;

        default: return null;
    }
}

/**
 * Returns all other caps used in module
 * @return array
 */
function resourceduedate_get_extra_capabilities() {
    return array('moodle/site:accessallgroups');
}

/**
 * This function is used by the reset_course_userdata function in moodlelib.
 * @param $data the data submitted from the reset course.
 * @return array status array
 */
function resourceduedate_reset_userdata($data) {
    return array();
}

/**
 * List the actions that correspond to a view of this module.
 * This is used by the participation report.
 *
 * Note: This is not used by new logging system. Event with
 *       crud = 'r' and edulevel = LEVEL_PARTICIPATING will
 *       be considered as view action.
 *
 * @return array
 */
function resourceduedate_get_view_actions() {
    return array('view','view all');
}

/**
 * List the actions that correspond to a post of this module.
 * This is used by the participation report.
 *
 * Note: This is not used by new logging system. Event with
 *       crud = ('c' || 'u' || 'd') and edulevel = LEVEL_PARTICIPATING
 *       will be considered as post action.
 *
 * @return array
 */
function resourceduedate_get_post_actions() {
    return array('update', 'add');
}

/**
 * Add resource instance.
 * @param object $data
 * @param object $mform
 * @return int new resource instance id
 */
function resourceduedate_add_instance($data, $mform) {
    global $CFG, $DB;
    require_once("$CFG->libdir/resourcelib.php");
    require_once("$CFG->dirroot/mod/resourceduedate/locallib.php");
    require_once( $CFG->dirroot . '/calendar/lib.php');
    $cmid = $data->coursemodule;
    $data->timemodified = time();

    resourceduedate_set_display_options($data);

    $data->id = $DB->insert_record('resourceduedate', $data);

    // add course event to calendar
    

    // we need to use context now, so we need to make sure all needed info is already in db
    $DB->set_field('course_modules', 'instance', $data->id, array('id'=>$cmid));
    resourceduedate_set_mainfile($data);

    resourceduedate_update_calendar( $data->id, $cmid );

    return $data->id;
}

/**
 * Update resource instance.
 * @param object $data
 * @param object $mform
 * @return bool true
 */
function resourceduedate_update_instance($data, $mform) {
    global $CFG, $DB;
    require_once("$CFG->libdir/resourcelib.php");
    $data->timemodified = time();
    $data->id           = $data->instance;
    $data->revision++;

    resourceduedate_set_display_options($data);

    $DB->update_record('resourceduedate', $data);
    resourceduedate_set_mainfile($data);

    resourceduedate_update_calendar( $data->id, $data->coursemodule );

    return true;
}

/**
 * Updates display options based on form input.
 *
 * Shared code used by resourceduedate_add_instance and resourceduedate_update_instance.
 *
 * @param object $data Data object
 */
function resourceduedate_set_display_options($data) {
    $displayoptions = array();
    if ($data->display == RESOURCELIB_DISPLAY_POPUP) {
        $displayoptions['popupwidth']  = $data->popupwidth;
        $displayoptions['popupheight'] = $data->popupheight;
    }
    if (in_array($data->display, array(RESOURCELIB_DISPLAY_AUTO, RESOURCELIB_DISPLAY_EMBED, RESOURCELIB_DISPLAY_FRAME))) {
        $displayoptions['printintro']   = (int)!empty($data->printintro);
    }
    if (!empty($data->showsize)) {
        $displayoptions['showsize'] = 1;
    }
    if (!empty($data->showtype)) {
        $displayoptions['showtype'] = 1;
    }
    $data->displayoptions = serialize($displayoptions);
}

/**
 * Delete resource instance.
 * @param int $id
 * @return bool true
 */
function resourceduedate_delete_instance($id) {
    global $DB, $CFG;
    require_once( $CFG->dirroot . '/calendar/lib.php' );

    if (!$resource = $DB->get_record('resourceduedate', array('id'=>$id))) {
        return false;
    }

    // delete associated calendar event
    $DB->delete_records('event', array('modulename' => 'resourceduedate', 'instance' => $id ) );   

    // note: all context files are deleted automatically

    $DB->delete_records('resourceduedate', array('id'=>$resource->id));

    return true;
}

/**
 * Update the calendar entries for this resource due date item.
 * 
 * @param int $coursemoduleid - Required to pass this is because it might
 *                              not exist in the database yet.
 * @return bool
 */
function resourceduedate_update_calendar($id, $cmid) {
	global $DB, $CFG;
	require_once($CFG->dirroot.'/calendar/lib.php');
	require_once($CFG->dirroot.'/lib/filelib.php');

	// get instance
	$params = array( 'id' => $id );
	$instance = $DB->get_record( 'resourceduedate', $params, '*', MUST_EXIST );


	if ( ! $instance ) {
		throw new coding_exception( 'Unable to get the resourceduedate instance in order to update the calendar event attached to it.' );
	}

	if ( $instance->duedate ) {
		// determine the **set date** for when this object
		$set_date = $instance->timemodified;
		$set_date_formatted = userdate( $set_date, get_string( 'strftimedatefullshort' ) ); 

		// add the event for the **due date** of this resourceduedate item
		$due_event = new stdClass();

		$params = array( 'modulename' => 'resourceduedate', 'instance' => $instance->id, 'eventtype' => 'due' );
		$due_event->id = $DB->get_field('event', 'id', $params); // might return false, in which case we add it to the DB later

		$due_event->name = get_string( 'resourceduedate:date_due', 'resourceduedate' ) . $instance->name;
		$due_event->timestart = $instance->duedate;
		
		// first, a prefix in the event showing date set and date due
		$intro = html_writer::div( '<p><strong>' . get_string( 'resourceduedate:date_set', 'resourceduedate' ) . '</strong>'
			 . $set_date_formatted . '</p><p><strong>' . get_string( 'resourceduedate:date_due', 'resourceduedate' ) . '</strong>'
			 . userdate( $instance->duedate, get_string( 'strftimedatefullshort' ) ) . '</p>'
			, 'mod_resourceduedate_metadata' ); 

		// links to files
		$intro .= $instance->intro;

		// main 'description' content
		if ( $draftid = file_get_submitted_draft_itemid('introeditor')) {
			$intro = file_rewrite_urls_to_pluginfile($intro, $draftid);
		}

		$intro = strip_pluginfile_content($intro);

		// add manual link to the file
		$intro .= html_writer::div( html_writer::link(
				new moodle_url( '/mod/resourceduedate/view.php', array( 'id' => $cmid ) ),
				get_string( 'view_resourceduedate_link', 'resourceduedate' ),
				array('class' => 'mod_resourceduedate_file_link' )
			), 'mod_resourceduedate_file_link_container' );

		$due_event->description = array(
			'text'      =>  $intro,
			'format'    =>  $instance->introformat
		);

		if ( $due_event->id ) {
			$calevent = calendar_event::load($due_event->id);
			$calevent->update( $due_event );
		}
		else {
			unset( $due_event->id );
			$due_event->courseid     = $instance->course;
			$due_event->groupid      = 0;
			$due_event->userid       = 0;
			$due_event->modulename   = 'resourceduedate';
			$due_event->instance     = $instance->id;
			$due_event->eventtype    = 'due';
			$due_event->timeduration = 0;
			calendar_event::create( $due_event );
		}

		// add the event for the **set date** of this item
		$set_event = new stdClass();

		$params = array( 'modulename' => 'resourceduedate', 'instance' => $instance->id, 'eventtype' => 'set' );
		$set_event->id = $DB->get_field('event', 'id', $params); // might return false, in which case we add it to the DB later

		$set_event->name = get_string( 'resourceduedate:date_set', 'resourceduedate' ) . $instance->name;
		$set_event->timestart = $set_date;
		
		// we re-use the $intro above
		$set_event->description = array(
			'text'      =>  $intro,
			'format'    =>  $instance->introformat
		);

		if ( $set_event->id ) {
			$calevent = calendar_event::load($set_event->id);
			$calevent->update( $set_event );
		}
		else {
			unset( $set_event->id );
			$set_event->courseid     = $instance->course;
			$set_event->groupid      = 0;
			$set_event->userid       = 0;
			$set_event->modulename   = 'resourceduedate';
			$set_event->instance     = $instance->id;
			$set_event->eventtype    = 'set';
			$set_event->timeduration = 0;
			calendar_event::create( $set_event );
		}		

	}
	else {	// no due date, should not be possible
		throw new coding_exception( 'Could not get the due date on this instance of resourceduedate.' );
	}
	
}

/**
 * Given a course_module object, this function returns any
 * "extra" information that may be needed when printing
 * this activity in a course listing.
 *
 * See {@link get_array_of_activities()} in course/lib.php
 *
 * @param stdClass $coursemodule
 * @return cached_cm_info info
 */
function resourceduedate_get_coursemodule_info($coursemodule) {
    global $CFG, $DB;
    require_once("$CFG->libdir/filelib.php");
    require_once("$CFG->dirroot/mod/resource/locallib.php");
    require_once($CFG->libdir.'/completionlib.php');

    $context = context_module::instance($coursemodule->id);

    if (!$resource = $DB->get_record('resourceduedate', array('id'=>$coursemodule->instance),
            'id, name, display, displayoptions, tobemigrated, revision, intro, introformat')) {
        return NULL;
    }

    $info = new cached_cm_info();
    $info->name = $resource->name;
    if ($coursemodule->showdescription) {
        // Convert intro to html. Do not filter cached version, filters run at display time.
        $info->content = format_module_intro('resource', $resource, $coursemodule->id, false);
    }

    if ($resource->tobemigrated) {
        $info->icon ='i/invalid';
        return $info;
    }
    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'mod_resourceduedate', 'content', 0, 'sortorder DESC, id ASC', false); // TODO: this is not very efficient!!
    if (count($files) >= 1) {
        $mainfile = reset($files);
        //$info->icon = file_file_icon($mainfile, 24);
        $resource->mainfile = $mainfile->get_filename();
    }

    $display = resourceduedate_get_final_display_type($resource);

    if ($display == RESOURCELIB_DISPLAY_POPUP) {
        $fullurl = "$CFG->wwwroot/mod/resourceduedate/view.php?id=$coursemodule->id&amp;redirect=1";
        $options = empty($resource->displayoptions) ? array() : unserialize($resource->displayoptions);
        $width  = empty($options['popupwidth'])  ? 620 : $options['popupwidth'];
        $height = empty($options['popupheight']) ? 450 : $options['popupheight'];
        $wh = "width=$width,height=$height,toolbar=no,location=no,menubar=no,copyhistory=no,status=no,directories=no,scrollbars=yes,resizable=yes";
        $info->onclick = "window.open('$fullurl', '', '$wh'); return false;";

    } else if ($display == RESOURCELIB_DISPLAY_NEW) {
        $fullurl = "$CFG->wwwroot/mod/resourceduedate/view.php?id=$coursemodule->id&amp;redirect=1";
        $info->onclick = "window.open('$fullurl'); return false;";

    }

    // If any optional extra details are turned on, store in custom data
    $info->customdata = resourceduedate_get_optional_details($resource, $coursemodule);

    return $info;
}

/**
 * Called when viewing course page. Shows extra details after the link if
 * enabled.
 *
 * @param cm_info $cm Course module information
 */
function resourceduedate_cm_info_view(cm_info $cm) {
    $details = $cm->customdata;
    if ($details) {
        $cm->set_after_link(' ' . html_writer::tag('span', $details,
                array('class' => 'resourcelinkdetails')));
    }
}

/**
 * Lists all browsable file areas
 *
 * @package  mod_resource
 * @category files
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @return array
 */
function resourceduedate_get_file_areas($course, $cm, $context) {
    $areas = array();
    $areas['content'] = get_string('resourcecontent', 'resource');
    return $areas;
}

/**
 * File browsing support for resource module content area.
 *
 * @package  mod_resource
 * @category files
 * @param stdClass $browser file browser instance
 * @param stdClass $areas file areas
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @param string $filearea file area
 * @param int $itemid item ID
 * @param string $filepath file path
 * @param string $filename file name
 * @return file_info instance or null if not found
 */
function resourceduedate_get_file_info($browser, $areas, $course, $cm, $context, $filearea, $itemid, $filepath, $filename) {
    global $CFG;

    if (!has_capability('moodle/course:managefiles', $context)) {
        // students can not peak here!
        return null;
    }

    $fs = get_file_storage();

    if ($filearea === 'content') {
        $filepath = is_null($filepath) ? '/' : $filepath;
        $filename = is_null($filename) ? '.' : $filename;

        $urlbase = $CFG->wwwroot.'/pluginfile.php';
        if (!$storedfile = $fs->get_file($context->id, 'mod_resourceduedate', 'content', 0, $filepath, $filename)) {
            if ($filepath === '/' and $filename === '.') {
                $storedfile = new virtual_root_file($context->id, 'mod_resourceduedate', 'content', 0);
            } else {
                // not found
                return null;
            }
        }
        require_once("$CFG->dirroot/mod/resourceduedate/locallib.php");
        return new resourceduedate_content_file_info($browser, $context, $storedfile, $urlbase, $areas[$filearea], true, true, true, false);
    }

    // note: resourceduedate_intro handled in file_browser automatically

    return null;
}

/**
 * Serves the resource files.
 *
 * @package  mod_resource
 * @category files
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @param string $filearea file area
 * @param array $args extra arguments
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 * @return bool false if file not found, does not return if found - just send the file
 */
function resourceduedate_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options=array()) {
    global $CFG, $DB;
    require_once("$CFG->libdir/resourcelib.php");

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_course_login($course, true, $cm);
    if (!has_capability('mod/resource:view', $context)) {
        return false;
    }

    if ($filearea !== 'content') {
        // intro is handled automatically in pluginfile.php
        return false;
    }

    array_shift($args); // ignore revision - designed to prevent caching problems only

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = rtrim("/$context->id/mod_resourceduedate/$filearea/0/$relativepath", '/');
    do {
        if (!$file = $fs->get_file_by_hash(sha1($fullpath))) {
            if ($fs->get_file_by_hash(sha1("$fullpath/."))) {
                if ($file = $fs->get_file_by_hash(sha1("$fullpath/index.htm"))) {
                    break;
                }
                if ($file = $fs->get_file_by_hash(sha1("$fullpath/index.html"))) {
                    break;
                }
                if ($file = $fs->get_file_by_hash(sha1("$fullpath/Default.htm"))) {
                    break;
                }
            }
            $resource = $DB->get_record('resourceduedate', array('id'=>$cm->instance), 'id, legacyfiles', MUST_EXIST);
            if ($resource->legacyfiles != RESOURCELIB_LEGACYFILES_ACTIVE) {
                return false;
            }
            if (!$file = resourcelib_try_file_migration('/'.$relativepath, $cm->id, $cm->course, 'mod_resourceduedate', 'content', 0)) {
                return false;
            }
            // file migrate - update flag
            $resource->legacyfileslast = time();
            $DB->update_record('resourceduedate', $resource);
        }
    } while (false);

    // should we apply filters?
    $mimetype = $file->get_mimetype();
    if ($mimetype === 'text/html' or $mimetype === 'text/plain') {
        $filter = $DB->get_field('resourceduedate', 'filterfiles', array('id'=>$cm->instance));
        $CFG->embeddedsoforcelinktarget = true;
    } else {
        $filter = 0;
    }

    // finally send the file
    send_stored_file($file, null, $filter, $forcedownload, $options);
}

/**
 * Return a list of page types
 * @param string $pagetype current page type
 * @param stdClass $parentcontext Block's parent context
 * @param stdClass $currentcontext Current context of block
 */
function resourceduedate_page_type_list($pagetype, $parentcontext, $currentcontext) {
    $module_pagetype = array('mod-resourceduedate-*'=>get_string('page-mod-resourceduedate-x', 'resource'));
    return $module_pagetype;
}

/**
 * Export file resource contents
 *
 * @return array of file content
 */
function resourceduedate_export_contents($cm, $baseurl) {
    global $CFG, $DB;
    $contents = array();
    $context = context_module::instance($cm->id);
    $resource = $DB->get_record('resourceduedate', array('id'=>$cm->instance), '*', MUST_EXIST);

    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'mod_resourceduedate', 'content', 0, 'sortorder DESC, id ASC', false);

    foreach ($files as $fileinfo) {
        $file = array();
        $file['type'] = 'file';
        $file['filename']     = $fileinfo->get_filename();
        $file['filepath']     = $fileinfo->get_filepath();
        $file['filesize']     = $fileinfo->get_filesize();
        $file['fileurl']      = file_encode_url("$CFG->wwwroot/" . $baseurl, '/'.$context->id.'/mod_resourceduedate/content/'.$resource->revision.$fileinfo->get_filepath().$fileinfo->get_filename(), true);
        $file['timecreated']  = $fileinfo->get_timecreated();
        $file['timemodified'] = $fileinfo->get_timemodified();
        $file['sortorder']    = $fileinfo->get_sortorder();
        $file['userid']       = $fileinfo->get_userid();
        $file['author']       = $fileinfo->get_author();
        $file['license']      = $fileinfo->get_license();
        $contents[] = $file;
    }

    return $contents;
}

/**
 * Register the ability to handle drag and drop file uploads
 * @return array containing details of the files / types the mod can handle
 */
function resourceduedate_dndupload_register() {
    return array('files' => array(
                     array('extension' => '*', 'message' => get_string('dnduploadresource', 'mod_resourceduedate'))
                 ));
}

/**
 * Handle a file that has been uploaded
 * @param object $uploadinfo details of the file / content that has been uploaded
 * @return int instance id of the newly created mod
 */
function resourceduedate_dndupload_handle($uploadinfo) {
    // Gather the required info.
    $data = new stdClass();
    $data->course = $uploadinfo->course->id;
    $data->name = $uploadinfo->displayname;
    $data->intro = '';
    $data->introformat = FORMAT_HTML;
    $data->coursemodule = $uploadinfo->coursemodule;
    $data->files = $uploadinfo->draftitemid;

    // Set the display options to the site defaults.
    $config = get_config('resourceduedate');
    $data->display = $config->display;
    $data->popupheight = $config->popupheight;
    $data->popupwidth = $config->popupwidth;
    $data->printintro = $config->printintro;
    $data->showsize = (isset($config->showsize)) ? $config->showsize : 0;
    $data->showtype = (isset($config->showtype)) ? $config->showtype : 0;
    $data->filterfiles = $config->filterfiles;

    return resourceduedate_add_instance($data, null);
}

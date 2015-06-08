<?php

/**
 * Listens for Instant Payment Notification from zarinpal
 *
 * This script waits for Payment notification from zarinpal,
 * then double checks that data by sending it back to zarinpal.
 * If zarinpal verifies this then it sets up the enrolment for that
 * user.
 *
 * @package    enrol_zarinpal
 * @copyright 2015 Masoud Amini
 * @author     Masoud Amini - based on code by others
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require("../../config.php");
require_once("lib.php");
require_once($CFG->libdir . '/eventslib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->libdir . '/filelib.php');

$id = required_param('id', PARAM_INT);

/// Keep out casual intruders
if (empty($_GET['Authority']) || empty($_GET['Status'])) {
  print_error("Sorry, you can not use the script that way.");
}

$data = new stdClass();
$data->zp_au = $_GET['Authority'];
$data->zp_status = $_GET['Status'];
$data->zp_resnum = $_GET['ResNum'];

/// get the user and course records
if (!$transaction = $DB->get_record("enrol_zarinpal", array("id" => $data->zp_resnum))) {
  message_zarinpal_error_to_admin("Not a valid ResNum", $data);
  die;
}

if (!$user = $DB->get_record("user", array("id" => $transaction->userid))) {
  message_zarinpal_error_to_admin("Not a valid user id", $data);
  die;
}

if (!$course = $DB->get_record("course", array("id" => $transaction->courseid))) {
  message_zarinpal_error_to_admin("Not a valid course id", $data);
  die;
}

if (!$context = context_course::instance($course->id, IGNORE_MISSING)) {
  message_zarinpal_error_to_admin("Not a valid context id", $data);
  die;
}

if (!$plugin_instance = $DB->get_record("enrol", array("id" => $transaction->instanceid, "status" => 0))) {
  message_zarinpal_error_to_admin("Not a valid instance id", $data);
  die;
}

$plugin = enrol_get_plugin('zarinpal'); //here
print_r($data);
//die();
if ($data->zp_status == 'OK') {

  // ALL CLEAR !
  $transaction->reference_number = $data->zp_au;
  $transaction->transaction_state = $data->zp_status;
  $transaction->timeupdated = time();

  $DB->update_record("enrol_zarinpal", $transaction);

  // Check that amount paid is the correct amount
  if ((float) $plugin_instance->cost <= 0) {
    $cost = (float) $plugin->get_config('cost');
  } else {
    $cost = (float) $plugin_instance->cost;
  }

  // Use the same rounding of floats as on the enrol form.
  $cost = format_float($cost, 0, false);
  $cost = $cost /10 ;
print_r('cost: '.$cost);
  try {

	

		// URL also Can be https://ir.zarinpal.com/pg/services/WebGate/wsdl
		$client = new SoapClient('https://ir.zarinpal.com/pg/services/WebGate/wsdl', array('encoding' => 'UTF-8')); 
	
		$result = $client->PaymentVerification(
						  	array(
									'MerchantID'	 => $plugin->get_config('merchant_id'),
									'Authority' 	 => $transaction->reference_number,
									'Amount'	 => $cost
								)
		);
		print_r($result);
		if($result->Status >= 100){
			echo 'Transation success. RefID:'. $result->RefID;
			//return true ;
			
			echo'inji';
			  // Enrol user
  if ($plugin_instance->enrolperiod) {
    $timestart = time();
    $timeend = $timestart + $plugin_instance->enrolperiod;
  } else {
    $timestart = 0;
    $timeend = 0;
  }
  $plugin->enrol_user($plugin_instance, $user->id, $plugin_instance->roleid, $timestart, $timeend);
print_r($plugin_instance);
  // Start sending messages
  $coursecontext = context_course::instance($course->id, IGNORE_MISSING);

  // Pass $view=true to filter hidden caps if the user cannot see them
  if ($users = get_users_by_capability($context, 'moodle/course:update', 'u.*', 'u.id ASC', '', '', '', '', false, true)) {
    $users = sort_by_roleassignment_authority($users, $context);
    $teacher = array_shift($users);
  } else {
    $teacher = false;
  }

  $mailstudents = $plugin->get_config('mailstudents');
  $mailteachers = $plugin->get_config('mailteachers');
  $mailadmins = $plugin->get_config('mailadmins');
  $shortname = format_string($course->shortname, true, array('context' => $context));
  if (!empty($mailstudents)) {
    $a->coursename = format_string($course->fullname, true, array('context' => $coursecontext));
    $a->profileurl = "$CFG->wwwroot/user/view.php?id=$user->id";

    $eventdata = new stdClass();
    $eventdata->modulename = 'moodle';
    $eventdata->component = 'enrol_zarinpal';
    $eventdata->name = 'zarinpal_enrolment';
    $eventdata->userfrom = $teacher;
    $eventdata->userto = $user;
    $eventdata->subject = get_string("enrolmentnew", 'enrol', $shortname);
    $eventdata->fullmessage = get_string('welcometocoursetext', '', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml = '';
    $eventdata->smallmessage = '';
    message_send($eventdata);
  }

  if (!empty($mailteachers)) {
    $a->course = format_string($course->fullname, true, array('context' => $coursecontext));
    $a->user = fullname($user);

    $eventdata = new stdClass();
    $eventdata->modulename = 'moodle';
    $eventdata->component = 'enrol_zarinpal';
    $eventdata->name = 'zarinpal_enrolment';
    $eventdata->userfrom = $user;
    $eventdata->userto = $teacher;
    $eventdata->subject = get_string("enrolmentnew", 'enrol', $shortname);
    $eventdata->fullmessage = get_string('enrolmentnewuser', 'enrol', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml = '';
    $eventdata->smallmessage = '';
    message_send($eventdata);
  }

  if (!empty($mailadmins)) {
    $a->course = format_string($course->fullname, true, array('context' => $coursecontext));
    $a->user = fullname($user);
    $admins = get_admins();
    foreach ($admins as $admin) {
      $eventdata = new stdClass();
      $eventdata->modulename = 'moodle';
      $eventdata->component = 'enrol_zarinpal';
      $eventdata->name = 'zarinpal_enrolment';
      $eventdata->userfrom = $user;
      $eventdata->userto = $admin;
      $eventdata->subject = get_string("enrolmentnew", 'enrol', $shortname);
      $eventdata->fullmessage = get_string('enrolmentnewuser', 'enrol', $a);
      $eventdata->fullmessageformat = FORMAT_PLAIN;
      $eventdata->fullmessagehtml = '';
      $eventdata->smallmessage = '';
      message_send($eventdata);
    }
  }

  if (!empty($SESSION->wantsurl)) {
    $destination = $SESSION->wantsurl;
    unset($SESSION->wantsurl);
  } else {
    $destination = "$CFG->wwwroot/course/view.php?id=$course->id";
  }

  $fullname = format_string($course->fullname, true, array('context' => $context));
  redirect($destination, get_string('paymentthanks', '', $fullname));
			
			
			
			
		} else {
			message_zarinpal_error_to_admin("Error code: $result->Status", $result->Status);
			die();
			//echo 'Transation failed. Status:'. $result->Status;
		}



  } catch (Exception $ex) {
	  print_r($ex);
	  //die();
    message_zarinpal_error_to_admin("Amount paid is not enough ($result < $cost))", $result->Status);
    die;
  }


} else {
  if (!empty($SESSION->wantsurl)) {
    $destination = $SESSION->wantsurl;
    unset($SESSION->wantsurl);
  } else {
    $destination = "$CFG->wwwroot/course/view.php?id=$course->id";
  }

  $fullname = format_string($course->fullname, true, array('context' => $context));
  $PAGE->set_url($destination);
  echo $OUTPUT->header();
  $a = new stdClass();
  $a->teacher = get_string('defaultcourseteacher');
  $a->fullname = $fullname;
  notice(get_string('paymentsorry', '', $a), $destination);
}

function message_zarinpal_error_to_admin($subject, $data) {
  echo $subject;
  $admin = get_admin();
  $site = get_site();

  $message = "$site->fullname:  Transaction failed.\n\n$subject\n\n";

  foreach ($data as $key => $value) {
    $message .= "$key => $value\n";
  }

  $eventdata = new stdClass();
  $eventdata->modulename = 'moodle';
  $eventdata->component = 'enrol_zarinpal';
  $eventdata->name = 'zarinpal_enrolment';
  $eventdata->userfrom = $admin;
  $eventdata->userto = $admin;
  $eventdata->subject = "PAYPAL ERROR: " . $subject;
  $eventdata->fullmessage = $message;
  $eventdata->fullmessageformat = FORMAT_PLAIN;
  $eventdata->fullmessagehtml = '';
  $eventdata->smallmessage = '';
  message_send($eventdata);
}
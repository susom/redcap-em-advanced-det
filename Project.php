<?php
namespace Stanford\AdvancedDET;
/** @var \Stanford\AdvancedDET\AdvancedDET $module */

/**
 * This is the MASS DET page adopted from an old plugin
 */

use \REDCap;

# Render Table Page
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';


##### VALIDATION #####
# Make sure user has permissions for project or is a super user
$these_rights = REDCap::getUserRights(USERID);
$my_rights = $these_rights[USERID];
if (!$my_rights['design'] && !SUPER_USER) {
	showError('Project Setup rights are required to access MASS DET plugin.');
	exit;
}

# Make sure the user's rights have not expired for the project
if ($my_rights['expiration'] != "" && $my_rights['expiration'] < TODAY) {
	showError('Your user account has expired for this project.  Please contact the project admin.');
	exit;
}



// Get the current DET
global $data_entry_trigger_url;

// Get the current Instruments
$instrument_names = REDCap::getInstrumentNames();

// Get the current Events
$events = REDCap::getEventNames(true);

// Get all records (across all arms/events) and DAGs
$record_data = REDCap::getData('array',NULL,REDCap::getRecordIdField(),NULL,NULL,FALSE,TRUE);
$records = array_keys($record_data);


// Are DAGs enabled (check for presence of 'redcap_data_access_group' on the first record)
// $first_record = reset($record_data);
// $first_event = reset($first_record);
// $dag_enabled = isset($first_event['redcap_data_access_group']);
// if ($dag_enabled) {
//     $first_event                             = reset($record_data[$record]);
//     $this_params['redcap_data_access_group'] = $first_event['redcap_data_access_group'];
// }



// Get Inputs
$det                    = isset($_POST['det'])                  ? $_POST['det']                 : "";
$instrument             = isset($_POST['instrument'])           ? $_POST['instrument']          : "";
$instrument_complete    = isset($_POST['instrument_complete'])  ? $_POST['instrument_complete'] : "0";
$event_id               = isset($_POST['event_id'])             ? $_POST['event_id']               : '';
// $custom_params          = isset($_POST['custom_params'])     ? $_POST['custom_params']       : '';

$action                 = isset($_POST['action'])               ? $_POST['action']              : '';
$post_records           = isset($_POST['records'])              ? $_POST['records']             : [];

// Handle Post
if ($action == "run") {

    $module->emDebug($_POST);

    if (empty($records)) {
        showError("No Records");
    }

	// Filter out any invalid records
	$record_list = array_intersect($post_records,$records);

    $instances = $module->getSubSettings('dets');
    $module->emDebug($instances);
    $urls = [];

    if ($det == "__all__") {
        foreach ($instances as $i => $instance) {
            if (!$instance['disabled']) $urls[] = $instance['url'];
        }
        if (!empty($data_entry_trigger_url)) $urls[] = $data_entry_trigger_url;
    } elseif ($det == "__data_entry_trigger_url__") {
        if (!empty($data_entry_trigger_url)) $urls[] = $data_entry_trigger_url;
    } elseif (isset($instances[$det])) {
        $instance = $instances[$det];
        if (!$instance['disabled']) $urls[] = $instance['url'];
    }

    $module->emDebug("URLS", $urls);

    foreach ($urls as $url) {
        foreach ($record_list as $record) {

            // TODO: Get repeat instances and loop
            $repeat_instance = null;

            // TODO: Figure out how to handle DAGs
            $group_id = "";

            $payload = $module->getPayload($module->getProjectId(), $record, $instrument, $event_id, $group_id, $repeat_instance);

            $result = $module->callDet($url, $payload);
            // $result = "test";
            $module->emDebug($result, $payload, $url);

            print "<div class='det'>$record: Calling $url...  " . json_encode($result) . "</div>";
        }
    }
}



/** HANDLE NORMAL PAGE RENDER **/


// Render Page
$cbx_array = array();

foreach ($records as $record) {
	$cbx_array[] = "<div class='item'><label><input type='checkbox' name='records[]' value='$record' " .
		(in_array($record, $post_records) ? "checked" : "") .
		"> $record</label></div>";
}

$instrument_options = array();
foreach ($instrument_names as $k => $v) {
	$instrument_options[] = "<option value='$k'" .
		($k == $instrument ? " selected='selected'":"") .
		">$v</option>";
}

$instrument_complete_options = array();
foreach (array(0 => "Incomplete", 1 => "Unverified", 2 => "Complete") as $k => $v) {
	$instrument_complete_options[] = "<option value='$k'" .
		($k == $instrument_complete ? " selected='selected'":"") .
		">$v</option>";
}

$event_options = array();
foreach ($events as $event_id => $event_name) {
    $event_options[] = "<option value='$event_id'>$event_name ($event_id)</option>";
}

$det_options = array();
$instances = $module->getSubSettings('dets');
foreach ($instances as $i => $det) {
    if ($det['disabled']) continue;
    $det_options[] = "<option value='$i'>{$det['title']}: {$det['url']}</option>";
}
if (!empty($data_entry_trigger_url)) {
    $det_options[] = "<option value='__data_entry_trigger_url__'>$data_entry_trigger_url</option>";
}
if (!empty($det_options)) {
    $det_options[] = "<option value='__all__'>ALL URLS</option>";
}


?>

<div class="col-12">

    <h4>Mass DET: Use this page to simulate a DET for a set of records with a set of input parameters:</h4>

    <form method='POST'>

        <div class="form-group">
            <label for="instrument">Select Instrument</label>
            <select class="form-control" name='instrument'><?php echo implode('',$instrument_options) ?></select>
            <small id="instrumentHelp" class="form-text text-muted">The DET will send as though this was the form just saved.</small>
        </div>

        <div class="form-group">
            <label for="instrument_complete">Select Instrument Status</label>
            <select class="form-control" name='instrument_complete'><?php echo implode('',$instrument_complete_options) ?></select>
            <small id="instrument_completeHelp" class="form-text text-muted">The DET will send as though this was the instrument just saved.</small>
        </div>

    <?php if (!empty($event_options)) { ?>
        <div class="form-group">
            <label for="event_id">Select Event</label>
            <select class="form-control" name='event_id'><?php echo implode('',$event_options) ?></select>
            <small id="event_idHelp" class="form-text text-muted">The DET will send as though this was the event just saved.</small>
        </div>
    <?php } ?>

    <?php if (!empty($det_options)) { ?>
        <div class="form-group">
            <label for="det">Select DET</label>
            <select class="form-control" name='det'><?php echo implode('',$det_options) ?></select>
            <small id="detHelp" class="form-text text-muted">Select which DETs you wish to run.</small>
        </div>
    <?php } ?>

    <div class="card">
        <div class="card-body">
            <h5 class="card-title">Select Records to run against DET</h5>
            <a href="#" class="card-link" data-choice="all">All</a>
            <a href="#" class="card-link" data-choice="none">None</a>
            <a href="#" class="card-link" data-choice="custom">Custom</a>
            <div class="wrapper">
                <div class="d-flex flex-wrap">
                    <?php print implode("",$cbx_array) ?>
                </div>
            </div>
        </div>
        <div class="card-footer">
            <button type="submit" name="action" value="run" class="btn btn-primaryrc">Run Mass DET</button>
        </div>
    </div>
<!--        <fieldset>-->
<!--            <legend>-->
<!--                Select Records to run DET against-->
<!--                <span data-choice='all' class='sel jqbutton'/>All</span>-->
<!--                <span data-choice='none' class='sel jqbutton'/>None</span>-->
<!--                <span data-choice='custom' class='customList jqbutton'/>Custom List</span>-->
<!--            </legend>-->
<!--            <br/>-->
<!--        </fieldset>-->
<!--        <br/>-->
<!--        <input type='submit' name='Run' class='jqbutton'/>-->
    </form>
</div>

<style>
	/*button.sel {margin: 0px 10px; font-weight:bold; padding: 5px;}*/
	/*legend {font-weight: bold; font-size:larger;}*/
	/*fieldset {padding: 5px; max-width: 600px;}*/
	/*input.url { width: 600px;}*/
	/*form div {padding-bottom: 10px;}*/
	/*div.det_results {background: rgb(247,248,249); color: #333; padding:10px;}*/
	.wrapper {overflow:auto; max-height: 300px;}
	/*.wrapper ul li {float:left; width: */<?php //echo $max_length ?>/*em; display:inline-block;}*/
	/*.wrapper br {clear:left;}*/
	/*.wrapper {margin-bottom: 1em;}*/
	.cr {width: 100%; height: 200px; overflow:auto;}
    .item {
        padding-right: 1rem; padding-top: 2px;
        /*flex-basis:0;*/
        /*flex-grow:5;*/
    }
</style>
<script type='text/javascript'>
	$(document).ready( function() {
        $('.card-link').on('click', function () {
            var choice = $(this).data('choice');

            if (choice === 'all') {
                $('input[name="records[]"]').prop('checked', true);
            }
            if (choice === 'none') {
                $('input[name="records[]"]').prop('checked', false);
            }
            if (choice === 'custom') {
                openCustomList();
            }
            return false;
        });
    });

    openCustomList = function() {
        // Open up a pop-up with a list
        var data = "<p>Enter a comma-separated or return-separated list of record ids to select</p>" +
            "<textarea class='cr' name='custom_records' placeholder='Enter a comma-separated list of record_ids'></textarea>";
			initDialog("custom_records_dialog", data);
			$('#custom_records_dialog').dialog({ bgiframe: true, title: 'Enter Custom Record List',
				modal: true, width: 650,
				buttons: {
					Close: function() {  },
					Apply: function() {
						// Parse out contents
						var list = $('#custom_records_dialog textarea').val();
						var items = $.map(list.split(/\n|,/), $.trim);
						$(items).each(function(i, e) {
							console.log (i, e);
							$('input[value="' + e + '"]').prop('checked',true);
						});
						$(this).dialog('close');
					}
				}
			});
    };

</script>

<?php

//Display the project footer
require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';



#display an error from scratch
function showError($msg) {
	$HtmlPage = new HtmlPage();
	$HtmlPage->PrintHeaderExt();
	echo "<div class='red'>$msg</div>";
	//Display the project footer
	require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
}

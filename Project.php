<?php
namespace Stanford\AdvancedDET;
/** @var \Stanford\AdvancedDET\AdvancedDET $module */

/**
 * This is the MASS DET page adopted from an old plugin and updated to handle repeaeting forms/instances (which,
 * is not trivial!)
 */

use \REDCap;

# Render Table Page
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

##### ACCESS VALIDATION #####
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


// CONFIGURE VARIABLES
global $Proj, $data_entry_trigger_url;

// Get the current Instruments
$allInstruments = REDCap::getInstrumentNames();

// Get the current Events
$allEvents = REDCap::getEventNames(true);

$rights = REDCap::getUserRights(USERID);
$group_id = $rights[USERID]['group_id'];
// $group_name = REDCap::getGroupNames(true,$group_id);


// Get Inputs
$action              = isset($_POST['action']) ? $_POST['action'] : '';
$det                 = isset($_POST['det']) ? $_POST['det'] : "";
$event_id            = isset($_POST['event_id']) ? $_POST['event_id'] : '';
$instrument          = isset($_POST['instrument']) ? $_POST['instrument'] : "";
$instrument_complete = isset($_POST['instrument_complete']) ? $_POST['instrument_complete'] : "0";
$post_records        = isset($_POST['records']) ? $_POST['records'] : [];
$output = [];   // Track output to display under form...
$errors = [];


// HANDLE POSTS
if (!empty($_POST)) {

    // DECIDE ACTION
    if ($action == "run") {
        // $module->emDebug($_POST);
        if (empty($post_records)) $errors[] = "No Records Selected";

        // GET EVENT
        if (empty($event_id)) {
            if ($Proj->longitudinal) {
                $errors[] = "Must select a valid event id";
            } else {
                $event_id = $Proj->firstEventId();
            }
        }

        // VERIFY INSTRUMENT
        if (!array_key_exists($instrument, $allInstruments)) $errors[] = "$instrument is invalid";

        // SEE IF INSTRUMENT IS ENABLED IN EVENT
        if ($Proj->longitudinal) {
            // $module->emDebug($Proj->eventsForms[$event_id]);
            if (!in_array($instrument, $Proj->eventsForms[$event_id])) {
                $errors[] = "Instrument $instrument is not enabled in event $event_id";
            }
        }

        // GET DET URLS
        $detUrls   = [];
        $instances = $module->getSubSettings('dets');
        if ($det == "__all__") {
            foreach ($instances as $i => $instance) {
                if (!$instance['disabled']) $detUrls[] = $instance['url'];
            }
            if (!empty($data_entry_trigger_url)) $detUrls[] = $data_entry_trigger_url;
        } elseif ($det == "__data_entry_trigger_url__") {
            if (!empty($data_entry_trigger_url)) $detUrls[] = $data_entry_trigger_url;
        } elseif (isset($instances[$det])) {
            $instance = $instances[$det];
            if (!$instance['disabled']) $detUrls[] = $instance['url'];
        }
        // $module->emDebug("DET URLS", $detUrls);

        //TODO: Handle groups
        $group_id = null;


        // GET RECORDS
        $record_data = REDCap::getData('array', $post_records, array(REDCap::getRecordIdField(), $instrument . "_complete"));
        // $module->emDebug("POST RECORDS: ", json_encode(array_keys($record_data));
        // $module->emDebug("POST RECORDS: ", $record_data);

        if (empty($errors)) {
            foreach ($detUrls as $detUrl) {
                $payloads = $module->getDetPayloadArray(array_keys($record_data),$instrument,$event_id, $group_id, null, $record_data, $instrument_complete);

                $module->emDebug("PAYLOADS", $payloads);
                foreach ($payloads as $i=>$payload) {
                    $result   = $module->callDet($detUrl, $payload);
                    $output[] = "DET executed for record {$payload['record']}" .
                        (!empty($payload['redcap_repeat_instance']) ? ", instance {$payload['redcap_repeat_instance']}" : "") .
                        " from $instrument to $detUrl.";
                }
            }
            if (empty($output)) $errors[] = "None of the specified records had data for the specified event and instrument";
            if (!empty($output)) REDCap::logEvent("Advanced DET Executed",implode("\n",$output));
        }
    } else {
        $errors[] = "Invalid action: $action";
    }

} // END OF POST

/** HANDLE NORMAL PAGE RENDER **/


// Get all records (across all arms/events) and DAGs
$record_data    = REDCap::getData('array',NULL,REDCap::getRecordIdField(),NULL,$group_id,FALSE,TRUE);
$all_record_ids = array_keys($record_data);

// BUILD FORM OPTIONS
$event_options = array();
foreach ($allEvents as $this_event_id => $event_name) {
    $event_options[] = "<option value='$this_event_id'" .
        ($event_id == $this_event_id ? " selected='selected'":"") .
        ">$event_name ($this_event_id)</option>";
}

$instrument_options = array();
foreach ($allInstruments as $k => $v) {
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

$recordCheckboxes = array();
foreach ($all_record_ids as $record) {
	$recordCheckboxes[] = "<div class='item'><label><input type='checkbox' name='records[]' value='$record' " .
		(in_array($record, $post_records) ? "checked" : "") .
		"> $record</label></div>";
}

?>

<div class="col-12">



    <form method='POST'>

    <div class="card">
        <div class="card-header">
            <h5>Mass DET Configuration</h5>
            <p>
                Use this page to replay the selected DET.  This is useful when your DET logic may have changed
                and you need to refresh existing data with the new DET behavior.
            </p>
        </div>

        <div class="card-body">

        <?php
            if (!empty($errors)) echo "<div class='alert alert-danger'><h6>Errors during execution</h6><ul><li>" .
                implode("</li><li>",$errors) . "</li></ul></div>";
        ?>

        <?php if (!empty($event_options)) { ?>
            <div class="form-group">
                <label class="col-form-label" for="event_id">Select Event</label>
                <select class="form-control form-control-sm" name='event_id'><?php echo implode('',$event_options) ?></select>
                <small id="event_idHelp" class="form-text text-muted">The DET will send as though this was the event just saved.</small>
            </div>
        <?php } ?>

            <div class="form-group">
                <label class="col-form-label" for="instrument">Select Instrument</label>
                <select class="form-control form-control-sm" name='instrument'><?php echo implode('',$instrument_options) ?></select>
                <small id="instrumentHelp" class="form-text text-muted">The DET will send as though this was the form just saved.</small>
            </div>

            <div class="form-group">
                <label class="col-form-label" for="instrument_complete">Select Instrument Status</label>
                <select class="form-control form-control-sm" name='instrument_complete'><?php echo implode('',$instrument_complete_options) ?></select>
                <small id="instrument_completeHelp" class="form-text text-muted">The DET will send as though this was the instrument just saved.</small>
            </div>


        <?php if (!empty($det_options)) { ?>
            <div class="form-group">
                <label class="col-form-label" for="det">Select DET</label>
                <select class="form-control form-control-sm" name='det'><?php echo implode('',$det_options) ?></select>
                <small id="detHelp" class="form-text text-muted">Select which DETs you wish to run.</small>
            </div>
        <?php } ?>

            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Select Records to run against DET</h5>
                    <a href="#" class="card-link" data-choice="all">All</a>
                    <a href="#" class="card-link" data-choice="none">None</a>
                    <a href="#" class="card-link" data-choice="custom">Custom</a>
                    <hr>
                    <div class="wrapper">
                        <div class="d-flex flex-wrap">
                            <?php print implode("",$recordCheckboxes) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-footer">
            <button type="submit" name="action" value="run" class="btn btn-primaryrc">Run Mass DET</button>
        </div>
    </div>
    <?php
        if (!empty($output)) {
            echo "<div class='output mt-3'><pre class='alert-success'>" . implode("\n", $output) . "</pre></div>";
        }

    ?>
    </form>
</div>

<style>
	.wrapper {overflow:auto; max-height: 300px;}
	.cr {width: 100%; height: 200px; overflow:auto;}
    .item {
        padding-right: 1rem; padding-top: 2px;
    }
    .output {max-height: 300px;}
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
    if (! headers_sent() ) {
        $HtmlPage = new \HtmlPage();
        $HtmlPage->PrintHeaderExt();
    }
	echo "<div class='red'>$msg</div>";
	//Display the project footer
	require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
}

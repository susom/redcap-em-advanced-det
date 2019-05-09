<?php

namespace Stanford\AdvancedDET;

include_once("emLoggerTrait.php");

use \REDCap;

class AdvancedDET extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;


    /**
     * SAVE RECORD HOOK THAT EXECUTES THE DETS
     * @param $project_id
     * @param $record
     * @param $instrument
     * @param $event_id
     * @param $group_id
     * @param $survey_hash
     * @param $response_id
     * @param $repeat_instance
     */
    function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {

        // Load all DET configs
        $detInstances = $this->getEnabledDets();
        $results = array();

        foreach ($detInstances as $i => $det) {
            $url   = $det['url'];
            $title = $det['title'];
            $logic = $det['logic'];

            if (empty($url)) {
                $this->emDebug("$i: URL missing");
                $results[$i] = "URL missing";
                continue;
            }

            if (!empty($logic)) {
                $result = REDCap::evaluateLogic($logic, $project_id, $record, $event_id, $repeat_instance);

                if (is_null($result)) {
                    $this->emDebug("$i: Error in logic for $title", $logic);
                    $results[$i] = "Error in logic!";
                    continue;
                }

                if ($result === false) {
                    $this->emDebug("$i: Logic is false for $title", $logic);
                    $results[$i] = "Logic false";
                    continue;
                }
            }

            $payloads = $this->getDetPayloadArray($record, $instrument, $event_id, $group_id, $repeat_instance);
            if(count($payloads) > 1) {
                $this->emError("Got more than one payload from a save_Record hook - this shouldn't happen:", $payloads, $record, $instrument, $event_id, $repeat_instance);
            }

            foreach($payloads as $j => $payload) {
                $response = $this->callDet($url, $payload);
                $results[$i][$j] = !!$response;
            }

            // Return boolean for success
        } //end loop

        $this->emDebug("SAVE RESULTS: " . json_encode($results, JSON_FORCE_OBJECT));
    }


    /**
     * Call the DET based on hte specified payload
     *
     * @param     $url
     * @param     $payload
     * @param int $timeout
     * @return string
     */
    function callDet($url, $payload, $timeout=10) {
        // Set timeout value for http request

        // If $data_entry_trigger_url is a relative URL, then prepend with server domain
        $pre_url = "";
        if (substr($url, 0, 1) == "/") {
            $pre_url = (SSL ? "https://" : "http://") . SERVER_NAME;
        }

        // Send Post request
        $response = http_post($pre_url . $url, $payload, $timeout);

        $this->emDebug($pre_url . $url, $payload, $response);

        return $response;
    }


    /**
     * Get all enabled Advanced DETs
     * @return array
     */
    function getEnabledDets() {
        // Load all DET configs that are not disabled
        $detInstances = $this->getSubSettings("dets");
        $results = array();
        foreach ($detInstances as $i => $det) {
            if (!$det['disabled']) $results[] = $det;
        }
        return $results;
    }


    /**
     * Parse out the array of instances (or one if not repeating) along with the insturment status.
     * If you call this with record data, it will use it, otherwise it will call for data itself on
     * the supplied record.
     *
     * @param      $record_id
     * @param      $instrument
     * @param      $event_id
     * @param null $record_data*
     * @return array containing a subset of the payload with one entry per instance and the current instrument_complete value
     */
    function getFormInstanceCompleteArray($record_id, $instrument, $event_id, $repeat_instance = null, $record_data = null) {
        // If record_data is passed in, then
        if (is_null($record_data)) {
            // Get the data for just this record
            $record_data = REDCap::getData('array', array($record_id), array(REDCap::getRecordIdField(), $instrument . "_complete"), array($event_id));
        }

        $record = @$record_data[$record_id];
        $icf = $instrument . "_complete";       // Instrument Complete Field


        $results = array();     // Initialize the results array
        $instances = array();   // Initialize an empty array
        $repeatType = $this->getRepeatInstrumentType($instrument,$event_id);
        switch($repeatType) {
            case "form":
                $instances = @$record["repeat_instances"][$event_id][$instrument];
                break;
            case "instrument":
                $instances = @$record["repeat_instances"][$event_id][""];
                break;
            case "none":
                // Check if the form_status is defined (meaning there is a record on this event)
                $form_status = @$record[$event_id][$icf];
                if (is_numeric($form_status)) {
                    $results[] = [
                        $icf => $form_status
                    ];
                }
                break;
        }

        if (!empty($instances)) {
            foreach ($instances as $instance_id => $instance_data) {
                // If we are running on save we only want to call the det for the instance specified if repeating
                if (empty($repeat_instance) || $repeat_instance == $instance_id) {
                    $result = [
                        "redcap_repeat_instance" => $instance_id,
                        $icf                     => $instance_data[$icf]
                    ];
                    if ($repeatType == "form") $result["redcap_repeat_instrument"] = $instrument;
                    $results[] = $result;
                }
            }
        }
        $this->emDebug("getFormInstanceCompleteArray results for $record_id - $instrument - $event_id", $results);
        return $results;
    }


    /**
     * A helper to distinguish between repeat none, forms, or events
     * @param $instrument
     * @return string "none", "form", "event"
     */
    function getRepeatInstrumentType($instrument, $event_id) {
        global $Proj;
        if ($Proj->isRepeatingForm($event_id, $instrument)) {
            return "form";
        } elseif ($Proj->isRepeatingEvent($event_id)) {
            return "event";
        } else {
            return "none";
        }
    }


    /**
     * Return array of DET payloads (for each record/instance combination)
     * @param string $record                record id or array of record ids
     * @param string $instrument
     * @param int    $event_id
     * @param null   $group_id
     * @param null   $repeat_instance
     * @param null   $record_data           For batch, supply, otherwise will be fetched automatically
     * @param null   $instrument_complete   Leave null to use existing value, otherwise set to value from this arg
     * @return array
     */
    function getDetPayloadArray($record, $instrument, $event_id, $group_id = null, $repeat_instance = null, $record_data = null, $instrument_complete = null) {
        global $Proj;
        $results = array();

        // Make record into an array if not passed in as such
        $records = is_array($record) ? $record : array($record);

        // Template payload
        $payload = array(
            "redcap_url"  => APP_PATH_WEBROOT_FULL,
            "project_url" => APP_PATH_WEBROOT_FULL . "redcap_v" . REDCAP_VERSION . "/index.php?pid=" . PROJECT_ID,
            "project_id"  => $this->getProjectId(),
            "username"    => USERID,
            "instrument"  => $instrument
        );

        // Events
        if ($Proj->longitudinal) {
            $events = REDCap::getEventNames(true);
            $payload['redcap_event_name'] = $events[$event_id];
        }

        // Data Access Groups
        if (!empty($group_id))   $payload['redcap_data_access_group'] = REDCap::getGroupNames($group_id);

        foreach ($records as $record) {
            // Get the instrument status (as anb array)
            $instances = $this->getFormInstanceCompleteArray($record, $instrument, $event_id, $repeat_instance, $record_data);
            foreach ($instances as $instance) {
                $result = array_merge($payload, $instance, array("record" => $record));

                // Override the instrument complete it set
                if (!is_null($instrument_complete)) $result[$instrument . "_complete"] = $instrument_complete;
                $results[] = $result;
            }
        }

        return $results;
    }


    /**
     * Lets add some UI enhancements to show the advanced DETs
     * @param null $project_id
     */
    function redcap_every_page_top($project_id = null) {

        if (PAGE == "ProjectSetup/index.php") {
            global $lang;

            // Get the number of DETs
            $instances = $this->getSubSettings("dets");

            $niceButton = "<a href='" . $this->getUrl("Project.php") . "'>
                        <span class='btn btn-xs btn-primaryrc'>            
                            <span style='text-indent: 0; margin-left: 0;' class='badge badge-light'>" . count($instances) . "</span> 
                            Advanced DETs Enabled
                            <span class='small'> Click to review</span>
                        </span>
                    </a>";

            // Add something to Additional Customizations page
            $lang['edit_project_160'] .= "<br><div style='text-indent: 0;' class='mt-3 mb-3'>$niceButton</div>";

            // Add something to project setup page
            if (count($instances)>0) {
                ?>
                <script>
                    $(document).ready(function () {
                        var niceButton = "<?php echo str_replace("\n", "", $niceButton) ?>";
                        $('#setupChklist-modules .chklisttext').append(niceButton);
                    });
                </script>
                <?php
            }
        }

    }

}

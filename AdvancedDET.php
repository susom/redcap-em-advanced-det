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
        $detInstances = $this->getSubSettings("dets");
        $results = array();

        foreach ($detInstances as $i => $det) {
            $url   = $det['url'];
            $title = $det['title'];
            $logic = $det['logic'];

            if ($det['disabled']) {
                $this->emDebug("$title disabled");
                $results[$i] = "$title disabled";
                continue;
            }

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


    function callDet($url, $payload, $timeout=10) {
        // Set timeout value for http request

        // If $data_entry_trigger_url is a relative URL, then prepend with server domain
        $pre_url = "";
        if (substr($url, 0, 1) == "/") {
            $pre_url = (SSL ? "https://" : "http://") . SERVER_NAME;
        }

        // Send Post request
        // $response = http_post($pre_url . $url, $payload, $timeout);
        $response = "test";

        $this->emDebug($pre_url . $url, $payload, $response);

        return $response;
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
     * @return array containing one entry per instance with the repeat_instance key and the instrument_complete key
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
     * @param      $project_id
     * @param      $record              Single record id or array of record ids
     * @param      $instrument
     * @param      $event_id
     * @param null $group_id
     * @param null $repeat_instance
     * @param null $record_data         For batch, supply, otherwise will be fetched automatically
     * @param null $instrument_complete Leave null to use existing value, otherwise set to value from this arg
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

        // $this->emDebug($records, $results);
        return $results;
    }


    /**
     * Lets add some UI enhancements
     * @param null $project_id
     */
    function redcap_every_page_top($project_id = null) {

        if (PAGE == "ProjectSetup/index.php") {
            global $lang;

            // Get nummber of DETs
            $instances = $this->getSubSettings("dets");

            // Add something to Additional Customizations page
            $lang['edit_project_160'] .= "<br>
                <div style='text-indent: 0; border-color: #333 !important;' class='alert alert-information'>
                    <h6 class='text-center'>" . count($instances) . " Advanced DETs Enabled.</h6>
                    This project uses the Advanced DET EM.  Use the External Module configuration to add/edit configuration
                </div>";

            // Add something to project setup page
            ?>
            <script>
                $(document).ready(function() {
                    $('#setupChklist-modules .chklisttext').append(
                        '<div style="margin-bottom:2px;color:#800000";> ' +
                        '  <span class="badge badge-secondary" style="vertical-align: middle; padding: 4px 4px;"><?php echo count($instances) ?></span>' +
                        '  <span style="padding-top:2px;"> Advanced DETs Enabled - use External Modules to configure</span> ' +
                        '</div>'
                    );
                });
            </script>
            <?php
            // $this->addSurveyIcons();
            // $this->addSurveySettingsDisclaimer();
        }

    }


    /**
     * Return a html table of DETs if they exist - otherwise nothing
     */
    function getDetTable() {
        $instances = $this->getSubSettings("dets");
        $body = [];
        foreach ($instances as $i => $det) {
            $title    = $det['title'];
            $url      = $det['url'];
            $logic    = $det['logic'];
            $disabled = $det['disabled'] ? 1 : 0;
            $body[] = "<tr><td>$title</td><td>$url</td><td>$logic</td><td>$disabled</td></tr>";
        }

        $result = "";
        if (!empty($body)) {
            $result = "
                <table id='advanced_det' class='display' style='width:100%'>
                    <thead>
                        <tr><th>Title</th><th>Url</th><th>Logic</th><th>Disabled</th></tr>
                    </thead>
                    <tbody>
                        " . implode("", $body) . "
                    </tbody>
                </table>";
        }

        return $result;
    }


    /**
     * Adding some more info to the survey Settings page as well.
     */
    function addSurveySettingsDisclaimer()
    {
        if (PAGE == "Surveys/edit_info.php" || PAGE == "Surveys/create_survey.php") {
            $survey_name = $_GET['page'];

            // Get current value from external-module settings
            $this->loadConfig();

            // Nothing to do if there isn't logic for this instrument
            if (empty($this->auto_continue_logic[$survey_name])) return;

            ?>
            <div style="display:none;">
                <table>
                    <tr id="AdvancedDET-tr" style="padding: 10px 0;">
                        <td valign="top" style="width:20px;">
                            <i class="fas fa-code-branch"></i>
                        </td>
                        <td valign="top">
                            <div class=""><strong>AdvancedDET EM is configured:</strong></div>
                        </td>
                        <td valign="top" style="padding-left:15px;">
                            <div>This survey will only be administered if the logic below is <b>true</b>:
                                <a href="javascript:;" class="help2" onclick="simpleDialog('<p>This project has the AdvancedDET External Module installed. ' +
                                  'If someone tries to open this survey and this logic is not true, the participant will be redirected to the next available survey.</p>' +
                                  '<p>If the logic is false and there are no more eligible surveys after this, then the participant will receive the ' +
                                   'the end-of-survey options as configured below.</p>' +
                                   '<p>You can change these setting in the External Module config page</p>','AdvancedDET External Module',600);">?</a>
                            </div>
                            <code style="font-weight:normal; background-color:white; display:block; padding: 5px; width: 98%; border: 1px solid #c1c1c1;;">
                                <?php echo $this->auto_continue_logic[$survey_name]; ?>
                            </code>
                        </td>
                    </tr>
                </table>
            </div>

            <script>
                $(document).ready(function () {
                    var parentTr = $('#end_survey_redirect_next_survey').closest('tr');
                    $('#AdvancedDET-tr')
                        .insertAfter($('#save_and_return-tr'))
                        .show()
                    ;
                });
            </script>
            <?php

        }
    }



    /**
     * On the edit instrument table it shows a lock icon next to surveys that have webauth enabled
     */
    function addSurveyIcons()
    {
        if (PAGE == "Design/online_designer.php") {
            $this->loadConfig();

            if (count($this->auto_continue_logic) > 0) {
                $tip = '';
                ?>
                <script>
                    $(document).ready(function () {
                        var autocontinue_logic_surveys = <?php echo json_encode($this->auto_continue_logic); ?>;
                        console.log("Here", autocontinue_logic_surveys);
                        $.each(autocontinue_logic_surveys, function (i, j) {
                            console.log(i,j);
                            var img = $('<a href="#"><i class="fas fa-code-branch"></i></a>')
                                .attr('title', "<div style='font-size: 10pt;'>This survey uses the AutoContinue Logic EM and <u>only</u> renders if the following is true:</div><code style='font-size: 9pt;'>" + autocontinue_logic_surveys[i] + "</code>")
                                // .css({'margin-left': '3px'})
                                .attr('data-html', true)
                                // .attr('data-toggle', 'tooltip')
                                .attr('data-trigger', 'hover')
                                .attr('data-placement', 'right')
                                .insertAfter('a.modsurvstg[href*="page=' + i + '&"]');
                            img.popover(); //tooltip();
                        });
                        $('a.modsurvstg').removeAttr('style');
                    });
                </script>
                <style>
                    a.modsurvstg { display:inline-block; }
                </style>
                <?php
            }
        }
    }


}

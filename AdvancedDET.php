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
        $instances = $this->getSubSettings("dets");
        $results = array();

        foreach ($instances as $i => $det) {
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


            $payload = $this->getPayload($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance);
            $response = $this->callDet($url, $payload);

            // Return boolean for success
            $results[$i] = !!$response;
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
        $response = http_post($pre_url . $url, $payload, $timeout);

        $this->emDebug($pre_url . $url, $payload, $response);

        return $response;
    }


    function getPayload($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance) {
        global $Proj;

        $payload = array(
            "redcap_url"  => APP_PATH_WEBROOT_FULL,
            "project_url" => APP_PATH_WEBROOT_FULL . "redcap_v" . REDCAP_VERSION . "/index.php?pid=" . PROJECT_ID,
            "project_id"  => $project_id,
            "username"    => USERID,
            "record"      => $record,
            "instrument"  => $instrument
        );

        // Get the instrument status
        $instrument_complete_field           = $instrument . "_complete";
        $q                                   = REDCap::getData('json', $record, array($instrument_complete_field), $event_id);
        $q                                   = json_decode($q, true);
        $payload[$instrument_complete_field] = $q[0][$instrument_complete_field];

        // Events and Data Access Groups
        if ($Proj->longitudinal) $payload['redcap_event_name']        = REDCap::getEventNames($event_id);
        if (!empty($group_id))   $payload['redcap_data_access_group'] = REDCap::getGroupNames($group_id);

        // Repeating events/instruments
        if ($Proj->hasRepeatingFormsEvents()) {
            if ($Proj->isRepeatingForm($event_id, $instrument)) {
                $payload['redcap_repeat_instrument'] = $instrument;
                $payload['redcap_repeat_instance']   = $repeat_instance;
            } elseif ($Proj->isRepeatingEvent($_GET['event_id'])) {
                $payload['redcap_repeat_instance'] = $repeat_instance;
            }
        }

        return $payload;
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

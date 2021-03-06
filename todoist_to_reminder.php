<?php



/*This array gives which label name to map to which reminder*/
/*The reminder is set in minutes before the task*/



/* CURL SETUP */
$ch = curl_init();
$optArray = array(
    CURLOPT_URL => 'https://todoist.com/API/v8/sync',
    /*option so the result of the curl can be processes in php*/
    CURLOPT_RETURNTRANSFER => true
);
curl_setopt_array($ch, $optArray);
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_POST, true);
/*END CURL SETUP*/



/*Get all the labels and create a labelname id array.
The items reference the labels by id not by name therefore this translation array is necessary for mapping
*/

$data = array('token' => todoist_app_token(), 'sync_token' => '*', 'resource_types' => '["labels"]');
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
$label_result = curl_exec($ch);
$label_result = json_decode($label_result);
$label_names = $label_result->labels;
echo print_debug() ? "Create label id to label name array:\n" : '';
$label_translation = array();
foreach ($label_names as $label) {
    echo print_debug() ? "The label with the name: " . $label->name . " has the id " . $label->id . "\n" : "";
    $label_translation[$label->id] = $label->name;
}
/*END LABEL TRANSLATION ARRAY*/
$label_to_reminder = get_reminder_labels($label_translation);

// Get all ToDos.
/*Additional Curl Options*/
$data = array('token' => todoist_app_token(), 'resource_types' => '["items"]');
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
$result = curl_exec($ch);
$result = json_decode($result);
$items = $result->items;

/*FIlter only for ToDos with a label*/
echo print_debug() ? "\n1. Get all tasks with a label. \n2. Set reminder according to label translation array.\n" : "";
$label_keys = array_keys($label_to_reminder);
foreach ($items as $item) {
    if ($item->labels != null) {
        $labels = $item->labels;
        echo print_debug() ? "1. Task with the id: " . $item->id : '';
        $label_ids = array();

        /**Check if any of the reminder labels match the task */
        $label_match = false;
        foreach ($labels as $label_id) {

            /*Need this if condition, because otherwise it
            will try to set reminders to all tasks with a label*/
            $label_name = $label_translation[$label_id];
            $remove_labels = true;

            if (in_array($label_name, $label_keys)) {
                echo print_debug(true) ? "1. Task '" . $item->content . "' has the label "  . $label_name . "\n" : '';

                $label_match = true;
                /**Since the newest todoist update all reminders are the same, e.g. no differentiation between email, push and desktop. */
                $service = "push";
                $time_offset = $label_to_reminder[$label_name];

                echo print_debug(true) ? "2. Create a  reminder " . $time_offset . " minutes before the task. \n" : "";


                /*Cannot set a before reminder if task has no due date*/

                if (!empty($item->due->date)) {
                    $due_date = $item->due->date;
                    /*A task might have a due date but not a due time. So first we check if the task has both date and time by checing the string timestamo
                    for a 'T' since the date format is as follows: 2021-02-25T12:00:00*/
                    if (str_contains($due_date, 'T')) {
                        $command = todoist_create_reminder_with_offset($item->id, $service, $time_offset);
                        $data = array('token' => todoist_app_token(), 'commands' => $command);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                        echo ("Execute command for setting reminder. Result: \n");
                        $result = curl_exec($ch);
                        var_dump($result);
                    } else {
                        /*Set base time at 9 in the morning*/
                        $due_date = $due_date . "T9:00:00";
                        /*Substract the time offset in minutes*/
                        /**Convert to UNIX timestamp */
                        $due_date = strtotime($due_date);
                        /** Remove the offset in minutes */
                        $due_date = $due_date - (int)$time_offset * 60;
                        /**Convert back to ISO Format 8601 */
                        $due_date = date('c', $due_date);
                        /*Remove the timezone from the timestamp to be conform with todoist API*/
                        $due_date = substr($due_date, 0, -6);
                        var_dump($due_date);
                        $command = todoist_create_reminder_at_date($item->id, $service, $due_date);
                        $data = array('token' => todoist_app_token(), 'commands' => $command);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                        echo ("Execute command for setting reminder. Result: \n");
                        $result = curl_exec($ch);
                        var_dump($result);
                    }
                } else {
                    /**If the task has no due date the labels should not be removed.*/
                    $remove_labels = false;
                    echo ("The task has no due date. Abort setting reminders and removing labels. \n");
                }
            }
            /*Need this condition, bacuse after the reminder Update the label is supposed to
            be removed. Otherwise, if the script is run as for instance a cron job,
            each the the script runs an additional remider will be added.
            Therefore add here all the ids of the labels which should not be removed*/ else {
                array_push($label_ids, $label_id);
            }
        }
        /*Here update the item by adding all the labels which should not be removed. 
        Threfore the label to remove is automatically gone. (Since a task can have more than one label, we only remove the reminder ones) */
        if ($label_match && $remove_labels) {
            $command = todoist_create_label_add_command($item->id, $label_ids);
            $data = array('token' => todoist_app_token(), 'commands' => $command);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            echo ("Execute command for removing reminder labels. Result: \n");
            $result = curl_exec($ch);
            var_dump($result);
        }
    }
};

todoist_set_email_notifications($ch);

// close curl
curl_close($ch);



/*Helper Methods*/

/**
 * @brief reads ToDoist API token from file. 
 * @param $filename file where the token is stored
 * @return returns the token as string
 */
function todoist_app_token($filename = "api-key.txt")
{

    $f = fopen($filename, 'r');
    $line = fgets($f);
    fclose($f);
    return $line;
}


/**
 * Parses all existing labels and check if any of those match the required pattern. 
 */
function get_reminder_labels($label_translation)
{
    $label_to_reminder = array();
    foreach ($label_translation as $label_name) {
        $label_split = explode('-', $label_name);
        if (count($label_split) == 2) {
            if (is_numeric($label_split[0])) {
                if (
                    strtolower($label_split[1]) == "min" || strtolower($label_split[1]) == "mins"
                    || strtolower($label_split[1]) == "minutes" || strtolower($label_split[1]) == "minute"
                ) {
                    $label_to_reminder[$label_name] = (string)(int)$label_split[0];
                }
                if (strtolower($label_split[1]) == "hour" || strtolower($label_split[1]) == "hours") {
                    $label_to_reminder[$label_name] = (string)((int)$label_split[0] * 60);
                }
                if (strtolower($label_split[1]) == "day" || strtolower($label_split[1]) == "days") {
                    $label_to_reminder[$label_name] = (string)((int)$label_split[0] * 60 * 24);
                }
            }
        }
    }
    //var_dump($label_to_reminder);
    return $label_to_reminder;
}

function print_debug($debug = true)
{
    return $debug;
}
function todoist_create_reminder_with_offset($item_id, $service, $time_offset)
{
    $command = '[{"type": "reminder_add", "temp_id": "' . guidv4(random_bytes(16)) . '",
    "uuid": "' . guidv4(random_bytes(16)) . '","args": {"item_id": ' . $item_id . ', "service": "' . $service . '",  "minute_offset": "' . $time_offset . '"}}]';
    echo print_debug() ? "Created command for reminder update: \n: " . $command : "";
    return $command;
}

function todoist_create_reminder_at_date($item_id, $service, $date)
{
    $command = '[{"type": "reminder_add", "temp_id": "' . guidv4(random_bytes(16)) . '",
    "uuid": "' . guidv4(random_bytes(16)) . '","args": {"item_id": ' . $item_id . ', "service": "' . $service . '",  "due":{"date": "' . $date . '"}}}]';
    echo print_debug() ? "Created command for reminder update: \n: " . $command : "";
    return $command;
}

function todoist_create_label_add_command($item_id, $labels)
{
    /*Make the the array to a string*/
    $label_array_string = "[]";
    if (!empty($labels)) {
        $label_array_string = "[";
        foreach ($labels as $label_id) {
            $label_array_string = $label_array_string . $label_id . ",";
        }
        $label_array_string = substr($label_array_string, 0, -1) . "]";
    }
    $command = '[{"type": "item_update",  "uuid": "' . guidv4(random_bytes(16)) . '",
      "args": {"id": ' . $item_id . ', "labels" :"' . $label_array_string . '"}}]';
    echo print_debug() ? "Created command for Label Update: \n: " . $command : "";
    return $command;
}

function todoist_set_email_notifications($ch)
{
    # 1-5 Mo-Fr, 6,1= Sat, Sunday
    $weekday=date('w');
    $hour=date('H');
    # Setting Email Notifications to True for all email reminders
    # Monday till friday 8-20h
    if($weekday==0 || $weekday==6){
        $email_notifications = "false";
    }
    # If Monday till friday, enable email notifcations for 
    # 8-20:00
    else if($hour >= 8 && $hour <= 20){
        $email_notifications = "true";
    }
    else {
        $email_notifications = "false";
    }
    $command = '[{"type": "user_settings_update",  "uuid": "' . guidv4(random_bytes(16)) . '",
        "args": {"reminder_email":' . $email_notifications . '}}]';
    echo print_debug() ? "\n Created command for Updating Notification Type: \n: " . $command : "";
    $data = array('token' => todoist_app_token(), 'commands' => $command);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    echo ("Execute changing email notification to ".$email_notifications." \n");
    $result = curl_exec($ch);
    var_dump($result);
}

/*From http://stackoverflow.com/questions/2040240/php-function-to-generate-v4-uuid*/
function guidv4($data)
{
    assert(strlen($data) == 16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
function str_contains($a, $b)
{
    if (strpos($a, $b) !== false) {
        return true;
    } else {
        return false;
    }
}

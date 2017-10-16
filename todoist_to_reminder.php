<?php

/*SETUP SCRIPT*/
/*Sync Token for ToDoist Access*/
function todoist_app_token()
{
    $app_token="XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX";
    return $app_token;
}
/*This array gives which label name to map to which reminder*/
/*The reminder is set in minutes before the task*/
$label_to_reminder=array(
  "sms_1_hour" => array("sms","60"),
  "sms_3_hours" => array("sms","180"),
  "sms_1_day" => array("sms","1440"),
  "email_1_day" => array("email","1440"),
  "email_3_days" => array("email","4320"),
  "email_7_days" => array("email","10080"),
);
 function print_debug($debug=true)
 {
     return $debug;
 }

/*END SCRIPRT SETUP*/

/* Curl Setup */
$ch = curl_init();
$optArray = array(
  CURLOPT_URL => 'https://todoist.com/API/v7/sync',
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
$data = array('token' => todoist_app_token(), 'sync_token' => '*', 'resource_types'=>'["labels"]');
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
$label_result=curl_exec($ch);
$label_result=json_decode($label_result);
$label_names=$label_result->labels;
echo print_debug() ? "Create label id to label name array:\n":'';
$label_translation=array();
foreach ($label_names as $label) {
    echo print_debug() ? "The label with the name: ".$label->name . " has the id " . $label->id ."\n":"";
    $label_translation[$label->id]=$label->name;
}
/*END LABEL TRANSLATION ARRAY*/

// Get all ToDos.
/*Additional Curl Options*/
$data = array('token' => todoist_app_token(), 'resource_types'=>'["items"]');
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
$result=curl_exec($ch);
$result=json_decode($result);
$items=$result->items;

/*FIlter only for ToDos with a label*/
echo print_debug() ? "\n1. Get all tasks with a label. \n2. Set reminder according to label translation array.\n" :"";
$label_keys = array_keys($label_to_reminder);
foreach ($items as $item) {
    if ($item->labels != null) {
        $labels=$item->labels;
        echo print_debug() ? "1. Task with the id: ".$item->id :'';
        $label_ids=array();
        foreach ($labels as $label_id) {

            /*Need this if condition, because otherwise it
            will try to set reminders to all tasks with a label*/
            $label_name=$label_translation[$label_id];

            if (in_array($label_name, $label_keys)) {
                echo print_debug() ? " has the label with the id: ".$label_id. " and the Label Name " .$label_name."\n" :'';


                $service=$label_to_reminder[$label_name][0];
                $time_offset=$label_to_reminder[$label_name][1];

                echo print_debug() ? "2. Create a ".$service. " reminder with the time offset: ".$time_offset."\n":"";
                $command=todoist_create_reminder_add_command($item->id, $service, $time_offset);

                $data = array('token' => todoist_app_token(), 'commands' => $command);
                /*Cannot set a before reminder if task has no due date*/
                if (!empty($item->date_string)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                    $result=curl_exec($ch);
                    var_dump($result);
                }
            }
            /*Need this condition, bacuse after the reminder Update the label is supposed to
            be removed. Otherwise, if the script is run as for instance a cron job,
            each the the script runs an additional remider will be added.
            Therefore add here all the ids of the labels which should not be removed*/
            else {
                array_push($label_ids, $label_id);
            }
      //
        }
        /*Here update the item by adding all the labels which should not be removed.
        Threfore the label to remove is automatically gone*/
        $command=todoist_create_label_add_command($item->id, $label_ids);
        $data = array('token' => todoist_app_token(), 'commands' => $command);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        $result=curl_exec($ch);
        var_dump($result);
    }
};

/*add reminders*/



// schlie�e den cURL-Handle und gib die Systemresourcen frei
curl_close($ch);




/*Helper Methods*/
function todoist_create_reminder_add_command($item_id, $service, $time_offset)
{
    $command='[{"type": "reminder_add", "temp_id": "'.guidv4(random_bytes(16)).'",
    "uuid": "'.guidv4(random_bytes(16)).'","args": {"item_id": '.$item_id.', "service": "'.$service.'",  "minute_offset": "'.$time_offset.'"}}]';
    echo print_debug() ? "Created command for reminder Update: \n: ".$command : "";
    return $command;
}
function todoist_create_label_add_command($item_id, $labels)
{
    /*Make the the array to a string*/
      $label_array_string="[]";
    if (!empty($labels)) {
        $label_array_string="[";
        foreach ($labels as $label_id) {
            $label_array_string=$label_array_string.$label_id.",";
        }
        $label_array_string=substr($label_array_string, 0, -1)."]";
    }
    $command='[{"type": "item_update",  "uuid": "'.guidv4(random_bytes(16)).'",
      "args": {"id": '.$item_id.', "labels" :"'.$label_array_string.'"}}]';
    echo print_debug() ? "Created command for Label Update: \n: ".$command : "";
    return $command;
}
/*From http://stackoverflow.com/questions/2040240/php-function-to-generate-v4-uuid*/
  function guidv4($data)
  {
      assert(strlen($data) == 16);
      $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
      $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
      return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
  }

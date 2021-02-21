# What does this script do?

Sets automatic reminders before a tasks due date based on labels assigned to the task. 

For example label `5-mins` sets a reminder to a task 5 minutes before it's due date. 

After setting the reminder the label(s) are removed.

# Setup

1. Put the API Key in the a file `api-key.txt` in the main directory, which you can get under: Todoist Settings -> Account -> API token
2. Execute the script with `php todoist_to_reminder.php` (Make sure the php curl extenstion in installed)

# How to setup the labels?
The labels must follow the following structure `<time offset>-<time unit>`. E.g. `25-mins`, `1-hour`, `2-days`
Supported verbs: 
- min, mins, minutes
- hour,hours
- day,days

All verbs are case insensitive.

# Special Cases
## What if the task has a reminder-label but no no due date?
Nothing happens.

## What if the task has a reminder label but no due time, just a due date?

The task reminder will be set with an offset from 9:00 a.m. at the task due date. 

For example a task with a due date of Friday and a label `25-mins` will get a reminder at Friday 8:35 a.m.

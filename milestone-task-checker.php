<?php

// This script assumes:
// 1. you have used Zapier to create a checklist with the same name as your Teamwork project,
// 2. your checklist has a task with the same name as one of your milestones

// This script will receive a Teamwork "milestone.completed" webhook and then will:
// 1. attempt to find a checklist with the milestone's project name,
// 2. attempt to find a task with the milestone's name,
// 3. check off that task if it exists.

// The Teamwork API key so that we can get the project name and the name of the milestone
define('TEAMWORK_API_KEY', '');

// The Teamwork API base URL, for example "https://companyname.teamwork.com"
define('TEAMWORK_API_BASE_URL', '');

// The Process Street API key so we can check off the item
define('PROCESS_STREET_API_KEY', '');

// Don't edit below this line unless you know what you're doing :)

if (empty(TEAMWORK_API_KEY)) {
    error_log('Teamwork API key is not set!');
    http_response_code(500);
    exit;
}

if (empty(TEAMWORK_API_BASE_URL)) {
    error_log('Teamwork API base URL is not set!');
    http_response_code(500);
    exit;
}

if (empty(PROCESS_STREET_API_KEY)) {
    error_log('Process Street API key is not set!');
    http_response_code(500);
    exit;
}

// Teamwork
define('TEAMWORK_API_ROUTE_MILESTONES_GET', '/milestones/{milestone_id}.json');
define('TEAMWORK_EVENT_MILESTONE_COMPLETED', 'MILESTONE.COMPLETED');

// Process Street
define('PROCESS_STREET_API_BASE', 'https://api.process.st');
define('PROCESS_STREET_API_ROUTE_TASKS_QUERY', '/1/tasks');
define('PROCESS_STREET_API_ROUTE_TASKS_UPDATE', '/1/tasks/{task_id}');

$event = get_form_param($_POST, 'event');

switch ($event) {
    case TEAMWORK_EVENT_MILESTONE_COMPLETED:
        handle_milestone_completed($_POST);
        break;
    default:
        // Ignore all other events
        http_response_code(200);
}

function handle_milestone_completed($params) {

    $milestone_id = get_form_param($params, 'objectId');
    $milestone = get_milestone($milestone_id);

    $project_name = trim($milestone['project-name']);
    $milestone_name = trim($milestone['title']);

    error_log('project name is ' . $project_name);
    error_log('milestone name is ' . $milestone_name);

    $task = find_task([
        'where' => json_encode([
            'taskTemplate.name' => ['_eq' => $milestone_name],
            'checklistRevision.checklist.name' => ['_eq' => $project_name],
            'checklistRevision.checklist.status' => ['_eq' => 'Active'],
            'checklistRevision.status' => ['_eq' => 'Active'],
        ]),
        'include' => 'taskTemplate,checklistRevision.checklist',
        'orderBy' => '+checklistRevision.createdDate',
        'limit' => 1
    ]);

    if (!empty($task)) {
        update_task($task['id'], ['status' => 'Completed']);
        error_log("succeeded to update task '{$task['id']}'");
    } else {
        error_log("no task found in Process Street for milestone '$milestone_name' in project '$project_name'");
    }

    http_response_code(200);
    exit;

}

// Teamwork

function get_milestone($milestone_id) {
    list($status, $response) = do_get_request(
        TEAMWORK_API_BASE_URL,
        str_replace('{milestone_id}', $milestone_id, TEAMWORK_API_ROUTE_MILESTONES_GET),
        [],
        ['Authorization: Basic ' . base64_encode(TEAMWORK_API_KEY . ':xxx')]
    );
    if ($status === 200) {
        return $response['milestone'];
    } else {
        error_log("failed to get milestone '$milestone_id' with status code $status");
        http_response_code(500);
        exit;
    }
}

// Process Street

function find_task($params) {
    list($status, $tasks) = do_get_request(
        PROCESS_STREET_API_BASE,
        PROCESS_STREET_API_ROUTE_TASKS_QUERY,
        $params,
        ['Authorization: Basic ' . base64_encode(PROCESS_STREET_API_KEY . ':')]
    );
    if ($status === 200) {
        return !empty($tasks) ? $tasks[0] : null;
    } else {
        error_log("failed to get tasks with status code $status");
        http_response_code(500);
        exit;
    }
}

function update_task($task_id, $changes) {
    list($status, $response) = do_post_request(
        'PATCH',
        PROCESS_STREET_API_BASE,
        str_replace('{task_id}', $task_id, PROCESS_STREET_API_ROUTE_TASKS_UPDATE),
        ['changes' => $changes],
        ['Authorization: Basic ' . base64_encode(PROCESS_STREET_API_KEY . ':')]
    );
    if ($status === 200) {
        return $response;
    } else {
        error_log("failed to update task '$task_id' with status code $status");
        http_response_code(500);
        exit;
    }
}

// Miscellaneous

function get_form_param($params, $key) {
    if (isset($params[$key])) {
        $value = $params[$key];
    }
    if (empty($value)) {
        error_log('missing form param: ' . $key);
        http_response_code(400);
        exit;
    } else {
        error_log($key . ' = ' . $value);
    }
    return $value;
}

// HTTP

function do_get_request($api_url, $route, $params = [], $headers = []) {

    if (empty($params)) {
        $encoded_params = '';
    } else {
        $encoded_params = '?' . http_build_query($params);
    }

    $url = $api_url . $route . $encoded_params;
    $ch = curl_init($url);

//    error_log('requesting: ' . $url);

    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $json = curl_exec($ch);
    if ($error_number = curl_errno($ch)) {
        $error_message = curl_strerror($error_number);
        error_log("curl error ({$error_number}):\n {$error_message}");
        curl_close($ch);
        return [500, []];
    }
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $decoded_json = json_decode($json, true);
    curl_close($ch);

//    error_log('request complete');
//    error_log('http code ' . $http_code);
//    error_log("json: " . $json);

    return [$http_code, $decoded_json];

}

function do_post_request($verb, $api_url, $route, $params, $headers = []) {

    $url = $api_url . $route;
    $ch = curl_init($url);

    $data = json_encode($params);

    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $verb ?: 'POST');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge([
        'Content-Type: application/json',
        'Content-Length: ' . strlen($data),
    ], $headers));

    $json = curl_exec($ch);
    if ($error_number = curl_errno($ch)) {
        $error_message = curl_strerror($error_number);
        error_log("curl error ({$error_number}):\n {$error_message}");
        curl_close($ch);
        return [500, []];
    }
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $decoded_json = json_decode($json, true);
    curl_close($ch);

    return [$http_code, $decoded_json];

}



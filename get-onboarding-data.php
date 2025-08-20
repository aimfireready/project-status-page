<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Load configuration
if (!file_exists('asana-config.php')) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Configuration file not found. Please copy asana-config-example.php to asana-config.php and configure it.'
    ]);
    exit;
}

require_once 'asana-config.php';

function makeAsanaRequest($url, $token) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        $errorResponse = json_decode($response, true);
        $errorMessage = isset($errorResponse['errors'][0]['message']) ? $errorResponse['errors'][0]['message'] : $response;
        throw new Exception("Asana API request failed with code: $httpCode - Error: $errorMessage - URL: $url");
    }
    
    return json_decode($response, true);
}

function extractNameFromTitle($title) {
    // Extract name from "Onboard Adam Zebra" format
    if (preg_match('/^Onboard\s+(.+)$/', $title, $matches)) {
        return trim($matches[1]);
    }
    return null;
}

function getCustomFieldValue($task, $fieldGid) {
    if (!isset($task['custom_fields'])) {
        return null;
    }
    
    foreach ($task['custom_fields'] as $field) {
        if ($field['gid'] === $fieldGid) {
            // Handle different field types
            if (isset($field['text_value'])) {
                return $field['text_value'];
            } elseif (isset($field['enum_value']['name'])) {
                return $field['enum_value']['name'];
            } elseif (isset($field['date_value'])) {
                return $field['date_value'];
            } elseif (isset($field['display_value'])) {
                return $field['display_value'];
            }
        }
    }
    return null;
}

try {
    // Function to get all subtasks recursively
    function getSubtasks($taskGid, $token) {
        $subtasksUrl = "https://app.asana.com/api/1.0/tasks/$taskGid/subtasks?opt_fields=name,completed,completed_at,resource_subtype,gid";
        $subtasks = makeAsanaRequest($subtasksUrl, $token);
        
        $allSubtasks = [];
        foreach ($subtasks['data'] as $subtask) {
            $allSubtasks[] = $subtask;
            // If this is a section, get its subtasks too
            if ($subtask['resource_subtype'] === 'section') {
                $subSubtasks = getSubtasks($subtask['gid'], $token);
                $allSubtasks = array_merge($allSubtasks, $subSubtasks);
            }
        }
        return $allSubtasks;
    }

    // Step 1: Get all tasks in the Onboarding section
    $tasksUrl = "https://app.asana.com/api/1.0/tasks?section=$ONBOARDING_SECTION_GID&completed_since=now&opt_fields=name,completed,custom_fields,gid";
    $tasksResponse = makeAsanaRequest($tasksUrl, $ASANA_TOKEN);
    
    $onboardingData = [];
    
    function findCompletedSubtask($subtasks, $pattern) {
        if (!isset($subtasks)) return null;
        foreach ($subtasks as $subtask) {
            if (preg_match($pattern, $subtask['name'])) {
                error_log("Found matching task: " . $subtask['name'] . " - Completed: " . ($subtask['completed'] ? 'true' : 'false'));
                if ($subtask['completed']) {
                    return [
                        'completed' => true,
                        'completed_at' => $subtask['completed_at'],
                        'task_gid' => $subtask['gid']
                    ];
                } else {
                    return [
                        'completed' => false,
                        'completed_at' => null,
                        'task_gid' => $subtask['gid']
                    ];
                }
            }
        }
        return ['completed' => false, 'completed_at' => null, 'task_gid' => null];
    }

    // Step 2 & 3: Process each task
    foreach ($tasksResponse['data'] as $task) {
        // Skip completed tasks
        if ($task['completed']) {
            continue;
        }
        
        // Extract name from task title
        $name = extractNameFromTitle($task['name']);
        if (!$name) {
            continue;
        }
        
        // Get detailed task info with custom fields
        $taskDetailUrl = "https://app.asana.com/api/1.0/tasks/{$task['gid']}?opt_fields=custom_fields";
        $taskDetail = makeAsanaRequest($taskDetailUrl, $ASANA_TOKEN);
        
        // Get all subtasks including nested ones
        $subtasks = getSubtasks($task['gid'], $ASANA_TOKEN);
        
        // Find Technology setup task and get its subtasks
        foreach ($subtasks as $subtask) {
            if ($subtask['name'] === 'Technology set up') {
                $techSubtasks = getSubtasks($subtask['gid'], $ASANA_TOKEN);
                error_log("Found tech setup subtasks for " . $name . ":");
                foreach ($techSubtasks as $tech) {
                    error_log("  - " . $tech['name'] . " (Completed: " . ($tech['completed'] ? 'true' : 'false') . ")");
                }
                $subtasks = array_merge($subtasks, $techSubtasks);
                break;
            }
        }

        // Check Microsoft Account status
        $msAccountStatus = findCompletedSubtask($subtasks, '/Create Microsoft user account/i');
        
        // Check Software Accounts status
        $softwareStatus = findCompletedSubtask($subtasks, '/Add user to role-based apps/i');
        
        // Check Equipment status - BOTH laptop and peripherals must be complete
        $laptopStatus = findCompletedSubtask($subtasks, '/Deploy laptop/i');
        $peripheralsStatus = findCompletedSubtask($subtasks, '/Deploy peripherals/i');
        
        // Equipment is ready only if both laptop and peripherals are complete
        $equipmentReady = [
            'completed' => ($laptopStatus['completed'] && $peripheralsStatus['completed']),
            'completed_at' => ($laptopStatus['completed'] && $peripheralsStatus['completed']) ? 
                max($laptopStatus['completed_at'], $peripheralsStatus['completed_at']) : null,
            'task_gid' => $laptopStatus['task_gid'] // Use laptop task as primary link
        ];
        
        // Extract custom field values
        $startDateField = getCustomFieldValue($taskDetail['data'], $CUSTOM_FIELDS['start_date']);
        
        // Check if Start Date is complete (date has passed)
        $startDateCompleted = false;
        $startDateCompletedAt = null;
        if ($startDateField && isset($startDateField['date'])) {
            $startDate = new DateTime($startDateField['date']);
            $today = new DateTime();
            $today->setTime(0, 0, 0); // Compare dates only, not time
            $startDateCompleted = ($startDate <= $today);
            if ($startDateCompleted) {
                $startDateCompletedAt = $startDateField['date'];
            }
        }
        
        $personData = [
            'name' => $name,
            'task_gid' => $task['gid'],
            'state' => getCustomFieldValue($taskDetail['data'], $CUSTOM_FIELDS['state']),
            'position' => getCustomFieldValue($taskDetail['data'], $CUSTOM_FIELDS['position']),
            'start_date' => $startDateField,
            'email' => getCustomFieldValue($taskDetail['data'], $CUSTOM_FIELDS['email']),
            'phone' => getCustomFieldValue($taskDetail['data'], $CUSTOM_FIELDS['phone']),
            'shipping_address' => getCustomFieldValue($taskDetail['data'], $CUSTOM_FIELDS['shipping_address']),
            'timeline_nodes' => [
                'offer_accepted' => [
                    'completed' => true,
                    'completed_at' => null, // We don't have this info
                    'task_gid' => $task['gid'] // Main onboarding task
                ],
                'microsoft_account' => $msAccountStatus,
                'software_accounts' => $softwareStatus,
                'equipment_ready' => $equipmentReady,
                'start_date' => [
                    'completed' => $startDateCompleted,
                    'completed_at' => $startDateCompletedAt,
                    'task_gid' => $task['gid'],
                    'date' => $startDateField
                ]
            ]
        ];
        
        // Determine if remote based on state
        $personData['is_remote'] = ($personData['state'] !== 'IN');
        
        $onboardingData[] = $personData;
    }
    
    // Step 4: Return as JSON
    echo json_encode([
        'success' => true,
        'data' => $onboardingData,
        'count' => count($onboardingData)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>

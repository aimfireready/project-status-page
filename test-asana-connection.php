<?php
/**
 * Test script to verify Asana connection and find custom field GIDs
 */

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
        throw new Exception("Asana API request failed with code: $httpCode - Response: $response");
    }
    
    return json_decode($response, true);
}

echo "<h1>Asana Connection Test</h1>";

try {
    // Test 1: Basic connection
    echo "<h2>1. Testing API Connection...</h2>";
    $userUrl = "https://app.asana.com/api/1.0/users/me";
    $user = makeAsanaRequest($userUrl, $ASANA_TOKEN);
    echo "✅ Connected as: " . $user['data']['name'] . " (" . $user['data']['email'] . ")<br><br>";
    
    // Test 2: Project access
    echo "<h2>2. Testing Project Access...</h2>";
    $projectUrl = "https://app.asana.com/api/1.0/projects/$PROJECT_GID";
    $project = makeAsanaRequest($projectUrl, $ASANA_TOKEN);
    echo "✅ Project found: " . $project['data']['name'] . "<br><br>";
    
    // Test 3: List custom fields (via a sample task)
    echo "<h2>3. Available Custom Fields:</h2>";
    
    // First get a sample task
    $sampleTaskUrl = "https://app.asana.com/api/1.0/tasks?project=$PROJECT_GID&limit=1&opt_fields=gid";
    $sampleTasks = makeAsanaRequest($sampleTaskUrl, $ASANA_TOKEN);
    
    if (empty($sampleTasks['data'])) {
        echo "⚠️ No tasks found to check custom fields.<br><br>";
        $fields = ['data' => []];
    } else {
        $taskGid = $sampleTasks['data'][0]['gid'];
        $fieldsUrl = "https://app.asana.com/api/1.0/tasks/$taskGid?opt_fields=custom_fields.gid,custom_fields.name,custom_fields.resource_subtype";
        $taskWithFields = makeAsanaRequest($fieldsUrl, $ASANA_TOKEN);
        $fields = ['data' => $taskWithFields['data']['custom_fields'] ?? []];
    }
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>Field Name</th><th>GID</th><th>Type</th></tr>";
    foreach ($fields['data'] as $field) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($field['name']) . "</td>";
        echo "<td><code>" . $field['gid'] . "</code></td>";
        echo "<td>" . $field['resource_subtype'] . "</td>";
        echo "</tr>";
    }
    echo "</table><br>";
    
    // Test 4: List sections
    echo "<h2>4. Available Sections:</h2>";
    $sectionsUrl = "https://app.asana.com/api/1.0/projects/$PROJECT_GID/sections";
    $sections = makeAsanaRequest($sectionsUrl, $ASANA_TOKEN);
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>Section Name</th><th>GID</th></tr>";
    foreach ($sections['data'] as $section) {
        $highlight = ($section['gid'] === $ONBOARDING_SECTION_GID) ? ' style="background-color: yellow;"' : '';
        echo "<tr$highlight>";
        echo "<td>" . htmlspecialchars($section['name']) . "</td>";
        echo "<td><code>" . $section['gid'] . "</code></td>";
        echo "</tr>";
    }
    echo "</table><br>";
    
    // Test 5: Sample tasks
    echo "<h2>5. Sample Onboarding Tasks:</h2>";
    $tasksUrl = "https://app.asana.com/api/1.0/tasks?project=$PROJECT_GID&section=$ONBOARDING_SECTION_GID&completed_since=now&opt_fields=name,completed,gid&limit=5";
    $tasks = makeAsanaRequest($tasksUrl, $ASANA_TOKEN);
    
    if (empty($tasks['data'])) {
        echo "⚠️ No incomplete tasks found in Onboarding section.<br>";
    } else {
        echo "<ul>";
        foreach ($tasks['data'] as $task) {
            echo "<li>" . htmlspecialchars($task['name']) . " (GID: " . $task['gid'] . ")</li>";
        }
        echo "</ul>";
    }
    
    echo "<h2>✅ All tests passed!</h2>";
    echo "<p>Copy the custom field GIDs above into your asana-config.php file.</p>";
    
} catch (Exception $e) {
    echo "<h2>❌ Error:</h2>";
    echo "<p style='color: red;'>" . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

<?php
/**
 * Asana Configuration Example
 * 
 * Copy this file to 'asana-config.php' and fill in your actual values.
 * Never commit the actual config file to version control!
 */

// Step 1: Get your Personal Access Token
// Go to: https://app.asana.com/0/my-apps
// Create a new Personal Access Token
$ASANA_TOKEN = '2/1207982179459269/1211069721147361:74657db83b25b270396d287a59d5cae8';

// Step 2: Get your Project GID
// Go to your Hiring project in Asana
// The URL will look like: https://app.asana.com/0/PROJECT_GID/...
// Copy the PROJECT_GID from the URL
$PROJECT_GID = 'PROJECT_GID';

// Step 3: Get your Section GID (already found from your JSON)
$ONBOARDING_SECTION_GID = 'ONBOARDING_SECTION_GID';

// Step 4: Get Custom Field GIDs
// You need to find the GIDs for each custom field in your project
// Use this API call to get custom fields from a sample task:
// GET https://app.asana.com/api/1.0/tasks/TASK_GID?opt_fields=custom_fields.gid,custom_fields.name,custom_fields.resource_subtype

$CUSTOM_FIELDS = [
    'state' => '123456789123456789',
    'position' => '123456789123456789', 
    'start_date' => '123456789123456789',
    'email' => '123456789123456789',
    'phone' => '123456789123456789',
    'shipping_address' => '123456789123456789'
];

/**
 * To find custom field GIDs, you can use this curl command:
 * 
 * curl -H "Authorization: Bearer YOUR_TOKEN" \
 *      "https://app.asana.com/api/1.0/tasks/SAMPLE_TASK_GID?opt_fields=custom_fields.gid,custom_fields.name,custom_fields.resource_subtype"
 * 
 * Look for the "gid" field for each custom field you need.
 */
?>

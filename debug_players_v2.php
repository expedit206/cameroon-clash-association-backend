<?php
// Simple script to get player rankings for Cameroon
$token = "TOKEN_PLACEHOLDER";
$envContent = file_get_contents('.env');
if (preg_match('/COC_API_TOKEN=(.*)/', $envContent, $matches)) {
    $token = trim($matches[1]);
}

$url = "https://api.clashofclans.com/v1/locations/32000046/rankings/players";

$opts = [
    "http" => [
        "method" => "GET",
        "header" => "Authorization: Bearer " . $token . "\r\n"
    ]
];

$context = stream_context_create($opts);
$response = file_get_contents($url, false, $context);

echo "PLAYER DATA SAMPLE:\n";
if ($response) {
    $data = json_decode($response, true);
    if (isset($data['items'][0])) {
        print_r($data['items'][0]);
    } else {
        echo "No items found.\n";
        print_r($data);
    }
} else {
    echo "API Request failed.\n";
}

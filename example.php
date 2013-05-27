<?php
include("api.php");
$api = new betaFaceApi();
$api->log_level = 2;

$upload_response = $api->upload_face("obama_normal.jpg", "obama1@waltergammarota.com");
$matches = $api->recognize_faces("obama_other.jpg", "waltergammarota.com");

echo "<pre>";
print_r($matches);
echo "</pre>";
?>

<?php
namespace Stanford\SPL;
/** @var \Stanford\SPL\SPL $module */

$token  = isset($_REQUEST['token']) ? $_REQUEST['token'] : false;
$id     = isset($_REQUEST['uid'])   ? $_REQUEST['uid']   : false;

$result = array();

if ($token === false || $id === false) {
    $result['success'] = false;
    $result['message'] = "Invalid Request";
} else {
    $result = $module->tokenLookup($token,$id);
}

header ("application/json");
echo json_encode($result);

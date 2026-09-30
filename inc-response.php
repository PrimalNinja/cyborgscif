<?php
// ---------------------------------------------------------------------------
// CyborgSCIF - response builder
// Response is always an array of { result, message, data }.
// ---------------------------------------------------------------------------

$g_arrResults = [];

function resp_Add($intResult_a, $strMessage_a, $arrData_a)
{
    global $g_arrResults;
    $g_arrResults[] = [
        'result' => (int)$intResult_a,
        'message' => (string)$strMessage_a,
        'data' => $arrData_a
    ];
}

function resp_Clear()
{
    global $g_arrResults;
    $g_arrResults = [];
}

function resp_Send()
{
    global $g_arrResults;
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($g_arrResults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

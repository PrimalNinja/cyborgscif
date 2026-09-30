<?php
// ---------------------------------------------------------------------------
// CyborgSCIF - single webservice entry point
// In : { "command":"JOIN", "parameters":[{"name":"channel","value":"#cpc"}] }
// Out: [ { "result":0, "message":"", "data":[ [...], [...] ] } ]
// State is in local files (inc-store.php), messages in ZOSCII MQ (inc-mq.php).
// ---------------------------------------------------------------------------

require_once 'inc-config.php';
require_once 'inc-response.php';
require_once 'inc-store.php';
require_once 'inc-mq.php';
require_once 'inc-esb.php';
require_once 'inc-irc.php';

// [{name,value},...] -> [lowercase name => string value]
function api_Params($arrRequest_a)
{
    $arrParams = [];
    if (isset($arrRequest_a['parameters']) && is_array($arrRequest_a['parameters']))
    {
        foreach ($arrRequest_a['parameters'] as $varParam)
        {
            if (is_array($varParam)
                && isset($varParam['name']) && is_string($varParam['name'])
                && array_key_exists('value', $varParam) && is_scalar($varParam['value']))
            {
                $arrParams[strtolower(trim($varParam['name']))] = (string)$varParam['value'];
            }
        }
    }
    return $arrParams;
}

$arrRequest = json_decode(file_get_contents('php://input'), true);

if (!is_array($arrRequest) || !isset($arrRequest['command']) || !is_string($arrRequest['command']))
{
    resp_Add(RESULT_ERROR, 'Invalid request', []);
}
else
{
    $arrParams = api_Params($arrRequest);
    $strGuid = '';
    if (isset($arrParams['guid']))
    {
        $strGuid = $arrParams['guid'];
    }
    elseif (isset($_COOKIE[DEVICE_COOKIE]) && is_string($_COOKIE[DEVICE_COOKIE]))
    {
        $strGuid = $_COOKIE[DEVICE_COOKIE];
    }
    try
    {
        irc_Dispatch($arrRequest['command'], $arrParams, $strGuid);
    }
    catch (Exception $objEx)
    {
        error_log('cyborgscif: ' . $objEx->getMessage());
        resp_Clear();
        resp_Add(RESULT_ERROR, 'Server error', []);
    }
}

resp_Send();

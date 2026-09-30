<?php
// ---------------------------------------------------------------------------
// CyborgSCIF - ZOSCII MQ client
//
// Everything goes through the MQ HTTP API, so MQ can be on another machine.
// MQ does the naming, the locking and the lock waiting; nothing here touches
// the queue folders.
//
// Two actions are used:
//   publish  - action=publish, q, r (retention days), msg
//   fetch    - action=fetch, q, after (a message name or a YmdHis time),
//              r present for the message BEFORE the pointer instead of after
//
// A fetch answers with the message itself, the name in Content-Disposition,
// or with JSON when there is nothing (or no queue yet). One message per call,
// so a page of messages is a call per message plus one.
// ---------------------------------------------------------------------------

function mq_ChannelQueue($strChannelGuid_a)
{
    return strtolower(MQ_QUEUE_PREFIX . '-c-' . $strChannelGuid_a);
}

function mq_PersonQueue($strQueueId_a)
{
    return strtolower(MQ_QUEUE_PREFIX . '-p-' . $strQueueId_a);
}

// POST to MQ; [body, headers], or [false, []] when it cannot be reached
function mq_Post($arrFields_a)
{
    $objContext = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($arrFields_a),
            'timeout' => MQ_TIMEOUT,
            'ignore_errors' => true
        ]
    ]);
    $strBody = @file_get_contents(MQ_URL, false, $objContext);
    $arrHeaders = [];
    if (isset($http_response_header))
    {
        $arrHeaders = $http_response_header;
    }
    if ($strBody === false)
    {
        error_log('cyborgscif: MQ unreachable at ' . MQ_URL);
    }
    return [$strBody, $arrHeaders];
}

// publish one payload string; true when MQ accepted it
function mq_Publish($strQueue_a, $intRetentionDays_a, $strPayload_a)
{
    $blnResult = false;
    $arrResponse = mq_Post([
        'action' => 'publish',
        'q' => $strQueue_a,
        'r' => (string)(int)$intRetentionDays_a,
        'msg' => $strPayload_a
    ]);
    if ($arrResponse[0] !== false)
    {
        $arrJSON = json_decode($arrResponse[0], true);
        if (is_array($arrJSON) && isset($arrJSON['error']) && $arrJSON['error'] === '')
        {
            $blnResult = true;
        }
        else
        {
            error_log('cyborgscif: MQ publish to ' . $strQueue_a . ' failed: ' . substr($arrResponse[0], 0, 200));
        }
    }
    return $blnResult;
}

// the filename MQ put in Content-Disposition ('' when it answered with JSON)
function mq_ResponseName($arrHeaders_a)
{
    $strResult = '';
    foreach ($arrHeaders_a as $strHeader)
    {
        if (preg_match('/^Content-Disposition:.*filename="([^"]+)"/i', $strHeader, $arrMatch) === 1)
        {
            $strResult = basename($arrMatch[1]);
        }
    }
    return $strResult;
}

// one message after $strAfter_a (before it when $blnReverse_a), as
// ['name' => .., 'data' => ..], or null when there is none
function mq_Fetch($strQueue_a, $strAfter_a, $blnReverse_a)
{
    $varResult = null;
    $arrFields = ['action' => 'fetch', 'q' => $strQueue_a, 'after' => $strAfter_a];
    // r must be absent to read forwards; its presence alone means backwards
    if ($blnReverse_a)
    {
        $arrFields['r'] = '';
    }
    $arrResponse = mq_Post($arrFields);
    if ($arrResponse[0] !== false)
    {
        $strName = mq_ResponseName($arrResponse[1]);
        if ($strName !== '')
        {
            $varResult = ['name' => $strName, 'data' => $arrResponse[0]];
        }
        else
        {
            mq_LogJSON($strQueue_a, $arrResponse[0]);
        }
    }
    return $varResult;
}

// a JSON answer to a fetch is normal when the queue is empty or not there yet;
// anything else means MQ is turning the call away
function mq_LogJSON($strQueue_a, $strBody_a)
{
    $arrJSON = json_decode($strBody_a, true);
    if (!is_array($arrJSON) || !isset($arrJSON['error']))
    {
        error_log('cyborgscif: MQ fetch from ' . $strQueue_a . ' answered with neither a message nor MQ JSON'
            . ' (is action=fetch allowed?): ' . substr($strBody_a, 0, 200));
    }
    elseif ($arrJSON['error'] !== '' && strpos($arrJSON['error'], 'does not exist') === false)
    {
        error_log('cyborgscif: MQ fetch from ' . $strQueue_a . ' failed: ' . $arrJSON['error']);
    }
}

// the newest name in a queue ('' when it is empty or not there yet)
function mq_Newest($strQueue_a)
{
    $strResult = '';
    $arrMessage = mq_Fetch($strQueue_a, '', true);
    if ($arrMessage !== null)
    {
        $strResult = $arrMessage['name'];
    }
    return $strResult;
}

// up to $intCount_a messages after a pointer, oldest first
function mq_AfterPage($strQueue_a, $strAfter_a, $intCount_a)
{
    $arrResult = [];
    $strAfter = $strAfter_a;
    $blnDone = false;
    while (!$blnDone)
    {
        $arrMessage = mq_Fetch($strQueue_a, $strAfter, false);
        if ($arrMessage === null)
        {
            $blnDone = true;
        }
        else
        {
            $arrResult[] = $arrMessage;
            $strAfter = $arrMessage['name'];
            if (count($arrResult) >= $intCount_a)
            {
                $blnDone = true;
            }
        }
    }
    return $arrResult;
}

// up to $intCount_a messages before $strBefore_a ('' = from the newest),
// oldest first, plus whether older ones exist
function mq_BeforePage($strQueue_a, $strBefore_a, $intCount_a)
{
    $arrPage = [];
    $strBefore = $strBefore_a;
    $blnMore = false;
    $blnDone = false;
    while (!$blnDone)
    {
        $arrMessage = mq_Fetch($strQueue_a, $strBefore, true);
        if ($arrMessage === null)
        {
            $blnDone = true;
        }
        else
        {
            $strBefore = $arrMessage['name'];
            if (count($arrPage) < $intCount_a)
            {
                $arrPage[] = $arrMessage;
            }
            else
            {
                // one older message exists, which is all the client needs to know
                $blnMore = true;
                $blnDone = true;
            }
        }
    }
    return [array_reverse($arrPage), $blnMore];
}

// a fetched payload decoded, or null when it is not one of ours
function mq_Decode($strPayload_a)
{
    $varResult = null;
    $varData = json_decode($strPayload_a, true);
    if (is_array($varData) && isset($varData['r']) && is_array($varData['r']))
    {
        $varResult = $varData;
    }
    return $varResult;
}

// unix time from a message name (YYYYMMDDHHNNSSCCCC-...)
function mq_NameTime($strName_a)
{
    $intResult = strtotime(substr($strName_a, 0, 14));
    if ($intResult === false)
    {
        $intResult = 0;
    }
    return $intResult;
}

// a store datetime (Y-m-d H:i:s) as a pointer: everything from that second on
function mq_TimePointer($strDateTime_a)
{
    return preg_replace('/[^0-9]/', '', $strDateTime_a);
}

function mq_ValidName($strName_a)
{
    return (preg_match('/^[0-9]{18}-[0-9]{4}-[0-9a-f-]{36}\.bin$/', $strName_a) === 1);
}

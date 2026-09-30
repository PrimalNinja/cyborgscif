<?php
// ---------------------------------------------------------------------------
// CyborgSCIF - ESB on ZOSCII MQ
//
// Every channel has a queue, every person has a queue for direct events
// (PRIVMSG/NOTICE to a nick, INVITE, being kicked). An event is published once
// per queue and every device reads the queues it needs with its own pointer.
//
// Queued payload: {"e": event id, "o": origin device, "r": data row}
//   e - one id per event; the same event on several queues is returned once
//   o - devices skip their own events (the sender already has them)
// ---------------------------------------------------------------------------

function esb_Event($arrRow_a, $arrDevice_a)
{
    $strOrigin = '';
    if ($arrDevice_a !== null)
    {
        $strOrigin = $arrDevice_a['oid'];
    }
    return json_encode(['e' => irc_NewGuid(), 'o' => $strOrigin, 'r' => $arrRow_a], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function esb_ToChannel($strChannelGuid_a, $strEvent_a)
{
    return mq_Publish(mq_ChannelQueue($strChannelGuid_a), MQ_CHANNEL_RETENTION_DAYS, $strEvent_a);
}

function esb_ToPerson($arrPerson_a, $strEvent_a)
{
    return mq_Publish(mq_PersonQueue($arrPerson_a['qid']), MQ_PERSON_RETENTION_DAYS, $strEvent_a);
}

// queue => pointer to start from when the device has no pointer for it:
// the later of when the device logged in and when the channel was joined
// (a new device gets channel backlog through HISTORY, not FETCH)
function esb_DeviceQueues($arrDevice_a, $arrPerson_a)
{
    $strCreated = mq_TimePointer($arrDevice_a['created']);
    $arrResult = [];
    $arrResult[mq_PersonQueue($arrPerson_a['qid'])] = $strCreated;
    foreach ($arrPerson_a['channels'] as $arrEntry)
    {
        if ($arrEntry['isactive'])
        {
            $strSince = mq_TimePointer($arrEntry['datetime']);
            if (strcmp($strCreated, $strSince) > 0)
            {
                $strSince = $strCreated;
            }
            $arrResult[mq_ChannelQueue($arrEntry['key'])] = $strSince;
        }
    }
    return $arrResult;
}

// where the device carries on reading a queue
function esb_Pointer($arrDevice_a, $strQueue_a, $strSince_a)
{
    $strResult = $strSince_a;
    if (isset($arrDevice_a['pointers'][$strQueue_a]))
    {
        $arrPointer = $arrDevice_a['pointers'][$strQueue_a];
        // a rejoin starts a new membership, so an old pointer no longer applies
        if ($arrPointer['since'] === $strSince_a)
        {
            $strResult = $arrPointer['after'];
        }
    }
    return $strResult;
}

// the joining device starts after the last message already in the queue, so
// messages from earlier in the same second are not delivered as new
// (the person's other devices start from the join second)
function esb_StartAtEnd($arrDevice_a, $strQueue_a, $strSince_a)
{
    $strAfter = mq_Newest($strQueue_a);
    if (strcmp($strAfter, $strSince_a) < 0)
    {
        $strAfter = $strSince_a;
    }
    store_Update(store_DevicePath($arrDevice_a['guid']), function ($varDevice_a) use ($strQueue_a, $strSince_a, $strAfter)
    {
        $varResult = null;
        if (is_array($varDevice_a))
        {
            $varDevice_a['pointers'][$strQueue_a] = ['since' => $strSince_a, 'after' => $strAfter];
            $varResult = $varDevice_a;
        }
        return $varResult;
    });
}

// PING source 'messages': anything newer than the device's pointers.
// Asking for the next message is the check - it either comes down or it does
// not, and the pointer only moves on a FETCH, so nothing is consumed here.
function esb_HasPending($arrDevice_a, $arrPerson_a)
{
    $blnResult = false;
    foreach (esb_DeviceQueues($arrDevice_a, $arrPerson_a) as $strQueue => $strSince)
    {
        if (!$blnResult)
        {
            $arrMessage = mq_Fetch($strQueue, esb_Pointer($arrDevice_a, $strQueue, $strSince), false);
            if ($arrMessage !== null)
            {
                $blnResult = true;
            }
        }
    }
    return $blnResult;
}

// names of every PING source with something waiting, space delimited ('' = nothing)
function esb_Ping($arrDevice_a, $arrPerson_a)
{
    global $PING_SOURCES;
    $arrNames = [];
    foreach ($PING_SOURCES as $strName => $strFunction)
    {
        if (call_user_func($strFunction, $arrDevice_a, $arrPerson_a))
        {
            $arrNames[] = $strName;
        }
    }
    return implode(' ', $arrNames);
}

// everything new for the device, a queue at a time, in order within each
// queue; moves its pointers on. ESB_FETCH_MAX caps the whole call, since MQ
// hands over one message per HTTP call.
function esb_Fetch($arrDevice_a, $arrPerson_a)
{
    $arrQueues = esb_DeviceQueues($arrDevice_a, $arrPerson_a);
    $arrAfter = [];
    $arrRows = [];
    $arrSeen = [];
    $intBudget = ESB_FETCH_MAX;
    foreach ($arrQueues as $strQueue => $strSince)
    {
        $strAfter = esb_Pointer($arrDevice_a, $strQueue, $strSince);
        if ($intBudget > 0)
        {
            foreach (mq_AfterPage($strQueue, $strAfter, $intBudget) as $arrMessage)
            {
                $strAfter = $arrMessage['name'];
                $intBudget--;
                $arrEvent = mq_Decode($arrMessage['data']);
                if ($arrEvent !== null)
                {
                    $strEventId = (string)$arrEvent['e'];
                    if (!isset($arrSeen[$strEventId]) && (string)$arrEvent['o'] !== $arrDevice_a['oid'])
                    {
                        $arrRows[] = $arrEvent['r'];
                    }
                    $arrSeen[$strEventId] = true;
                }
            }
        }
        $arrAfter[$strQueue] = $strAfter;
    }

    // keep pointers only for queues still read (parted channels drop out)
    $arrPointers = [];
    foreach ($arrQueues as $strQueue => $strSince)
    {
        $arrPointers[$strQueue] = ['since' => $strSince, 'after' => $arrAfter[$strQueue]];
    }
    store_Update(store_DevicePath($arrDevice_a['guid']), function ($varDevice_a) use ($arrPointers)
    {
        $varResult = null;
        if (is_array($varDevice_a))
        {
            $varDevice_a['pointers'] = $arrPointers;
            $varResult = $varDevice_a;
        }
        return $varResult;
    });
    return $arrRows;
}

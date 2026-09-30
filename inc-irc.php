<?php
// ---------------------------------------------------------------------------
// CyborgSCIF - IRC core
// Transport-agnostic: each command takes the device, the person and a
// name=>value parameter array, answers the caller via resp_Add() and reaches
// other people via ZOSCII MQ queues (inc-esb.php). A socket front end can call
// the same functions.
//
// Data rows: IRC params in order, trailing text last.
//   events   : ["PRIVMSG", "nick!user@host", "#chan", "text"]
//   numerics : ["353", "mynick", "=", "#chan", "@julian yenny"]
//
// A device is a connection (one per browser, keyed by its GUID). A person owns
// the nick and the channel list; with the built-in login the person is the
// device GUID, a real login (e.g. CyborgShell) can map many devices to one
// person later. Identities and GUIDs are never sent to clients.
//
// Person channel entries work like CyborgShell spaces:
//   {"key": channel GUID, "alias": "#cpc", "datetime": joined/invited,
//    "isactive": in it now, "level": member|voice|op, "invitedby": nick}
// On a private channel an inactive entry is an invite; public channels drop
// the entry on PART.
// ---------------------------------------------------------------------------

// command => [function, requires registration]
$m_arrCommands = [
    'PING'    => ['irc_CmdPing', false],
    'FETCH'   => ['irc_CmdFetch', false],
    'NICK'    => ['irc_CmdNick', false],
    'USER'    => ['irc_CmdUser', false],
    'QUIT'    => ['irc_CmdQuit', false],
    'JOIN'    => ['irc_CmdJoin', true],
    'PART'    => ['irc_CmdPart', true],
    'PRIVMSG' => ['irc_CmdPrivmsg', true],
    'NOTICE'  => ['irc_CmdNotice', true],
    'TOPIC'   => ['irc_CmdTopic', true],
    'NAMES'   => ['irc_CmdNames', true],
    'LIST'    => ['irc_CmdList', true],
    'WHOIS'   => ['irc_CmdWhois', true],
    'AWAY'    => ['irc_CmdAway', true],
    'KICK'    => ['irc_CmdKick', true],
    'INVITE'  => ['irc_CmdInvite', true],
    'ACCESS'  => ['irc_CmdAccess', true],
    'HISTORY' => ['irc_CmdHistory', true],
    'MODE'    => ['irc_CmdMode', true]
];

// ===========================================================================
// Dispatch
// ===========================================================================

function irc_Dispatch($strCommand_a, $arrParams_a, $strGuid_a)
{
    global $m_arrCommands;
    $strCommand = strtoupper(trim($strCommand_a));
    store_Init();
    irc_ReapStale();
    if ($strCommand == 'LOGIN')
    {
        irc_CmdLogin($strGuid_a);
    }
    else
    {
        $arrDevice = irc_DeviceByGuid($strGuid_a);
        if ($arrDevice === null)
        {
            resp_Add(RESULT_ERROR, 'Not logged in', []);
        }
        else
        {
            irc_Touch($arrDevice);
            $arrPerson = irc_PersonEnsure($arrDevice['identity']);
            if (!isset($m_arrCommands[$strCommand]))
            {
                irc_Error($arrPerson, '421', [$strCommand], 'Unknown command');
            }
            elseif ($m_arrCommands[$strCommand][1] && !$arrPerson['registered'])
            {
                irc_Error($arrPerson, '451', [], 'You have not registered');
            }
            else
            {
                call_user_func($m_arrCommands[$strCommand][0], $arrDevice, $arrPerson, $arrParams_a);
            }
        }
    }
}

// ===========================================================================
// Devices, people, timeouts
// ===========================================================================

function irc_CmdLogin($strGuid_a)
{
    $strGuid = strtolower(trim($strGuid_a));
    if (!irc_ValidGuid($strGuid))
    {
        $strGuid = irc_NewGuid();
    }
    $arrDevice = store_Update(store_DevicePath($strGuid), function ($varDevice_a) use ($strGuid)
    {
        $varResult = $varDevice_a;
        if (!is_array($varDevice_a))
        {
            // identity = GUID until a real login supplies one
            $varResult = [
                'guid' => $strGuid,
                'identity' => $strGuid,
                'oid' => irc_NewGuid(),
                'created' => store_Now(),
                'pointers' => new stdClass()
            ];
        }
        return $varResult;
    });
    $arrPerson = irc_PersonEnsure($arrDevice['identity']);
    $arrRows = [];
    $arrRows[] = ['LOGIN', $strGuid, (string)POLL_INTERVAL, (string)$arrPerson['nick'], (string)(int)$arrPerson['registered']];
    $arrNames = [];
    foreach ($arrPerson['channels'] as $arrEntry)
    {
        $arrChannel = null;
        if ($arrEntry['isactive'])
        {
            $arrChannel = irc_ChannelLoad($arrEntry['key']);
        }
        if ($arrChannel !== null)
        {
            $arrNames[] = $arrChannel['name'];
        }
    }
    if (count($arrNames) > 0)
    {
        sort($arrNames);
        $arrRows[] = array_merge(['CHANNELS'], $arrNames);
    }
    resp_Add(RESULT_SUCCESS, '', $arrRows);
}

function irc_DeviceByGuid($strGuid_a)
{
    $varResult = null;
    $strGuid = strtolower(trim($strGuid_a));
    if (irc_ValidGuid($strGuid))
    {
        $varResult = store_Read(store_DevicePath($strGuid));
    }
    return $varResult;
}

// last call = file time
function irc_Touch($arrDevice_a)
{
    @touch(store_DevicePath($arrDevice_a['guid']));
}

function irc_PersonLoad($strIdentity_a)
{
    return store_Read(store_PersonPath($strIdentity_a));
}

function irc_PersonEnsure($strIdentity_a)
{
    return store_Update(store_PersonPath($strIdentity_a), function ($varPerson_a) use ($strIdentity_a)
    {
        $varResult = $varPerson_a;
        if (!is_array($varPerson_a))
        {
            $varResult = [
                'jsonversion' => 1,
                'identity' => $strIdentity_a,
                'qid' => irc_NewGuid(),
                'nick' => '',
                'lastnick' => '',
                'username' => '',
                'realname' => '',
                'away' => '',
                'usermodes' => '',
                'registered' => false,
                'signon' => 0,
                'lastactive' => 0,
                'channels' => []
            ];
        }
        return $varResult;
    });
}

// change a person under their lock; $fnChange_a gets and returns the person array
function irc_PersonChange($strIdentity_a, $fnChange_a)
{
    return store_Update(store_PersonPath($strIdentity_a), function ($varPerson_a) use ($fnChange_a)
    {
        $varResult = null;
        if (is_array($varPerson_a))
        {
            $varResult = call_user_func($fnChange_a, $varPerson_a);
        }
        return $varResult;
    });
}

function irc_PersonByNick($strNick_a)
{
    $varResult = null;
    if ($strNick_a !== '')
    {
        $strIdentity = store_ClaimOwner(store_NickPath($strNick_a));
        if ($strIdentity !== '')
        {
            $arrPerson = irc_PersonLoad($strIdentity);
            if ($arrPerson !== null && strtolower($arrPerson['nick']) === strtolower($strNick_a))
            {
                $varResult = $arrPerson;
            }
        }
    }
    return $varResult;
}

// every person file (ACCESS and offline KICK)
function irc_AllPeople()
{
    $arrResult = [];
    foreach (store_List(DATA_DIR . 'people/', '*.json') as $strFile)
    {
        $arrPerson = store_Read(DATA_DIR . 'people/' . $strFile);
        if ($arrPerson !== null)
        {
            $arrResult[] = $arrPerson;
        }
    }
    return $arrResult;
}

// drop devices that stopped polling, at most every REAP_INTERVAL seconds
function irc_ReapStale()
{
    $strStamp = DATA_DIR . 'reaper.stamp';
    $intLast = @filemtime($strStamp);
    if ($intLast === false || $intLast < time() - REAP_INTERVAL)
    {
        @touch($strStamp);
        $intCutoff = time() - PING_TIMEOUT;
        foreach (store_List(DATA_DIR . 'devices/', '*.json') as $strFile)
        {
            $strPath = DATA_DIR . 'devices/' . $strFile;
            $intMTime = @filemtime($strPath);
            if ($intMTime !== false && $intMTime < $intCutoff)
            {
                $arrDevice = store_Read($strPath);
                @unlink($strPath);
                if ($arrDevice !== null && !irc_HasLiveDevice($arrDevice['identity']))
                {
                    $arrPerson = irc_PersonLoad($arrDevice['identity']);
                    if ($arrPerson !== null && $arrPerson['registered'])
                    {
                        irc_Signoff($arrPerson, null, 'Ping timeout: ' . PING_TIMEOUT . ' seconds');
                    }
                }
            }
        }
    }
}

function irc_HasLiveDevice($strIdentity_a)
{
    $blnResult = false;
    $intCutoff = time() - PING_TIMEOUT;
    foreach (store_List(DATA_DIR . 'devices/', '*.json') as $strFile)
    {
        $strPath = DATA_DIR . 'devices/' . $strFile;
        if (!$blnResult && @filemtime($strPath) >= $intCutoff)
        {
            $arrDevice = store_Read($strPath);
            $blnResult = ($arrDevice !== null && $arrDevice['identity'] === $strIdentity_a);
        }
    }
    return $blnResult;
}

// QUIT or timeout: tell every channel, leave them, give the nick back
function irc_Signoff($arrPerson_a, $arrDevice_a, $strReason_a)
{
    $strEvent = esb_Event(['QUIT', irc_Prefix($arrPerson_a), $strReason_a], $arrDevice_a);
    foreach ($arrPerson_a['channels'] as $arrEntry)
    {
        if ($arrEntry['isactive'])
        {
            esb_ToChannel($arrEntry['key'], $strEvent);
        }
    }
    $arrPerson = irc_PersonChange($arrPerson_a['identity'], function ($arrPerson_a)
    {
        $arrKeep = [];
        foreach ($arrPerson_a['channels'] as $arrEntry)
        {
            $arrChannel = irc_ChannelLoad($arrEntry['key']);
            if ($arrChannel !== null && irc_IsPrivate($arrChannel))
            {
                // stays invited
                $arrEntry['isactive'] = false;
                $arrKeep[] = $arrEntry;
            }
        }
        $arrPerson_a['channels'] = $arrKeep;
        $arrPerson_a['nick'] = '';
        $arrPerson_a['username'] = '';
        $arrPerson_a['realname'] = '';
        $arrPerson_a['away'] = '';
        $arrPerson_a['usermodes'] = '';
        $arrPerson_a['registered'] = false;
        return $arrPerson_a;
    });
    foreach ($arrPerson_a['channels'] as $arrEntry)
    {
        if ($arrEntry['isactive'])
        {
            @unlink(store_MemberPath($arrEntry['key'], $arrPerson_a['identity']));
            irc_ChannelCleanup($arrEntry['key']);
        }
    }
    if ($arrPerson_a['nick'] !== '' && store_ClaimOwner(store_NickPath($arrPerson_a['nick'])) === $arrPerson_a['identity'])
    {
        store_Release(store_NickPath($arrPerson_a['nick']));
    }
    return $arrPerson;
}

// ===========================================================================
// Connection / registration commands
// ===========================================================================

// keepalive: reports which subsystems have something waiting, fetches nothing
// data: [] when nothing, otherwise [["PING", "messages printjobs ..."]]
function irc_CmdPing($arrDevice_a, $arrPerson_a, $arrParams_a)
{
    $strWaiting = esb_Ping($arrDevice_a, $arrPerson_a);
    $arrRows = [];
    if ($strWaiting !== '')
    {
        $arrRows[] = ['PING', $strWaiting];
    }
    resp_Add(RESULT_SUCCESS, '', $arrRows);
}

// everything new on the device's queues (the 'messages' PING source)
function irc_CmdFetch($arrDevice_a, $arrPerson_a, $arrParams_a)
{
    resp_Add(RESULT_SUCCESS, '', esb_Fetch($arrDevice_a, $arrPerson_a));
}

function irc_CmdNick($arrDevice_a, $arrPerson_a, $arrParams_a)
{
    global $RESERVED_NICKS;
    $strNick = irc_Param($arrParams_a, 'nick');
    if ($strNick === '')
    {
        irc_Error($arrPerson_a, '431', [], 'No nickname given');
    }
    elseif (!irc_ValidNick($strNick) || in_array(strtolower($strNick), $RESERVED_NICKS))
    {
        irc_Error($arrPerson_a, '432', [$strNick], 'Erroneous nickname');
    }
    elseif ($strNick === $arrPerson_a['nick'])
    {
        resp_Add(RESULT_SUCCESS, '', []);
    }
    elseif (!store_Claim(store_NickPath($strNick), $arrPerson_a['identity']))
    {
        irc_Error($arrPerson_a, '433', [$strNick], 'Nickname is already in use');
    }
    else
    {
        $strOld = $arrPerson_a['nick'];
        if ($strOld !== '' && strtolower($strOld) !== strtolower($strNick))
        {
            store_Release(store_NickPath($strOld));
        }
        $arrPerson = irc_PersonChange($arrPerson_a['identity'], function ($arrPerson_a) use ($strNick)
        {
            $arrPerson_a['nick'] = $strNick;
            $arrPerson_a['lastnick'] = $strNick;
            return $arrPerson_a;
        });
        if ($arrPerson_a['registered'])
        {
            $arrRow = ['NICK', irc_Prefix($arrPerson_a), $strNick];
            irc_ToMyChannels($arrPerson_a, esb_Event($arrRow, $arrDevice_a));
            resp_Add(RESULT_SUCCESS, '', [$arrRow]);
        }
        else
        {
            irc_TryRegister($arrPerson);
        }
    }
}

function irc_CmdUser($arrDevice_a, $arrPerson_a, $arrParams_a)
{
    $strUser = preg_replace('/[^A-Za-z0-9_.\-]/', '', irc_Param($arrParams_a, 'username'));
    $strUser = substr($strUser, 0, USERNAME_MAX_LEN);
    $strReal = trim(irc_Text($arrParams_a, 'realname', REALNAME_MAX_LEN));
    if ($strReal === '')
    {
        $strReal = $strUser;
    }
    if ($arrPerson_a['registered'])
    {
        irc_Error($arrPerson_a, '462', [], 'You may not reregister');
    }
    elseif ($strUser === '')
    {
        irc_Error($arrPerson_a, '461', ['USER'], 'Not enough parameters');
    }
    else
    {
        $arrPerson = irc_PersonChange($arrPerson_a['identity'], function ($arrPerson_a) use ($strUser, $strReal)
        {
            $arrPerson_a['username'] = $strUser;
            $arrPerson_a['realname'] = $strReal;
            return $arrPerson_a;
        });
        irc_TryRegister($arrPerson);
    }
}

// registration completes once both NICK and USER are in
function irc_TryRegister($arrPerson_a)
{
    if ($arrPerson_a['nick'] !== '' && $arrPerson_a['username'] !== '')
    {
        irc_Welcome($arrPerson_a);
    }
    else
    {
        resp_Add(RESULT_SUCCESS, '', []);
    }
}

function irc_Welcome($arrPerson_a)
{
    global $MOTD_LINES;
    $arrPerson = irc_PersonChange($arrPerson_a['identity'], function ($arrPerson_a)
    {
        $arrPerson_a['registered'] = true;
        $arrPerson_a['signon'] = time();
        $arrPerson_a['lastactive'] = time();
        return $arrPerson_a;
    });
    $arrRows = [];
    $arrRows[] = irc_Row($arrPerson, '001', [], 'Welcome to the ' . NETWORK_NAME . ' IRC Network ' . irc_Prefix($arrPerson));
    $arrRows[] = irc_Row($arrPerson, '002', [], 'Your host is ' . SERVER_NAME . ', running version ' . SERVER_VERSION);
    $arrRows[] = irc_Row($arrPerson, '003', [], 'This server was created ' . SERVER_CREATED);
    $arrRows[] = irc_Num($arrPerson, '004', [SERVER_NAME, SERVER_VERSION, 'i', 'iklmnotv']);
    $arrRows[] = irc_Row($arrPerson, '005', [
        'CHANTYPES=#',
        'PREFIX=(ov)@+',
        'CHANMODES=,k,l,imnt',
        'CASEMAPPING=ascii',
        'NICKLEN=' . NICK_MAX_LEN,
        'CHANNELLEN=' . CHANNEL_MAX_LEN,
        'TOPICLEN=' . TOPIC_MAX_LEN,
        'NETWORK=' . NETWORK_NAME
    ], 'are supported by this server');
    if (count($MOTD_LINES) > 0)
    {
        $arrRows[] = irc_Row($arrPerson, '375', [], '- ' . SERVER_NAME . ' Message of the day -');
        foreach ($MOTD_LINES as $strLine)
        {
            $arrRows[] = irc_Row($arrPerson, '372', [], '- ' . $strLine);
        }
        $arrRows[] = irc_Row($arrPerson, '376', [], 'End of MOTD command');
    }
    else
    {
        $arrRows[] = irc_Row($arrPerson, '422', [], 'MOTD File is missing');
    }
    resp_Add(RESULT_SUCCESS, '', $arrRows);
}

function irc_CmdQuit($arrDevice_a, $arrPerson_a, $arrParams_a)
{
    $strText = trim(irc_Text($arrParams_a, 'message', TEXT_MAX_LEN));
    $strReason = 'Quit';
    if ($strText !== '')
    {
        $strReason = 'Quit: ' . $strText;
    }
    $arrRow = ['QUIT', irc_Prefix($arrPerson_a), $strReason];
    // the device stays logged in; only the IRC state is reset
    irc_Signoff($arrPerson_a, $arrDevice_a, $strReason);
    resp_Add(RESULT_SUCCESS, '', [$arrRow]);
}

// ===========================================================================
// Channel commands
// ===========================================================================

function irc_CmdJoin($arrDevice_a, $arrPerson_a, $arrParams_a)
{
    $strName = irc_Param($arrParams_a, 'channel');
    $strKey = irc_Param($arrParams_a, 'key');
    if ($strName === '')
    {
        irc_Error($arrPerson_a, '461', ['JOIN'], 'Not enough parameters');
    }
    elseif (!irc_ValidChannel($strName))
    {
        irc_Error($arrPerson_a, '403', [$strName], 'No such channel');
    }
    else
    {
        $arrChannel = irc_ChannelByName($strName);
        $arrEntry = null;
        $blnOwner = false;
        if ($arrChannel !== null)
        {
            $arrEntry = irc_Entry($arrPerson_a, $arrChannel['guid']);
            $blnOwner = irc_IsOwner($arrPerson_a, $arrChannel);
        }
        if ($arrEntry !== null && $arrEntry['isactive'])
        {
            resp_Add(RESULT_SUCCESS, '', []);
        }
        elseif (irc_ActiveCount($arrPerson_a) >= MAX_CHANNELS_PER_PERSON)
        {
            irc_Error($arrPerson_a, '405', [$strName], 'You have joined too many channels');
        }
        elseif ($arrChannel !== null && irc_IsPrivate($arrChannel) && !$blnOwner && $arrEntry === null)
        {
            irc_Error($arrPerson_a, '473', [$arrChannel['name']], 'Cannot join channel (+i)');
        }
        elseif ($arrChannel !== null && !$blnOwner && $arrChannel['key'] !== null && $arrChannel['key'] !== $strKey)
        {
            irc_Error($arrPerson_a, '475', [$arrChannel['name']], 'Cannot join channel (+k)');
        }
        elseif ($arrChannel !== null && !$blnOwner && $arrChannel['limit'] !== null && irc_MemberCount($arrChannel['guid']) >= (int)$arrChannel['limit'])
        {
            irc_Error($arrPerson_a, '471', [$arrChannel['name']], 'Cannot join channel (+l)');
        }
        else
        {
            if ($arrChannel === null)
            {
                $arrChannel = irc_ChannelCreate($strName, $arrPerson_a);
                $blnOwner = irc_IsOwner($arrPerson_a, $arrChannel);
            }
            // ops: owner always, invite level on private channels, first one in on public ones
            $strLevel = 'member';
            if ($blnOwner)
            {
                $strLevel = 'op';
            }
            elseif (irc_IsPrivate($arrChannel) && $arrEntry !== null)
            {
                $strLevel = $arrEntry['level'];
            }
            elseif (!irc_IsPrivate($arrChannel) && irc_MemberCount($arrChannel['guid']) == 0)
            {
                $strLevel = 'op';
            }
            $strGuid = $arrChannel['guid'];
            $strAlias = $arrChannel['name'];
            $strNow = store_Now();
            irc_PersonChange($arrPerson_a['identity'], function ($arrPerson_a) use ($strGuid, $strAlias, $strLevel, $strNow)
            {
                $arrEntry = irc_Entry($arrPerson_a, $strGuid);
                if ($arrEntry === null)
                {
                    $arrEntry = ['key' => $strGuid, 'alias' => $strAlias, 'invitedby' => ''];
                }
                $arrEntry['datetime'] = $strNow;
                $arrEntry['isactive'] = true;
                $arrEntry['level'] = $strLevel;
                return irc_SetEntry($arrPerson_a, $arrEntry);
            });
            @touch(store_MemberPath($strGuid, $arrPerson_a['identity']));
            esb_StartAtEnd($arrDevice_a, mq_ChannelQueue($strGuid), mq_TimePointer($strNow));
            $arrRow = ['JOIN', irc_Prefix($arrPerson_a), $arrChannel['name']];
            esb_ToChannel($strGuid, esb_Event($arrRow, $arrDevice_a));
            $arrRows = [$arrRow];
            if ((string)$arrChannel['topic'] !== '')
            {
                $arrRows[] = irc_Row($arrPerson_a, '332', [$arrChannel['name']], $arrChannel['topic']);
                $arrRows[] = irc_Num($arrPerson_a, '333', [$arrChannel['name'], $arrChannel['topicby'], $arrChannel['topicat']]);
            }
            $arrRows = array_merge($arrRows, irc_NamesRows($arrPerson_a, $arrChannel['name'], $arrChannel));
            resp_Add(RESULT_SUCCESS, '', $arrRows);
        }
    }
}

function irc_CmdPart($arrDevice_a, $arrPerson_a, $arrParams_a)
{
    $strName = irc_Param($arrParams_a, 'channel');
    $strText = trim(irc_Text($arrParams_a, 'message', TEXT_MAX_LEN));
    $arrChannel = null;
    if ($strName !== '')
    {
        $arrChannel = irc_ChannelVisible($arrPerson_a, $strName);
    }
    if ($strName === '')
    {
        irc_Error($arrPerson_a, '461', ['PART'], 'Not enough parameters');
    }
    elseif ($arrChannel === null)
    {
        irc_Error($arrPerson_a, '403', [$strName], 'No such channel');
    }
    elseif (irc_Membership($arrPerson_a, $arrChannel) === null)
    {
        irc_Error($arrPerson_a, '442', [$arrChannel['name']], "You're not on that channel");
    }
    else
    {
        $arrRow = ['PART', irc_Prefix($arrPerson_a), $arrChannel['name'], $strText];
        esb_ToChannel($arrChannel['guid'], esb_Event($arrRow, $arrDevice_a));
        irc_Leave($arrPerson_a['identity'], $arrChannel, false);
        resp_Add(RESULT_SUCCESS, '', [$arrRow]);
    }
}

// leave a channel: private keeps the entry as an invite unless kicked
function irc_Leave($strIdentity_a, $arrChannel_a, $blnKicked_a)
{
    $strGuid = $arrChannel_a['guid'];
    $blnKeep = (irc_IsPrivate($arrChannel_a) && !$blnKicked_a);
    irc_PersonChange($strIdentity_a, function ($arrPerson_a) use ($strGuid, $blnKeep)
    {
        $arrResult = irc_RemoveEntry($arrPerson_a, $strGuid);
        $arrEntry = irc_Entry($arrPerson_a, $strGuid);
        if ($blnKeep && $arrEntry !== null)
        {
            $arrEntry['isactive'] = false;
            $arrResult = irc_SetEntry($arrPerson_a, $arrEntry);
        }
        return $arrResult;
    });
    @unlink(store_MemberPath($strGuid, $strIdentity_a));
    irc_ChannelCleanup($strGuid);
}

function irc_CmdTopic($arrDevice_a, $arrPerson_a, $arrParams_a)
{
    $strName = irc_Param($arrParams_a, 'channel');
    $blnSet = array_key_exists('topic', $arrParams_a);
    $arrChannel = null;
    if ($strName !== '')
    {
        $arrChannel = irc_ChannelVisible($arrPerson_a, $strName);
    }
    if ($strName === '')
    {
        irc_Error($arrPerson_a, '461', ['TOPIC'], 'Not enough parameters');
    }
    elseif ($arrChannel === null)
    {
        irc_Error($arrPerson_a, '403', [$strName], 'No such channel');
    }
    elseif (!$blnSet)
    {
        $arrRows = [];
        if ((string)$arrChannel['topic'] === '')
        {
            $arrRows[] = irc_Row($arrPerson_a, '331', [$arrChannel['name']], 'No topic is set');
        }
        else
        {
            $arrRows[] = irc_Row($arrPerson_a, '332', [$arrChannel['name']], $arrChannel['topic']);
            $arrRows[] = irc_Num($arrPerson_a, '333', [$arrChannel['name'], $arrChannel['topicby'], $arrChannel['topicat']]);
        }
        resp_Add(RESULT_SUCCESS, '', $arrRows);
    }
    elseif (irc_Membership($arrPerson_a, $arrChannel) === null)
    {
        irc_Error($arrPerson_a, '442', [$arrChannel['name']], "You're not on that channel");
    }
    elseif (irc_HasFlag($arrChannel['modes'], 't') && !irc_IsOp($arrPerson_a, $arrChannel))
    {
        irc_Error($arrPerson_a, '482', [$arrChannel['name']], "You're not channel operator");
    }
    else
    {
        $strTopic = trim(irc_Text($arrParams_a, 'topic', TOPIC_MAX_LEN));
        $strBy = irc_Prefix($arrPerson_a);
        store_Update(store_ChannelPath($arrChannel['guid']), function ($varChannel_a) use ($strTopic, $strBy)
        {
            $varResult = null;
            if (is_array($varChannel_a))
            {
                $varChannel_a['topic'] = $strTopic;
                $varChannel_a['topicby'] = $strBy;
                $varChannel_a['topicat'] = time();
                $varResult = $varChannel_a;
            }
            return $varResult;
        });
        $arrRow = ['TOPIC', $strBy, $arrChannel['name'], $strTopic];
        esb_ToChannel($arrChannel['guid'], esb_Event($arrRow, $arrDevice_a));
        resp_Add(RESULT_SUCCESS, '', [$arrRow]);
    }
}

function irc_CmdNames($arrDevice_a, $arrPerson_a, $arrParams_a)
{
    $strName = irc_Param($arrParams_a, 'channel');
    if ($strName === '')
    {
        irc_Error($arrPerson_a, '461', ['NAMES'], 'Not enough parameters');
    }
    else
    {
        resp_Add(RESULT_SUCCESS, '', irc_NamesRows($arrPerson_a, $strName, irc_ChannelVisible($arrPerson_a, $strName)));
    }
}

function irc_CmdList($arrDevice_a, $arrPerson_a, $arrParams_a)
{
    $arrChannels = [];
    foreach (store_List(DATA_DIR . 'channels/', '*') as $strGuid)
    {
        $arrChannel = irc_ChannelLoad($strGuid);
        if ($arrChannel !== null && irc_CanSee($arrPerson_a, $arrChannel))
        {
            $arrChannels[strtolower($arrChannel['name'])] = $arrChannel;
        }
    }
    ksort($arrChannels);
    $arrRows = [];
    foreach ($arrChannels as $arrChannel)
    {
        $arrRows[] = irc_Row($arrPerson_a, '322', [$arrChannel['name'], irc_MemberCount($arrChannel['guid'])], (string)$arrChannel['topic']);
    }
    $arrRows[] = irc_Row($arrPerson_a, '323', [], 'End of LIST');
    resp_Add(RESULT_SUCCESS, '', $arrRows);
}

function irc_CmdKick($arrDevice_a, $arrPerson_a, $arrParams_a)
{
    $strName = irc_Param($arrParams_a, 'channel');
    $strNick = irc_Param($arrParams_a, 'nick');
    $strReason = trim(irc_Text($arrParams_a, 'reason', TEXT_MAX_LEN));
    if ($strReason === '')
    {
        $strReason = irc_Target($arrPerson_a);
    }
    $arrChannel = null;
    $arrTarget = null;
    $arrTargetEntry = null;
    if ($strName !== '')
    {
        $arrChannel = irc_ChannelVisible($arrPerson_a, $strName);
    }
    if ($arrChannel !== null && $strNick !== '')
    {
        $arrTarget = irc_PersonByNick($strNick);
        if ($arrTarget === null && irc_IsPrivate($arrChannel))
        {
            // not online under that nick: match the invite list by last known nick
            $arrTarget = irc_InviteeByLastNick($arrChannel, $strNick);
        }
        if ($arrTarget !== null)
        {
            $arrTargetEntry = irc_Entry($arrTarget, $arrChannel['guid']);
        }
    }
    if ($strName === '' || $strNick === '')
    {
        irc_Error($arrPerson_a, '461', ['KICK'], 'Not enough parameters');
    }
    elseif ($arrChannel === null)
    {
        irc_Error($arrPerson_a, '403', [$strName], 'No such channel');
    }
    elseif (irc_Membership($arrPerson_a, $arrChannel) === null)
    {
        irc_Error($arrPerson_a, '442', [$arrChannel['name']], "You're not on that channel");
    }
    elseif (!irc_IsOp($arrPerson_a, $arrChannel))
    {
        irc_Error($arrPerson_a, '482', [$arrChannel['name']], "You're not channel operator");
    }
    elseif ($arrTarget !== null && irc_IsOwner($arrTarget, $arrChannel))
    {
        irc_Error($arrPerson_a, '482', [$arrChannel['name']], 'Cannot kick the channel owner');
    }
    elseif ($arrTargetEntry !== null && $arrTargetEntry['isactive'])
    {
        $arrRow = ['KICK', irc_Prefix($arrPerson_a), $arrChannel['name'], $arrTarget['nick'], $strReason];
        $strEvent = esb_Event($arrRow, $arrDevice_a);
        esb_ToChannel($arrChannel['guid'], $strEvent);
        // the kicked person stops reading the channel queue, so they get it directly too
        esb_ToPerson($arrTarget, $strEvent);
        irc_Leave($arrTarget['identity'], $arrChannel, true);
        resp_Add(RESULT_SUCCESS, '', [$arrRow]);
    }
    elseif ($arrTargetEntry !== null && irc_IsPrivate($arrChannel))
    {
        $strNickShown = $arrTarget['lastnick'];
        irc_Leave($arrTarget['identity'], $arrChannel, true);
        resp_Add(
            RESULT_SUCCESS,
            $strNickShown . ' removed from ' . $arrChannel['name'] . ' invite list',
            [['UNINVITE', irc_Prefix($arrPerson_a), $arrChannel['name'], $strNickShown]]
        );
    }
    else
    {
        irc_Error($arrPerson_a, '441', [$strNick, $arrChannel['name']], "They aren't on that channel");
    }
}

// ===========================================================================
// Messaging
// ===========================================================================

function irc_CmdPrivmsg($arrDevice_a, $arrPerson_a, $arrParams_a)
{
    irc_SendText($arrDevice_a, $arrPerson_a, $arrParams_a, 'PRIVMSG');
}

function irc_CmdNotice($arrDevice_a, $arrPerson_a, $arrParams_a)
{
    irc_SendText($arrDevice_a, $arrPerson_a, $arrParams_a, 'NOTICE');
}

// NOTICE never generates error replies (RFC 2812)
function irc_SendText($arrDevice_a, $arrPerson_a, $arrParams_a, $strVerb_a)
{
    $blnNotice = ($strVerb_a == 'NOTICE');
    $strTarget = irc_Param($arrParams_a, 'target');
    $strText = irc_Text($arrParams_a, 'text', TEXT_MAX_LEN);
    $arrRows = [];
    $arrError = null;
    $blnSent = false;
    if ($strTarget === '')
    {
        $arrError = ['411', [], 'No recipient given (' . $strVerb_a . ')'];
    }
    elseif (trim($strText) === '')
    {
        $arrError = ['412', [], 'No text to send'];
    }
    elseif (substr($strTarget, 0, 1) == '#')
    {
        $arrChannel = irc_ChannelVisible($arrPerson_a, $strTarget);
        if ($arrChannel === null)
        {
            $arrError = ['403', [$strTarget], 'No such channel'];
        }
        else
        {
            $arrMember = irc_Membership($arrPerson_a, $arrChannel);
            if ($arrMember === null && irc_HasFlag($arrChannel['modes'], 'n'))
            {
                $arrError = ['404', [$arrChannel['name']], 'Cannot send to channel'];
            }
            elseif (irc_HasFlag($arrChannel['modes'], 'm') && ($arrMember === null || $arrMember['level'] === 'member'))
            {
                $arrError = ['404', [$arrChannel['name']], 'Cannot send to channel'];
            }
            else
            {
                $blnSent = esb_ToChannel($arrChannel['guid'], esb_Event([$strVerb_a, irc_Prefix($arrPerson_a), $arrChannel['name'], $strText], $arrDevice_a));
            }
        }
    }
    else
    {
        $arrTarget = irc_PersonByNick($strTarget);
        if ($arrTarget === null || !$arrTarget['registered'])
        {
            $arrError = ['401', [$strTarget], 'No such nick/channel'];
        }
        else
        {
            $blnSent = esb_ToPerson($arrTarget, esb_Event([$strVerb_a, irc_Prefix($arrPerson_a), $arrTarget['nick'], $strText], $arrDevice_a));
            if (!$blnNotice && (string)$arrTarget['away'] !== '')
            {
                $arrRows[] = irc_Row($arrPerson_a, '301', [$arrTarget['nick']], $arrTarget['away']);
            }
        }
    }
    if ($arrError !== null && !$blnNotice)
    {
        irc_Error($arrPerson_a, $arrError[0], $arrError[1], $arrError[2]);
    }
    elseif ($arrError === null && !$blnSent && !$blnNotice)
    {
        resp_Add(RESULT_ERROR, 'Message could not be delivered, try again', []);
    }
    else
    {
        if ($blnSent)
        {
            irc_PersonChange($arrPerson_a['identity'], function ($arrPerson_a)
            {
                $arrPerson_a['lastactive'] = time();
                return $arrPerson_a;
            });
        }
        resp_Add(RESULT_SUCCESS, '', $arrRows);
    }
}

// ===========================================================================
// User queries / state
// ===========================================================================

function irc_CmdWhois($arrDevice_a, $arrPerson_a, $arrParams_a)
{
    $strNick = irc_Param($arrParams_a, 'nick');
    $arrTarget = null;
    if ($strNick !== '')
    {
        $arrTarget = irc_PersonByNick($strNick);
    }
    if ($strNick === '')
    {
        irc_Error($arrPerson_a, '431', [], 'No nickname given');
    }
    elseif ($arrTarget === null || !$arrTarget['registered'])
    {
        irc_Error($arrPerson_a, '401', [$strNick], 'No such nick/channel');
    }
    else
    {
        $strTargetNick = $arrTarget['nick'];
        $arrRows = [];
        $arrRows[] = irc_Row($arrPerson_a, '311', [$strTargetNick, $arrTarget['username'], USER_HOST, '*'], (string)$arrTarget['realname']);
        $arrNames = [];
        foreach ($arrTarget['channels'] as $arrEntry)
        {
            $arrChannel = null;
            if ($arrEntry['isactive'])
            {
                $arrChannel = irc_ChannelLoad($arrEntry['key']);
            }
            // private channels only shown to people who can see them
            if ($arrChannel !== null && irc_CanSee($arrPerson_a, $arrChannel))
            {
                $arrNames[strtolower($arrChannel['name'])] = irc_LevelPrefix($arrEntry['level']) . $arrChannel['name'];
            }
        }
        if (count($arrNames) > 0)
        {
            ksort($arrNames);
            $arrRows[] = irc_Row($arrPerson_a, '319', [$strTargetNick], implode(' ', $arrNames));
        }
        $arrRows[] = irc_Row($arrPerson_a, '312', [$strTargetNick, SERVER_NAME], NETWORK_NAME);
        if ((string)$arrTarget['away'] !== '')
        {
            $arrRows[] = irc_Row($arrPerson_a, '301', [$strTargetNick], $arrTarget['away']);
        }
        $intIdle = time() - (int)$arrTarget['lastactive'];
        $arrRows[] = irc_Row($arrPerson_a, '317', [$strTargetNick, $intIdle, $arrTarget['signon']], 'seconds idle, signon time');
        $arrRows[] = irc_Row($arrPerson_a, '318', [$strTargetNick], 'End of WHOIS list');
        resp_Add(RESULT_SUCCESS, '', $arrRows);
    }
}

function irc_CmdAway($arrDevice_a, $arrPerson_a, $arrParams_a)
{
    $strText = trim(irc_Text($arrParams_a, 'message', AWAY_MAX_LEN));
    irc_PersonChange($arrPerson_a['identity'], function ($arrPerson_a) use ($strText)
    {
        $arrPerson_a['away'] = $strText;
        return $arrPerson_a;
    });
    $arrRows = [];
    if ($strText === '')
    {
        $arrRows[] = irc_Row($arrPerson_a, '305', [], 'You are no longer marked as being away');
    }
    else
    {
        $arrRows[] = irc_Row($arrPerson_a, '306', [], 'You have been marked as being away');
    }
    resp_Add(RESULT_SUCCESS, '', $arrRows);
}

// ===========================================================================
// MODE
// ===========================================================================

function irc_CmdMode($arrDevice_a, $arrPerson_a, $arrParams_a)
{
    $strTarget = irc_Param($arrParams_a, 'target');
    $strModes = irc_Param($arrParams_a, 'modes');
    $arrArgs = preg_split('/\s+/', irc_Param($arrParams_a, 'args'), -1, PREG_SPLIT_NO_EMPTY);
    if ($strTarget === '')
    {
        irc_Error($arrPerson_a, '461', ['MODE'], 'Not enough parameters');
    }
    elseif (substr($strTarget, 0, 1) == '#')
    {
        irc_ChannelMode($arrDevice_a, $arrPerson_a, $strTarget, $strModes, $arrArgs);
    }
    else
    {
        irc_UserMode($arrPerson_a, $strTarget, $strModes);
    }
}

function irc_UserMode($arrPerson_a, $strTarget_a, $strModes_a)
{
    $arrTarget = irc_PersonByNick($strTarget_a);
    if ($arrTarget === null)
    {
        irc_Error($arrPerson_a, '401', [$strTarget_a], 'No such nick/channel');
    }
    elseif ($arrTarget['identity'] !== $arrPerson_a['identity'])
    {
        irc_Error($arrPerson_a, '502', [], 'Cannot change mode for other users');
    }
    elseif ($strModes_a === '')
    {
        resp_Add(RESULT_SUCCESS, '', [irc_Num($arrPerson_a, '221', ['+' . $arrPerson_a['usermodes']])]);
    }
    else
    {
        $strFlags = $arrPerson_a['usermodes'];
        $strSign = '+';
        $arrChange = ['', '', []];
        $blnUnknown = false;
        for ($intI = 0; $intI < strlen($strModes_a); $intI++)
        {
            $strChar = $strModes_a[$intI];
            if ($strChar == '+' || $strChar == '-')
            {
                $strSign = $strChar;
            }
            elseif ($strChar == 'i')
            {
                $strFlags = irc_FlagToggle($strFlags, $strSign, $strChar, $arrChange);
            }
            else
            {
                $blnUnknown = true;
            }
        }
        $arrRows = [];
        if ($arrChange[0] !== '')
        {
            irc_PersonChange($arrPerson_a['identity'], function ($arrPerson_a) use ($strFlags)
            {
                $arrPerson_a['usermodes'] = $strFlags;
                return $arrPerson_a;
            });
            $arrRows[] = ['MODE', $arrPerson_a['nick'], $arrPerson_a['nick'], $arrChange[0]];
        }
        if (count($arrRows) > 0 || !$blnUnknown)
        {
            resp_Add(RESULT_SUCCESS, '', $arrRows);
        }
        if ($blnUnknown)
        {
            irc_Error($arrPerson_a, '501', [], 'Unknown MODE flag');
        }
    }
}

function irc_ChannelMode($arrDevice_a, $arrPerson_a, $strName_a, $strModes_a, $arrArgs_a)
{
    $arrChannel = irc_ChannelVisible($arrPerson_a, $strName_a);
    $arrMember = null;
    if ($arrChannel !== null)
    {
        $arrMember = irc_Membership($arrPerson_a, $arrChannel);
    }
    if ($arrChannel === null)
    {
        irc_Error($arrPerson_a, '403', [$strName_a], 'No such channel');
    }
    elseif ($strModes_a === '')
    {
        $strFlags = '+' . $arrChannel['modes'];
        $arrFlagArgs = [];
        if ($arrChannel['key'] !== null)
        {
            $strFlags .= 'k';
            if ($arrMember !== null)
            {
                $arrFlagArgs[] = $arrChannel['key'];
            }
            else
            {
                $arrFlagArgs[] = '*';
            }
        }
        if ($arrChannel['limit'] !== null)
        {
            $strFlags .= 'l';
            $arrFlagArgs[] = $arrChannel['limit'];
        }
        $arrRows = [];
        $arrRows[] = irc_Num($arrPerson_a, '324', array_merge([$arrChannel['name'], $strFlags], $arrFlagArgs));
        $arrRows[] = irc_Num($arrPerson_a, '329', [$arrChannel['name'], $arrChannel['created']]);
        resp_Add(RESULT_SUCCESS, '', $arrRows);
    }
    elseif ($arrMember === null)
    {
        irc_Error($arrPerson_a, '442', [$arrChannel['name']], "You're not on that channel");
    }
    elseif (!irc_IsOp($arrPerson_a, $arrChannel))
    {
        irc_Error($arrPerson_a, '482', [$arrChannel['name']], "You're not channel operator");
    }
    else
    {
        irc_ChannelModeApply($arrDevice_a, $arrPerson_a, $arrChannel, $strModes_a, $arrArgs_a);
    }
}

function irc_ChannelModeApply($arrDevice_a, $arrPerson_a, $arrChannel_a, $strModes_a, $arrArgs_a)
{
    $strFlags = $arrChannel_a['modes'];
    $varKey = $arrChannel_a['key'];
    $varLimit = $arrChannel_a['limit'];
    $strSign = '+';
    $intArg = 0;
    $intArgCount = count($arrArgs_a);
    $arrChange = ['', '', []];      // [mode string, last sign written, args]
    $arrErrors = [];
    for ($intI = 0; $intI < strlen($strModes_a); $intI++)
    {
        $strChar = $strModes_a[$intI];
        if ($strChar == '+' || $strChar == '-')
        {
            $strSign = $strChar;
        }
        elseif ($strChar == 'i')
        {
            if (!irc_IsOwner($arrPerson_a, $arrChannel_a))
            {
                $arrErrors[] = ['482', [$arrChannel_a['name']], 'Only the channel owner can change +i'];
            }
            else
            {
                $blnWasPrivate = irc_HasFlag($strFlags, 'i');
                $strFlags = irc_FlagToggle($strFlags, $strSign, 'i', $arrChange);
                if ($strSign == '+' && !$blnWasPrivate)
                {
                    // private channels always block outside messages; current members
                    // already have entries, which now act as their invites
                    $strFlags = irc_FlagToggle($strFlags, '+', 'n', $arrChange);
                }
            }
        }
        elseif ($strChar == 'm' || $strChar == 'n' || $strChar == 't')
        {
            // -n is refused while the channel is private
            if (!($strChar == 'n' && $strSign == '-' && irc_HasFlag($strFlags, 'i')))
            {
                $strFlags = irc_FlagToggle($strFlags, $strSign, $strChar, $arrChange);
            }
        }
        elseif ($strChar == 'k')
        {
            if ($strSign == '+')
            {
                if ($intArg < $intArgCount)
                {
                    $varKey = irc_Clip($arrArgs_a[$intArg], KEY_MAX_LEN);
                    $intArg++;
                    irc_ModeRecord($arrChange, '+', 'k', $varKey);
                }
            }
            else
            {
                if ($intArg < $intArgCount)
                {
                    $intArg++;
                }
                if ($varKey !== null)
                {
                    $varKey = null;
                    irc_ModeRecord($arrChange, '-', 'k', '*');
                }
            }
        }
        elseif ($strChar == 'l')
        {
            if ($strSign == '+')
            {
                if ($intArg < $intArgCount)
                {
                    $intLimit = (int)$arrArgs_a[$intArg];
                    $intArg++;
                    if ($intLimit > 0)
                    {
                        $varLimit = $intLimit;
                        irc_ModeRecord($arrChange, '+', 'l', (string)$intLimit);
                    }
                }
            }
            elseif ($varLimit !== null)
            {
                $varLimit = null;
                irc_ModeRecord($arrChange, '-', 'l', null);
            }
        }
        elseif ($strChar == 'o' || $strChar == 'v')
        {
            if ($intArg < $intArgCount)
            {
                $strNick = $arrArgs_a[$intArg];
                $intArg++;
                $arrTarget = irc_PersonByNick($strNick);
                $arrTargetMember = null;
                if ($arrTarget !== null)
                {
                    $arrTargetMember = irc_Membership($arrTarget, $arrChannel_a);
                }
                if ($arrTargetMember === null)
                {
                    $arrErrors[] = ['441', [$strNick, $arrChannel_a['name']], "They aren't on that channel"];
                }
                elseif ($strChar == 'o' && $strSign == '-' && irc_IsOwner($arrTarget, $arrChannel_a))
                {
                    $arrErrors[] = ['482', [$arrChannel_a['name']], 'Cannot deop the channel owner'];
                }
                else
                {
                    // op outranks voice; the level is kept on the entry, so on a
                    // private channel it comes back on rejoin
                    $strGuid = $arrChannel_a['guid'];
                    $strMode = $strSign . $strChar;
                    irc_PersonChange($arrTarget['identity'], function ($arrPerson_a) use ($strGuid, $strMode)
                    {
                        $arrEntry = irc_Entry($arrPerson_a, $strGuid);
                        $varResult = null;
                        if ($arrEntry !== null)
                        {
                            if ($strMode == '+o')
                            {
                                $arrEntry['level'] = 'op';
                            }
                            elseif ($strMode == '-o' && $arrEntry['level'] == 'op')
                            {
                                $arrEntry['level'] = 'member';
                            }
                            elseif ($strMode == '+v' && $arrEntry['level'] != 'op')
                            {
                                $arrEntry['level'] = 'voice';
                            }
                            elseif ($strMode == '-v' && $arrEntry['level'] == 'voice')
                            {
                                $arrEntry['level'] = 'member';
                            }
                            $varResult = irc_SetEntry($arrPerson_a, $arrEntry);
                        }
                        return $varResult;
                    });
                    irc_ModeRecord($arrChange, $strSign, $strChar, $arrTarget['nick']);
                }
            }
        }
        else
        {
            $arrErrors[] = ['472', [$strChar], 'is unknown mode char to me'];
        }
    }
    $arrRows = [];
    if ($arrChange[0] !== '')
    {
        store_Update(store_ChannelPath($arrChannel_a['guid']), function ($varChannel_a) use ($strFlags, $varKey, $varLimit)
        {
            $varResult = null;
            if (is_array($varChannel_a))
            {
                $varChannel_a['modes'] = $strFlags;
                $varChannel_a['key'] = $varKey;
                $varChannel_a['limit'] = $varLimit;
                $varResult = $varChannel_a;
            }
            return $varResult;
        });
        $arrRow = array_merge(['MODE', irc_Prefix($arrPerson_a), $arrChannel_a['name'], $arrChange[0]], $arrChange[2]);
        esb_ToChannel($arrChannel_a['guid'], esb_Event($arrRow, $arrDevice_a));
        $arrRows[] = $arrRow;
    }
    if (count($arrRows) > 0 || count($arrErrors) == 0)
    {
        resp_Add(RESULT_SUCCESS, '', $arrRows);
    }
    foreach ($arrErrors as $arrError)
    {
        irc_Error($arrPerson_a, $arrError[0], $arrError[1], $arrError[2]);
    }
}

// set/clear a single-letter flag, recording the change
function irc_FlagToggle($strFlags_a, $strSign_a, $strChar_a, &$arrChange_a)
{
    $strFlags = $strFlags_a;
    if ($strSign_a == '+' && !irc_HasFlag($strFlags, $strChar_a))
    {
        $strFlags .= $strChar_a;
        irc_ModeRecord($arrChange_a, '+', $strChar_a, null);
    }
    elseif ($strSign_a == '-' && irc_HasFlag($strFlags, $strChar_a))
    {
        $strFlags = str_replace($strChar_a, '', $strFlags);
        irc_ModeRecord($arrChange_a, '-', $strChar_a, null);
    }
    return $strFlags;
}

function irc_ModeRecord(&$arrChange_a, $strSign_a, $strChar_a, $varArg_a)
{
    if ($arrChange_a[1] !== $strSign_a)
    {
        $arrChange_a[0] .= $strSign_a;
        $arrChange_a[1] = $strSign_a;
    }
    $arrChange_a[0] .= $strChar_a;
    if ($varArg_a !== null)
    {
        $arrChange_a[2][] = $varArg_a;
    }
}

// ===========================================================================
// Private channels
// Owner = creator. +i (owner only) makes a channel private: it stays when
// empty, JOIN needs an entry (invite), ops invite/kick, a kick removes the
// entry, the level on the entry comes back on rejoin, outsiders can't see it.
// ===========================================================================

function irc_CmdInvite($arrDevice_a, $arrPerson_a, $arrParams_a)
{
    $strNick = irc_Param($arrParams_a, 'nick');
    $strName = irc_Param($arrParams_a, 'channel');
    $arrChannel = null;
    $arrMember = null;
    $arrTarget = null;
    if ($strName !== '')
    {
        $arrChannel = irc_ChannelVisible($arrPerson_a, $strName);
    }
    if ($arrChannel !== null)
    {
        $arrMember = irc_Membership($arrPerson_a, $arrChannel);
    }
    if ($strNick !== '')
    {
        $arrTarget = irc_PersonByNick($strNick);
    }
    if ($strNick === '' || $strName === '')
    {
        irc_Error($arrPerson_a, '461', ['INVITE'], 'Not enough parameters');
    }
    elseif ($arrChannel === null)
    {
        irc_Error($arrPerson_a, '403', [$strName], 'No such channel');
    }
    elseif ($arrMember === null)
    {
        irc_Error($arrPerson_a, '442', [$arrChannel['name']], "You're not on that channel");
    }
    elseif (irc_IsPrivate($arrChannel) && !irc_IsOp($arrPerson_a, $arrChannel))
    {
        irc_Error($arrPerson_a, '482', [$arrChannel['name']], "You're not channel operator");
    }
    elseif ($arrTarget === null || !$arrTarget['registered'])
    {
        irc_Error($arrPerson_a, '401', [$strNick], 'No such nick/channel');
    }
    elseif (irc_Membership($arrTarget, $arrChannel) !== null)
    {
        irc_Error($arrPerson_a, '443', [$arrTarget['nick'], $arrChannel['name']], 'is already on channel');
    }
    else
    {
        if (irc_IsPrivate($arrChannel) && !irc_IsOwner($arrTarget, $arrChannel))
        {
            $strGuid = $arrChannel['guid'];
            $strAlias = $arrChannel['name'];
            $strBy = $arrPerson_a['nick'];
            irc_PersonChange($arrTarget['identity'], function ($arrPerson_a) use ($strGuid, $strAlias, $strBy)
            {
                $varResult = null;
                if (irc_Entry($arrPerson_a, $strGuid) === null)
                {
                    $varResult = irc_SetEntry($arrPerson_a, [
                        'key' => $strGuid,
                        'alias' => $strAlias,
                        'datetime' => store_Now(),
                        'isactive' => false,
                        'level' => 'member',
                        'invitedby' => $strBy
                    ]);
                }
                return $varResult;
            });
        }
        esb_ToPerson($arrTarget, esb_Event(['INVITE', irc_Prefix($arrPerson_a), $arrTarget['nick'], $arrChannel['name']], $arrDevice_a));
        resp_Add(RESULT_SUCCESS, '', [irc_Num($arrPerson_a, '341', [$arrTarget['nick'], $arrChannel['name']])]);
    }
}

// invite list of a private channel, ops only
// rows: ["ACCESS", "#chan", nick, "owner"|"op"|"voice"|"member", invitedByNick]
function irc_CmdAccess($arrDevice_a, $arrPerson_a, $arrParams_a)
{
    $strName = irc_Param($arrParams_a, 'channel');
    $arrChannel = null;
    $arrMember = null;
    if ($strName !== '')
    {
        $arrChannel = irc_ChannelVisible($arrPerson_a, $strName);
    }
    if ($arrChannel !== null)
    {
        $arrMember = irc_Membership($arrPerson_a, $arrChannel);
    }
    if ($strName === '')
    {
        irc_Error($arrPerson_a, '461', ['ACCESS'], 'Not enough parameters');
    }
    elseif ($arrChannel === null)
    {
        irc_Error($arrPerson_a, '403', [$strName], 'No such channel');
    }
    elseif (!irc_IsPrivate($arrChannel))
    {
        resp_Add(RESULT_ERROR, $arrChannel['name'] . ': Channel is not private', []);
    }
    elseif ($arrMember === null)
    {
        irc_Error($arrPerson_a, '442', [$arrChannel['name']], "You're not on that channel");
    }
    elseif (!irc_IsOp($arrPerson_a, $arrChannel))
    {
        irc_Error($arrPerson_a, '482', [$arrChannel['name']], "You're not channel operator");
    }
    else
    {
        $strOwnerNick = '';
        $arrList = [];
        foreach (irc_AllPeople() as $arrOther)
        {
            $arrEntry = irc_Entry($arrOther, $arrChannel['guid']);
            if ($arrOther['identity'] === $arrChannel['owner'])
            {
                $strOwnerNick = $arrOther['lastnick'];
            }
            elseif ($arrEntry !== null)
            {
                $arrList[strtolower($arrOther['lastnick']) . ' ' . $arrOther['identity']] = [
                    'ACCESS', $arrChannel['name'], (string)$arrOther['lastnick'], $arrEntry['level'], (string)$arrEntry['invitedby']
                ];
            }
        }
        ksort($arrList);
        $arrRows = array_merge([['ACCESS', $arrChannel['name'], $strOwnerNick, 'owner', '']], array_values($arrList));
        resp_Add(RESULT_SUCCESS, '', $arrRows);
    }
}

// someone with an entry on the channel whose last nick matches (offline KICK)
function irc_InviteeByLastNick($arrChannel_a, $strNick_a)
{
    $varResult = null;
    foreach (irc_AllPeople() as $arrOther)
    {
        if ($varResult === null && strtolower($arrOther['lastnick']) === strtolower($strNick_a)
            && irc_Entry($arrOther, $arrChannel_a['guid']) !== null)
        {
            $varResult = $arrOther;
        }
    }
    return $varResult;
}

// ===========================================================================
// History (the channel's ZOSCII MQ queue, read backwards from the tail)
// ===========================================================================

// params: channel, tail (message id, empty = from the end), count
// rows  : ["HISTORY", "#chan", id, time, <original event row...>] oldest first
// last  : ["HISTORYEND", "#chan", oldestId, more(0/1)] - send oldestId as the next tail
function irc_CmdHistory($arrDevice_a, $arrPerson_a, $arrParams_a)
{
    $strName = irc_Param($arrParams_a, 'channel');
    $strTail = decodeID(irc_Param($arrParams_a, 'tail'));
    $intCount = (int)irc_Param($arrParams_a, 'count');
    if ($intCount < 1)
    {
        $intCount = HISTORY_PAGE_SIZE;
    }
    if ($intCount > HISTORY_PAGE_MAX)
    {
        $intCount = HISTORY_PAGE_MAX;
    }
    $arrChannel = null;
    if ($strName !== '')
    {
        $arrChannel = irc_ChannelVisible($arrPerson_a, $strName);
    }
    if ($strName === '')
    {
        irc_Error($arrPerson_a, '461', ['HISTORY'], 'Not enough parameters');
    }
    elseif ($arrChannel === null)
    {
        irc_Error($arrPerson_a, '403', [$strName], 'No such channel');
    }
    elseif ($strTail !== '' && !mq_ValidName($strTail))
    {
        resp_Add(RESULT_ERROR, $arrChannel['name'] . ': Invalid message id', []);
    }
    else
    {
        $strQueue = mq_ChannelQueue($arrChannel['guid']);
        $arrPage = mq_BeforePage($strQueue, $strTail, $intCount);
        $arrRows = [];
        $strOldest = '';
        foreach ($arrPage[0] as $arrMessage)
        {
            $strMessage = $arrMessage['name'];
            $arrEvent = mq_Decode($arrMessage['data']);
            if ($arrEvent !== null)
            {
                if ($strOldest === '')
                {
                    $strOldest = encodeID($strMessage);
                }
                $arrRows[] = array_merge(
                    ['HISTORY', $arrChannel['name'], encodeID($strMessage), (string)mq_NameTime($strMessage)],
                    $arrEvent['r']
                );
            }
        }
        $arrRows[] = ['HISTORYEND', $arrChannel['name'], $strOldest, (string)(int)$arrPage[1]];
        resp_Add(RESULT_SUCCESS, '', $arrRows);
    }
}

// message id as the client sees it (the MQ message name for now)
function encodeID($strID_a)
{
    return $strID_a;
}

function decodeID($strID_a)
{
    return $strID_a;
}

// ===========================================================================
// Channels
// ===========================================================================

function irc_ChannelLoad($strGuid_a)
{
    $varResult = null;
    if ($strGuid_a !== '')
    {
        $varResult = store_Read(store_ChannelPath($strGuid_a));
    }
    return $varResult;
}

function irc_ChannelByName($strName_a)
{
    return irc_ChannelLoad(store_ClaimOwner(store_ChannelNamePath($strName_a)));
}

// the creator owns it; if someone else created it at the same moment, theirs wins
function irc_ChannelCreate($strName_a, $arrPerson_a)
{
    $strGuid = irc_NewGuid();
    $strDir = store_ChannelDir($strGuid);
    @mkdir($strDir . 'members', 0755, true);
    $arrChannel = [
        'guid' => $strGuid,
        'name' => $strName_a,
        'owner' => $arrPerson_a['identity'],
        'created' => time(),
        'topic' => '',
        'topicby' => '',
        'topicat' => 0,
        'modes' => DEFAULT_CHANNEL_MODES,
        'key' => null,
        'limit' => null
    ];
    store_Write(store_ChannelPath($strGuid), $arrChannel);
    $strNamePath = store_ChannelNamePath($strName_a);
    if (!store_Claim($strNamePath, $strGuid))
    {
        $arrExisting = irc_ChannelLoad(store_ClaimOwner($strNamePath));
        if ($arrExisting === null)
        {
            // left behind by a channel that no longer exists
            store_Release($strNamePath);
            store_Claim($strNamePath, $strGuid);
        }
        else
        {
            store_RemoveDir($strDir);
            $arrChannel = $arrExisting;
        }
    }
    return $arrChannel;
}

// a public channel goes when the last person leaves (its queue expires by retention)
function irc_ChannelCleanup($strGuid_a)
{
    $arrChannel = irc_ChannelLoad($strGuid_a);
    if ($arrChannel !== null && !irc_IsPrivate($arrChannel) && irc_MemberCount($strGuid_a) == 0)
    {
        $strNamePath = store_ChannelNamePath($arrChannel['name']);
        if (store_ClaimOwner($strNamePath) === $strGuid_a)
        {
            store_Release($strNamePath);
        }
        store_RemoveDir(store_ChannelDir($strGuid_a));
    }
}

function irc_Members($strGuid_a)
{
    return store_List(store_ChannelDir($strGuid_a) . 'members/', '*');
}

function irc_MemberCount($strGuid_a)
{
    return count(irc_Members($strGuid_a));
}

function irc_IsPrivate($arrChannel_a)
{
    return irc_HasFlag($arrChannel_a['modes'], 'i');
}

function irc_IsOwner($arrPerson_a, $arrChannel_a)
{
    return ((string)$arrChannel_a['owner'] !== '' && $arrChannel_a['owner'] === $arrPerson_a['identity']);
}

function irc_IsOp($arrPerson_a, $arrChannel_a)
{
    $arrMember = irc_Membership($arrPerson_a, $arrChannel_a);
    return (irc_IsOwner($arrPerson_a, $arrChannel_a) || ($arrMember !== null && $arrMember['level'] === 'op'));
}

// active entry = in the channel now
function irc_Membership($arrPerson_a, $arrChannel_a)
{
    $varResult = irc_Entry($arrPerson_a, $arrChannel_a['guid']);
    if ($varResult !== null && !$varResult['isactive'])
    {
        $varResult = null;
    }
    return $varResult;
}

// public: everyone; private: owner or anyone with an entry (in it or invited)
function irc_CanSee($arrPerson_a, $arrChannel_a)
{
    return (!irc_IsPrivate($arrChannel_a)
        || irc_IsOwner($arrPerson_a, $arrChannel_a)
        || irc_Entry($arrPerson_a, $arrChannel_a['guid']) !== null);
}

// channel lookup that treats a private channel as missing for outsiders
function irc_ChannelVisible($arrPerson_a, $strName_a)
{
    $arrChannel = irc_ChannelByName($strName_a);
    if ($arrChannel !== null && !irc_CanSee($arrPerson_a, $arrChannel))
    {
        $arrChannel = null;
    }
    return $arrChannel;
}

function irc_NamesRows($arrPerson_a, $strName_a, $arrChannel_a)
{
    $arrRows = [];
    $strName = $strName_a;
    if ($arrChannel_a !== null)
    {
        $strName = $arrChannel_a['name'];
        $arrNames = [];
        foreach (irc_Members($arrChannel_a['guid']) as $strIdentity)
        {
            $arrOther = irc_PersonLoad($strIdentity);
            $arrEntry = null;
            if ($arrOther !== null)
            {
                $arrEntry = irc_Membership($arrOther, $arrChannel_a);
            }
            if ($arrEntry !== null && $arrOther['nick'] !== '')
            {
                $arrNames[strtolower($arrOther['nick'])] = irc_LevelPrefix($arrEntry['level']) . $arrOther['nick'];
            }
        }
        ksort($arrNames);
        foreach (array_chunk(array_values($arrNames), NAMES_PER_ROW) as $arrChunk)
        {
            $arrRows[] = irc_Row($arrPerson_a, '353', ['=', $strName], implode(' ', $arrChunk));
        }
    }
    $arrRows[] = irc_Row($arrPerson_a, '366', [$strName], 'End of NAMES list');
    return $arrRows;
}

// the same event to every channel the person is in (NICK)
function irc_ToMyChannels($arrPerson_a, $strEvent_a)
{
    foreach ($arrPerson_a['channels'] as $arrEntry)
    {
        if ($arrEntry['isactive'])
        {
            esb_ToChannel($arrEntry['key'], $strEvent_a);
        }
    }
}

// ===========================================================================
// Person channel entries (the spaces-style list)
// ===========================================================================

function irc_Entry($arrPerson_a, $strGuid_a)
{
    $varResult = null;
    foreach ($arrPerson_a['channels'] as $arrEntry)
    {
        if ($arrEntry['key'] === $strGuid_a)
        {
            $varResult = $arrEntry;
        }
    }
    return $varResult;
}

function irc_SetEntry($arrPerson_a, $arrEntry_a)
{
    $arrChannels = [];
    $blnFound = false;
    foreach ($arrPerson_a['channels'] as $arrEntry)
    {
        if ($arrEntry['key'] === $arrEntry_a['key'])
        {
            $arrChannels[] = $arrEntry_a;
            $blnFound = true;
        }
        else
        {
            $arrChannels[] = $arrEntry;
        }
    }
    if (!$blnFound)
    {
        $arrChannels[] = $arrEntry_a;
    }
    $arrPerson_a['channels'] = $arrChannels;
    return $arrPerson_a;
}

function irc_RemoveEntry($arrPerson_a, $strGuid_a)
{
    $arrChannels = [];
    foreach ($arrPerson_a['channels'] as $arrEntry)
    {
        if ($arrEntry['key'] !== $strGuid_a)
        {
            $arrChannels[] = $arrEntry;
        }
    }
    $arrPerson_a['channels'] = $arrChannels;
    return $arrPerson_a;
}

function irc_ActiveCount($arrPerson_a)
{
    $intResult = 0;
    foreach ($arrPerson_a['channels'] as $arrEntry)
    {
        if ($arrEntry['isactive'])
        {
            $intResult++;
        }
    }
    return $intResult;
}

function irc_LevelPrefix($strLevel_a)
{
    $strPrefix = '';
    if ($strLevel_a === 'op')
    {
        $strPrefix = '@';
    }
    elseif ($strLevel_a === 'voice')
    {
        $strPrefix = '+';
    }
    return $strPrefix;
}

// ===========================================================================
// Rows and helpers
// ===========================================================================

// numeric row without trailing text: [num, mynick, params...]
function irc_Num($arrPerson_a, $strNum_a, $arrParams_a)
{
    return array_map('strval', array_merge([$strNum_a, irc_Target($arrPerson_a)], $arrParams_a));
}

// numeric row with trailing text
function irc_Row($arrPerson_a, $strNum_a, $arrParams_a, $strText_a)
{
    $arrRow = irc_Num($arrPerson_a, $strNum_a, $arrParams_a);
    $arrRow[] = (string)$strText_a;
    return $arrRow;
}

// result 1, message shown at the client, numeric row in data
function irc_Error($arrPerson_a, $strNum_a, $arrParams_a, $strText_a)
{
    $strMessage = $strText_a;
    if (count($arrParams_a) > 0)
    {
        $strMessage = implode(' ', $arrParams_a) . ': ' . $strText_a;
    }
    resp_Add(RESULT_ERROR, $strMessage, [irc_Row($arrPerson_a, $strNum_a, $arrParams_a, $strText_a)]);
}

function irc_Target($arrPerson_a)
{
    $strNick = '*';
    if ((string)$arrPerson_a['nick'] !== '')
    {
        $strNick = $arrPerson_a['nick'];
    }
    return $strNick;
}

function irc_Prefix($arrPerson_a)
{
    $strUser = '*';
    if ((string)$arrPerson_a['username'] !== '')
    {
        $strUser = $arrPerson_a['username'];
    }
    return irc_Target($arrPerson_a) . '!' . $strUser . '@' . USER_HOST;
}

function irc_HasFlag($strFlags_a, $strChar_a)
{
    return (strpos((string)$strFlags_a, $strChar_a) !== false);
}

function irc_Param($arrParams_a, $strName_a)
{
    $strValue = '';
    if (isset($arrParams_a[$strName_a]))
    {
        $strValue = trim($arrParams_a[$strName_a]);
    }
    return $strValue;
}

// free text: no line breaks / nulls, clipped to length, not trimmed
function irc_Text($arrParams_a, $strName_a, $intMax_a)
{
    $strValue = '';
    if (isset($arrParams_a[$strName_a]))
    {
        $strValue = str_replace(["\r", "\n", "\0"], ' ', $arrParams_a[$strName_a]);
        $strValue = irc_Clip($strValue, $intMax_a);
    }
    return $strValue;
}

function irc_Clip($strValue_a, $intMax_a)
{
    $strValue = '';
    if (function_exists('mb_substr'))
    {
        $strValue = mb_substr($strValue_a, 0, $intMax_a, 'UTF-8');
    }
    else
    {
        $strValue = substr($strValue_a, 0, $intMax_a);
    }
    return $strValue;
}

function irc_Len($strValue_a)
{
    $intLen = 0;
    if (function_exists('mb_strlen'))
    {
        $intLen = mb_strlen($strValue_a, 'UTF-8');
    }
    else
    {
        $intLen = strlen($strValue_a);
    }
    return $intLen;
}

function irc_ValidNick($strNick_a)
{
    return (strlen($strNick_a) <= NICK_MAX_LEN
        && preg_match('/^[A-Za-z_\[\]`^{|}][A-Za-z0-9_\[\]`^{|}\-]*$/', $strNick_a) === 1);
}

function irc_ValidChannel($strName_a)
{
    return (irc_Len($strName_a) <= CHANNEL_MAX_LEN
        && preg_match('/^#[^\s,\x07]+$/u', $strName_a) === 1);
}

function irc_ValidGuid($strGuid_a)
{
    return (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $strGuid_a) === 1);
}

// v4 GUID
function irc_NewGuid()
{
    $strBytes = '';
    if (function_exists('openssl_random_pseudo_bytes'))
    {
        $strBytes = openssl_random_pseudo_bytes(16);
    }
    if (strlen($strBytes) != 16)
    {
        $strBytes = '';
        for ($intI = 0; $intI < 16; $intI++)
        {
            $strBytes .= chr(mt_rand(0, 255));
        }
    }
    $strBytes[6] = chr((ord($strBytes[6]) & 0x0f) | 0x40);
    $strBytes[8] = chr((ord($strBytes[8]) & 0x3f) | 0x80);
    $strHex = bin2hex($strBytes);
    return substr($strHex, 0, 8) . '-' . substr($strHex, 8, 4) . '-' . substr($strHex, 12, 4) . '-'
        . substr($strHex, 16, 4) . '-' . substr($strHex, 20, 12);
}

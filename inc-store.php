<?php
// ---------------------------------------------------------------------------
// CyborgSCIF - local file storage
//
// Layout under DATA_DIR:
//   devices/<guid>.json         one per logged in device, mtime = last call
//   people/<identity>.json      nick, registration, channels (like CyborgShell spaces)
//   nicks/<hex nick>            nick claim, contents = identity (created with 'x')
//   channelnames/<hex name>     channel name claim, contents = channel GUID ('x')
//   channels/<guid>/channel.json  owner, name, topic, modes, key, limit
//   channels/<guid>/members/<identity>  index of who is in the channel now
//   reaper.stamp                last timeout sweep
//
// Writes go to a temp file then rename, so readers never see half a file and
// need no lock. Read-change-write is done under a lock file created with
// fopen 'x' (only one caller can create it). No flock, no database.
// ---------------------------------------------------------------------------

function store_Init()
{
    $arrDirs = ['', 'devices', 'people', 'nicks', 'channelnames', 'channels'];
    foreach ($arrDirs as $strDir)
    {
        if (!is_dir(DATA_DIR . $strDir))
        {
            @mkdir(DATA_DIR . $strDir, 0755, true);
        }
    }
}

// ---------------------------------------------------------------------------
// JSON files
// ---------------------------------------------------------------------------

function store_Read($strPath_a)
{
    $varResult = null;
    if (is_file($strPath_a))
    {
        $strJSON = @file_get_contents($strPath_a);
        if ($strJSON !== false)
        {
            $varData = json_decode($strJSON, true);
            if (is_array($varData))
            {
                $varResult = $varData;
            }
        }
    }
    return $varResult;
}

// temp + rename: readers see the old file or the new one, never a partial one
function store_Write($strPath_a, $arrData_a)
{
    $strTemp = $strPath_a . '.' . irc_NewGuid() . '.tmp';
    if (@file_put_contents($strTemp, json_encode($arrData_a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) === false)
    {
        throw new Exception('store: cannot write ' . $strTemp);
    }
    if (!@rename($strTemp, $strPath_a))
    {
        @unlink($strTemp);
        throw new Exception('store: cannot rename to ' . $strPath_a);
    }
}

// read, change, write under the file's lock. $fnChange_a gets the current data
// (or null) and returns the new data, or null to leave the file alone.
function store_Update($strPath_a, $fnChange_a)
{
    $varResult = null;
    $strLock = store_Lock($strPath_a);
    try
    {
        $varResult = call_user_func($fnChange_a, store_Read($strPath_a));
        if ($varResult !== null)
        {
            store_Write($strPath_a, $varResult);
        }
    }
    catch (Exception $objEx)
    {
        store_Unlock($strLock);
        throw $objEx;
    }
    store_Unlock($strLock);
    return $varResult;
}

// ---------------------------------------------------------------------------
// Lock files
// ---------------------------------------------------------------------------

function store_Lock($strPath_a)
{
    $strLock = $strPath_a . '.lock';
    $objLock = @fopen($strLock, 'x');
    $intWaited = 0;
    while ($objLock === false && $intWaited < LOCK_MAX_WAIT_US)
    {
        $intMTime = @filemtime($strLock);
        if ($intMTime !== false && $intMTime < time() - LOCK_STALE)
        {
            // holder died long ago
            @unlink($strLock);
        }
        else
        {
            usleep(LOCK_WAIT_US);
            $intWaited += LOCK_WAIT_US;
        }
        $objLock = @fopen($strLock, 'x');
    }
    if ($objLock === false)
    {
        throw new Exception('store: lock timeout ' . $strLock);
    }
    fclose($objLock);
    return $strLock;
}

function store_Unlock($strLock_a)
{
    @unlink($strLock_a);
}

// ---------------------------------------------------------------------------
// Claim files: exclusive create, contents = owner. true if we now hold it.
// ---------------------------------------------------------------------------

function store_Claim($strPath_a, $strOwner_a)
{
    $blnResult = false;
    $objFile = @fopen($strPath_a, 'x');
    if ($objFile !== false)
    {
        fwrite($objFile, $strOwner_a);
        fclose($objFile);
        $blnResult = true;
    }
    elseif (store_ClaimOwner($strPath_a) === $strOwner_a)
    {
        $blnResult = true;
    }
    return $blnResult;
}

function store_ClaimOwner($strPath_a)
{
    $strResult = '';
    if (is_file($strPath_a))
    {
        $strResult = trim((string)@file_get_contents($strPath_a));
    }
    return $strResult;
}

function store_Release($strPath_a)
{
    @unlink($strPath_a);
}

// ---------------------------------------------------------------------------
// Paths
// ---------------------------------------------------------------------------

function store_SafeKey($strKey_a)
{
    return preg_replace('/[^A-Za-z0-9_-]/', '', $strKey_a);
}

function store_DevicePath($strGuid_a)
{
    return DATA_DIR . 'devices/' . store_SafeKey($strGuid_a) . '.json';
}

function store_PersonPath($strIdentity_a)
{
    return DATA_DIR . 'people/' . store_SafeKey($strIdentity_a) . '.json';
}

// nicks and channel names are case insensitive (ASCII casemapping) and may hold
// characters that are awkward in file names, so they are hex encoded
function store_NickPath($strNick_a)
{
    return DATA_DIR . 'nicks/' . bin2hex(strtolower($strNick_a));
}

function store_ChannelNamePath($strName_a)
{
    return DATA_DIR . 'channelnames/' . bin2hex(strtolower($strName_a));
}

function store_ChannelDir($strGuid_a)
{
    return DATA_DIR . 'channels/' . store_SafeKey($strGuid_a) . '/';
}

function store_ChannelPath($strGuid_a)
{
    return store_ChannelDir($strGuid_a) . 'channel.json';
}

function store_MemberPath($strGuid_a, $strIdentity_a)
{
    return store_ChannelDir($strGuid_a) . 'members/' . store_SafeKey($strIdentity_a);
}

// file names in a folder, sorted ('' dir missing = [])
function store_List($strDir_a, $strPattern_a)
{
    $arrResult = [];
    $arrFiles = glob($strDir_a . $strPattern_a);
    if (is_array($arrFiles))
    {
        foreach ($arrFiles as $strFile)
        {
            $arrResult[] = basename($strFile);
        }
    }
    sort($arrResult);
    return $arrResult;
}

function store_RemoveDir($strDir_a)
{
    $strDir = rtrim($strDir_a, '/') . '/';
    $arrItems = @scandir($strDir);
    if (is_array($arrItems))
    {
        foreach ($arrItems as $strBase)
        {
            $strItem = $strDir . $strBase;
            if ($strBase !== '.' && $strBase !== '..')
            {
                if (is_dir($strItem))
                {
                    store_RemoveDir($strItem);
                }
                else
                {
                    @unlink($strItem);
                }
            }
        }
    }
    @rmdir($strDir_a);
}

function store_Now()
{
    return date('Y-m-d H:i:s');
}

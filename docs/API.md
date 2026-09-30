# CyborgSCIF — Client API

This document covers everything needed to write a client for CyborgSCIF: an IRC-style chat server delivered as JSON webservices.

The server follows IRC (RFC 1459 / 2812) closely. Command names, numeric replies and event shapes are IRC's, so existing IRC knowledge carries over. The differences are the transport (HTTP + polling instead of a socket), the login (a device GUID), private channels, and channel history.

---

## 1. Transport

There is a single entry point:

```
POST https://<host>/<path>/api.php
```

The request body is JSON. The server reads the raw body, so the Content-Type header doesn't matter. `application/json` is recommended.

The response is always a JSON array, with HTTP status 200.

**CORS:** the server sends no CORS headers. Serve your client from the same origin, or add the headers you need in front of `api.php`.

### Request

```json
{
  "command": "JOIN",
  "parameters": [
    { "name": "guid",    "value": "5d1654c8-1309-4590-8072-f278b3270e57" },
    { "name": "channel", "value": "#cpc" }
  ]
}
```

- **`command`** is case-insensitive.
- **`parameters`** is optional.
  - Parameter names are case-insensitive.
  - Values are converted to strings. Anything that isn't a string, number or boolean (null, objects, arrays) is ignored.
  - Parameters the command doesn't use are ignored.
  - If a name appears twice, the last one wins.

### Response

```json
[
  { "result": 0, "message": "", "data": [ ["JOIN", "julian!jc@cyborgscif", "#cpc"], ["353", "julian", "=", "#cpc", "@julian"] ] }
]
```

The response is an array of **results**. Most commands return one result, but some return several (see `MODE`). Process them in order.

| Field | Meaning |
|---|---|
| `result` | Action code. `0` = success, `1` = error. More codes will be added later (e.g. logout, file download), so treat unknown codes as "show the message, process the data". |
| `message` | Text for the user. **If it isn't empty, display it.** Style it by `result` (e.g. red for errors). |
| `data` | Array of rows. Each row is an array of **strings**. Row formats are in section 5. Always present, and may be `[]`. |

### Special replies

These errors come back with `data: []`:

| message | Meaning / what to do |
|---|---|
| `Invalid request` | The body wasn't valid JSON, or had no `command`. |
| `Not logged in` | The GUID is unknown, or the device timed out. Call `LOGIN` again with the same GUID, then re-register. |
| `Server error` | Something failed server side. Part of the request may have been applied (for example you joined but others weren't told). Retrying is safe for everything except PRIVMSG/NOTICE, which may then arrive twice. |

---

## 2. Identity: the device GUID

Every device has a GUID (lowercase v4 UUID, e.g. `5d1654c8-1309-4590-8072-f278b3270e57`). It identifies the device's connection, and for now it is also the user's identity for channel ownership and invites.

- Send it as a `guid` parameter on **every** call. If the parameter is missing, the server reads the cookie `cyborgscif_guid`.
- Get one from `LOGIN`. Store it on the device (cookie, local storage, config file) and reuse it forever.
- **Treat it as a password.** Anyone with a device's GUID can act as that device, including owning its channels. Never display it or send it to other users. The server never sends anyone else's GUID.

This is the system login, not a security login. A proper security login will replace the identity part later. The GUID will stay the device/connection key.

---

## 3. Connection lifecycle

```
LOGIN  ─►  NICK + USER  ─►  (registered: welcome numerics)  ─►  PING every N seconds
                                                                 └─ "messages" ─► FETCH
                                                               + commands as needed
```

1. **`LOGIN`** with your stored GUID, or without one the first time. Save the GUID from the reply. The reply also tells you the poll interval and whether this device is already registered and in channels. This happens after a page reload, when the device state is still on the server.
2. **`NICK`** and **`USER`**, in either order. Registration completes when both are in, and that reply carries the welcome numerics (001–005, MOTD). Until then, only `PING`, `FETCH`, `NICK`, `USER` and `QUIT` are allowed. Anything else gets `451`.
3. **`PING`** every *poll seconds* (from the LOGIN reply). PING is the keepalive, and it tells you which subsystems have something waiting for you. It never returns the things themselves. When it reports `messages`, call **`FETCH`** to get them. That is how you receive everything other people do.
4. **Timeout:** any call counts as activity. A device with no calls for **120 seconds** is deleted. Its channels see it `QUIT` with `Ping timeout`, and the events it hadn't fetched are no longer delivered to it. The next call returns `Not logged in`. Call `LOGIN` with the same GUID, then `NICK`/`USER` again. Use `HISTORY` to catch up on channels.
5. **`QUIT`** leaves all channels and resets the nickname. The device stays logged in (the GUID stays valid), so to chat again, register with `NICK`/`USER`.

### What comes back where

Replies to **your own** command come back in that command's response. That includes your own JOIN, PART, NICK, TOPIC, KICK, MODE and QUIT rows.

What **other people** do reaches you through `FETCH`, after `PING` reports `messages`.

Your own `PRIVMSG` and `NOTICE` are **not** echoed back, so the client should display what it sent.

---

## 4. Commands

Requires registration unless marked otherwise.

`→ others` shows which other devices receive the event. It's queued for them, their next `PING` reports `messages`, and their `FETCH` returns it.

---

### LOGIN (no registration needed)

Registers the device and returns its state.

| Parameter | |
|---|---|
| `guid` | Optional. Stored GUID. If missing or not a valid GUID, a new one is generated. |

**Success rows:**
```
["LOGIN", guid, pollSeconds, nick, registered]      nick "" if none, registered "0"/"1"
["CHANNELS", "#a", "#b", ...]                       only if the device is still in channels
```

---

### PING (no registration needed)

The keepalive. It reports which subsystems have something waiting for this device, and changes nothing.

**Success rows:**
- Nothing waiting: `[]`.
- Something waiting: `["PING", "messages printjobs ..."]`, a space-delimited list of subsystem names.

The client decides what to do with each name, usually calling that subsystem's command. Ignore names you don't know, because new subsystems will be added.

| Name | Meaning | Call |
|---|---|---|
| `messages` | Chat events are queued for you | `FETCH` |

A name stays in the list until whatever it reports is dealt with. `messages` keeps coming back on every PING until the queue is empty, so a FETCH that fails, or one you skip, loses nothing. Just catch it on the next PING.

---

### FETCH (no registration needed)

Returns everything new for this device, oldest first: messages to you, and everything in the channels you're in since you last fetched (or since you joined or logged in). Each device keeps its own place, so several devices of the same person each get everything.

**Success rows:** event rows (section 5.1). Returns `[]` if there's nothing.

At most 500 rows are returned per call. If more are waiting, the next PING reports `messages` again. To drain a big backlog faster, call FETCH again straight away when you get 500 rows.

Your own events are not returned (you already have them from your command's reply). An event that concerns several of your channels, like a QUIT, is returned once.

The device's place moves on when FETCH answers. If the response is lost on the way back (a dropped connection), those rows won't come again through FETCH; use `HISTORY` to fill in channel gaps.

---

### NICK (no registration needed)

| Parameter | |
|---|---|
| `nick` | New nickname. Max 30 chars. Letters, digits, backtick and `_ - [ ] { } \| ^`, not starting with a digit or `-`. Case-insensitive for uniqueness. |

**Success rows:**
- Not yet registered, and USER not sent yet: `[]`.
- Not yet registered, and USER already sent: the welcome numerics.
- Already registered: `["NICK", oldPrefix, newNick]`.

→ others: every device sharing a channel gets `["NICK", oldPrefix, newNick]`.

**Errors:** `431` no nick given, `432` invalid or reserved, `433` in use.

---

### USER (no registration needed)

| Parameter | |
|---|---|
| `username` | Required. Characters outside `A-Z a-z 0-9 _ - .` are removed. Max 20. |
| `realname` | Optional, max 50. Defaults to username. |

**Success rows:** `[]` if NICK hasn't been sent yet, otherwise the welcome numerics.

**Errors:** `461`, `462` already registered.

**Welcome numerics** (sent when registration completes):
```
["001", nick, "Welcome to the CyborgNet IRC Network nick!user@host"]
["002", nick, "Your host is ..., running version ..."]
["003", nick, "This server was created ..."]
["004", nick, serverName, version, "i", "iklmnotv"]
["005", nick, "CHANTYPES=#", "PREFIX=(ov)@+", "CHANMODES=,k,l,imnt", "CASEMAPPING=ascii",
              "NICKLEN=30", "CHANNELLEN=50", "TOPICLEN=390", "NETWORK=CyborgNet",
              "are supported by this server"]
["375", nick, "- server Message of the day -"]
["372", nick, "- line"]  (one per line)
["376", nick, "End of MOTD command"]      or  ["422", nick, "MOTD File is missing"]
```

Read `005` for limits rather than hard-coding them. Server config can change them.

---

### QUIT (no registration needed)

| Parameter | |
|---|---|
| `message` | Optional quit message. |

**Success rows:** `["QUIT", prefix, "Quit: message"]`

→ others: every device sharing a channel.

The nickname, username, away message and user modes are cleared and all channels are left (private channels keep your invite). The device stays logged in.

---

### JOIN

| Parameter | |
|---|---|
| `channel` | Required. Starts with `#`, max 50 chars, no spaces, commas or `\x07`. One channel per call. |
| `key` | Required if the channel is `+k`. |

If the channel doesn't exist, it's created and **you become its owner**.

**Success rows:**
```
["JOIN", yourPrefix, "#chan"]
["332", nick, "#chan", topic]                   if there is a topic
["333", nick, "#chan", setByPrefix, setAtUnix]  if there is a topic
["353", nick, "=", "#chan", "@owner +voiced plain ..."]   (repeated for large channels)
["366", nick, "#chan", "End of NAMES list"]
```

If you're already in the channel: `[]`.

→ others: members of the channel get `["JOIN", prefix, "#chan"]`.

**Op on join:** the owner always gets op. On a private channel, anyone invited as an op gets op. On a public channel, the first person in gets op.

**Errors:** `461`, `403` bad name, `405` too many channels (20), `473` private and not invited, `475` bad key, `471` full. The owner is never blocked by `+k` or `+l`.

---

### PART

| Parameter | |
|---|---|
| `channel` | Required. |
| `message` | Optional. |

**Success rows:** `["PART", prefix, "#chan", message]`

→ others: channel members.

A public channel is deleted when the last member leaves. A new channel with the same name starts with no history. Private channels are kept.

**Errors:** `461`, `403`, `442` not on channel.

---

### PRIVMSG / NOTICE

| Parameter | |
|---|---|
| `target` | Required. A `#channel` or a nick. |
| `text` | Required. Max 400 chars. CR/LF/NUL are replaced with spaces. Not trimmed. |

**Success rows:** usually `[]`. For a PRIVMSG to someone who is away, you get `["301", nick, targetNick, awayMessage]`.

→ others:
- Channel: all members except you get `["PRIVMSG", prefix, "#chan", text]`.
- Nick: that person gets `["PRIVMSG", prefix, theirNick, text]`.

NOTICE works the same with `"NOTICE"`.

**Action / `/me`:** send `text` as `\x01ACTION waves\x01`. The server passes it through untouched.

**UNSIGNAL / encoded payloads:** send them as `text`. The server passes them through and can't read them. Mind the length limit.

**Errors (PRIVMSG only):** `411` no target, `412` no text, `403` no such channel (or private and you can't see it), `404` cannot send (`+n` and you're outside, or `+m` and you have no voice), `401` no such nick. If the message store is unreachable, result `1` with message `Message could not be delivered, try again` and `data: []`.

A NOTICE never returns errors, per RFC 2812. If it can't be delivered, it's dropped silently.

---

### TOPIC

| Parameter | |
|---|---|
| `channel` | Required. |
| `topic` | **Leave it out** to read the topic. Send it **empty** to clear the topic. Send text to set it. Max 390. |

**Success rows:**
- Read: `["331", nick, "#chan", "No topic is set"]`, or `["332", ...]` followed by `["333", ...]`.
- Set: `["TOPIC", prefix, "#chan", topic]`.

→ others (set): channel members.

**Errors:** `461`, `403`, `442`, `482` (`+t` and you're not op).

---

### NAMES

| Parameter | |
|---|---|
| `channel` | Required. |

**Success rows:** `353` rows (as in JOIN), then `366`. If the channel doesn't exist, you get only `366`. `@` = op, `+` = voice.

**Errors:** `461`.

---

### LIST

No parameters.

**Success rows:** one `["322", nick, "#chan", userCount, topic]` per channel you can see, then `["323", nick, "End of LIST"]`.

---

### WHOIS

| Parameter | |
|---|---|
| `nick` | Required. |

**Success rows:**
```
["311", nick, theirNick, username, host, "*", realname]
["319", nick, theirNick, "@#chan1 +#chan2 #chan3"]   only channels you can see; left out if none
["312", nick, theirNick, serverName, networkName]
["301", nick, theirNick, awayMessage]                if away
["317", nick, theirNick, idleSeconds, signonUnix, "seconds idle, signon time"]
["318", nick, theirNick, "End of WHOIS list"]
```

Idle time counts from their last PRIVMSG or NOTICE.

**Errors:** `431`, `401`.

---

### AWAY

| Parameter | |
|---|---|
| `message` | Away text, max 200. Empty or left out means "back". |

**Success rows:** `["306", nick, "You have been marked as being away"]` or `["305", nick, "You are no longer marked as being away"]`.

---

### KICK

| Parameter | |
|---|---|
| `channel` | Required. |
| `nick` | Required. |
| `reason` | Optional. Defaults to your nick. |

Ops only.

**Success rows (person is in the channel):** `["KICK", prefix, "#chan", theirNick, reason]`

→ others: channel members, including the kicked person.

On a **private** channel, the person's invite is also removed. If they're **not in the channel** but are on the invite list (matched by identity if online, otherwise by their last known nick), only the invite is removed. In that case, `message` is `nick removed from #chan invite list`, and the row is `["UNINVITE", prefix, "#chan", nick]`. Nobody else is notified.

**Errors:** `461`, `403`, `442`, `482` not op, `482` "Cannot kick the channel owner", `441` not on channel (and not invited).

---

### INVITE

| Parameter | |
|---|---|
| `nick` | Required. The person must be online and registered. |
| `channel` | Required. You must be in it. |

- **Private channel:** ops only. The person is added to the invite list as a member, and the list remembers who invited them. The invite lasts until they're kicked.
- **Public channel:** any member can invite. It's just a notice.

**Success rows:** `["341", nick, theirNick, "#chan"]`

→ others: the invited person gets `["INVITE", prefix, theirNick, "#chan"]`.

**Errors:** `461`, `403`, `442`, `482` (private and not op), `401`, `443` already on channel.

---

### ACCESS

| Parameter | |
|---|---|
| `channel` | Required. Must be a private channel you're an op in. |

**Success rows:**
```
["ACCESS", "#chan", ownerNick, "owner", ""]
["ACCESS", "#chan", nick, "op"|"member", invitedByNick]   one per invited person, by nick
```

Nicks are the last nick each person used.

**Errors:** `461`, `403`, `442`, `482`. A public channel returns result `1` with message `#chan: Channel is not private` and `data: []`.

---

### MODE

| Parameter | |
|---|---|
| `target` | Required. A `#channel` or your own nick. |
| `modes` | Mode string, e.g. `+o`, `+mt-k`, `+ov`. Leave it out to query. |
| `args` | Space-separated arguments, used in order by the modes that take one: `k`, `l`, `o`, `v`. |

#### Channel modes

| Mode | Arg | Meaning | Who |
|---|---|---|---|
| `i` | – | Private: invite only, hidden from outsiders, kept when empty. On `+i`, current members are added to the invite list (ops as ops), and `+n` is forced on. | Owner |
| `n` | – | No messages from outside. Can't be removed while `+i`. | Op |
| `t` | – | Only ops set the topic. | Op |
| `m` | – | Moderated: only ops and voiced people can talk. | Op |
| `k` | key (on `+`) | Join key. `-k` needs no key. | Op |
| `l` | number (on `+`) | Member limit. | Op |
| `o` | nick | Operator. The owner can't be deopped. On private channels, op status is also saved on the invite list. | Op |
| `v` | nick | Voice. | Op |

New channels start as `+nt`.

**Query rows:**
```
["324", nick, "#chan", "+ntkl", key, limit]   key shown as "*" to non-members
["329", nick, "#chan", createdUnix]
```

**Change rows:** `["MODE", prefix, "#chan", "+o-v", "bob", "carl"]`. This lists only what actually changed, with the args in order.

→ others: channel members.

**Partial success:** if some modes apply and others fail, the response has **several results**. The first is result `0` with the MODE row, then one result `1` per failure. If nothing applied and there were no errors, you get result `0` with `[]`.

**Errors:** `461`, `403`, `442`, `482` not op, `482` "Only the channel owner can change +i", `482` "Cannot deop the channel owner", `441` nick not in channel, `472` unknown mode char.

#### User modes

Only your own nick. The only mode is `i`.

- Query: `["221", nick, "+i"]`
- Change: `["MODE", nick, nick, "+i"]`

**Errors:** `401`, `502` not your nick, `501` unknown flag.

---

### HISTORY

| Parameter | |
|---|---|
| `channel` | Required. |
| `tail` | Message id. **Empty or left out:** the newest messages. **Set:** messages older than this id. |
| `count` | Rows wanted. Defaults to 50, max 200. |

**Success rows**, oldest first:
```
["HISTORY", "#chan", id, unixTime, <original event row...>]
...
["HISTORYEND", "#chan", oldestId, more]
```

- Examples of the original event row: `"PRIVMSG", prefix, "#chan", text` or `"JOIN", prefix, "#chan"`.
- `oldestId` is the id of the first row in this page, or `""` if the page is empty. **Send it as `tail` to get the page before.**
- `more` is `"1"` if older messages exist, `"0"` if you've reached the start.
- Ids are strings. Treat them as opaque, and only pass back ones the server gave you.

Everything sent to the channel is in its history: PRIVMSG, NOTICE, JOIN, PART, TOPIC, KICK, MODE, and QUIT/NICK of people who were in it. Private messages to nicks are not.

Anyone can read a public channel's history. For a private channel, you must be the owner, invited, or in it.

The server keeps channel history for a set number of days (server config, 30 by default); older messages drop off.

**Errors:** `461`, `403`. A bad `tail` returns result `1` with message `#chan: Invalid message id` and `data: []`.

---

## 5. Row reference

A **prefix** is `nick!user@host`.

### 5.1 Event rows (from FETCH, and in replies to your own commands)

| Row | Sent to |
|---|---|
| `["JOIN", prefix, "#chan"]` | Channel |
| `["PART", prefix, "#chan", message]` | Channel |
| `["QUIT", prefix, message]` | Everyone sharing a channel |
| `["NICK", oldPrefix, newNick]` | Everyone sharing a channel |
| `["PRIVMSG", prefix, target, text]` | Channel or one person |
| `["NOTICE", prefix, target, text]` | Channel or one person |
| `["TOPIC", prefix, "#chan", topic]` | Channel |
| `["KICK", prefix, "#chan", kickedNick, reason]` | Channel, including the kicked person |
| `["MODE", prefix, "#chan", modes, args...]` | Channel |
| `["INVITE", prefix, yourNick, "#chan"]` | The invited person |

When someone times out, their peers get `["QUIT", prefix, "Ping timeout: 120 seconds"]`.

### 5.2 Server rows

| Row | From |
|---|---|
| `["LOGIN", guid, pollSeconds, nick, registered]` | LOGIN |
| `["PING", "messages ..."]` | PING, only when something is waiting |
| `["CHANNELS", "#a", ...]` | LOGIN |
| `["ACCESS", "#chan", nick, level, invitedBy]` | ACCESS |
| `["UNINVITE", prefix, "#chan", nick]` | KICK of an invited person who's not in the channel |
| `["HISTORY", "#chan", id, time, event...]` | HISTORY |
| `["HISTORYEND", "#chan", oldestId, more]` | HISTORY |

### 5.3 Numeric rows

`[numeric, yourNick, params..., text]`. `yourNick` is `*` before you have a nick.

On errors, `message` holds the same thing in readable form, e.g. `#chan: You're not channel operator`. The client can just display `message` and ignore the row.

| Numeric | Meaning |
|---|---|
| 001–005, 375, 372, 376, 422 | Welcome / MOTD |
| 221 | Your user modes |
| 301 | Away message |
| 305 / 306 | Back / away |
| 311, 312, 317, 318, 319 | WHOIS |
| 322, 323 | LIST |
| 324, 329 | Channel modes / created time |
| 331, 332, 333 | Topic |
| 341 | Invite sent |
| 353, 366 | NAMES |
| 401 | No such nick/channel |
| 403 | No such channel (also used for private channels you can't see) |
| 404 | Cannot send to channel |
| 405 | Too many channels |
| 411, 412 | No recipient / no text |
| 421 | Unknown command |
| 431, 432, 433 | No nick / bad nick / nick in use |
| 441, 442, 443 | They aren't on channel / you aren't / already on channel |
| 451 | Not registered |
| 461, 462 | Missing parameters / already registered |
| 471, 473, 475 | Can't join: full / invite only / bad key |
| 472 | Unknown channel mode |
| 482 | Not channel operator (or owner rules) |
| 501, 502 | Unknown user mode / not your nick |

---

## 6. Example session

```
→ {"command":"LOGIN"}
← [{"result":0,"message":"","data":[["LOGIN","5d1654c8-...","2","","0"]]}]

→ {"command":"NICK","parameters":[{"name":"guid","value":"5d1654c8-..."},{"name":"nick","value":"julian"}]}
← [{"result":0,"message":"","data":[]}]

→ {"command":"USER","parameters":[{"name":"guid","value":"5d1654c8-..."},{"name":"username","value":"jc"},{"name":"realname","value":"Julian"}]}
← [{"result":0,"message":"","data":[["001","julian","Welcome to ..."], ... ]}]

→ {"command":"JOIN","parameters":[{"name":"guid","value":"5d1654c8-..."},{"name":"channel","value":"#cpc"}]}
← [{"result":0,"message":"","data":[["JOIN","julian!jc@cyborgscif","#cpc"],["353","julian","=","#cpc","@julian"],["366","julian","#cpc","End of NAMES list"]]}]

→ {"command":"PING","parameters":[{"name":"guid","value":"5d1654c8-..."}]}
← [{"result":0,"message":"","data":[]}]                                  nothing waiting

→ {"command":"PING","parameters":[{"name":"guid","value":"5d1654c8-..."}]}
← [{"result":0,"message":"","data":[["PING","messages"]]}]               something waiting

→ {"command":"FETCH","parameters":[{"name":"guid","value":"5d1654c8-..."}]}
← [{"result":0,"message":"","data":[["JOIN","yenny!y@cyborgscif","#cpc"],["PRIVMSG","yenny!y@cyborgscif","#cpc","hi"]]}]

→ {"command":"JOIN","parameters":[{"name":"guid","value":"5d1654c8-..."},{"name":"channel","value":"#secret"}]}
← [{"result":1,"message":"#secret: Cannot join channel (+i)","data":[["473","julian","#secret","Cannot join channel (+i)"]]}]
```

### Minimal client (ES5)

```javascript
var g_strGuid = localStorage.getItem('cyborgscif_guid') || '';

function callApi(strCommand_a, objParams_a, fnDone_a)
{
    var arrParams = [{ name: 'guid', value: g_strGuid }];
    var strName;
    for (strName in objParams_a)
    {
        if (objParams_a.hasOwnProperty(strName))
        {
            arrParams.push({ name: strName, value: objParams_a[strName] });
        }
    }
    var objXhr = new XMLHttpRequest();
    objXhr.open('POST', 'api.php', true);
    objXhr.setRequestHeader('Content-Type', 'application/json');
    objXhr.onload = function ()
    {
        fnDone_a(JSON.parse(objXhr.responseText));
    };
    objXhr.send(JSON.stringify({ command: strCommand_a, parameters: arrParams }));
}

function handleResults(arrResults_a)
{
    var intI;
    var intJ;
    for (intI = 0; intI < arrResults_a.length; intI++)
    {
        var objResult = arrResults_a[intI];
        if (objResult.message !== '')
        {
            showMessage(objResult.message, objResult.result);   // style by result code
        }
        if (objResult.message === 'Not logged in')
        {
            reconnect();                                         // LOGIN, then NICK/USER again
        }
        for (intJ = 0; intJ < objResult.data.length; intJ++)
        {
            handleRow(objResult.data[intJ]);                     // switch on row[0]
        }
    }
}

// in handleRow: a ["PING", ...] row lists what is waiting
function handlePing(arrRow_a)
{
    var arrWaiting = arrRow_a[1].split(' ');
    var intI;
    for (intI = 0; intI < arrWaiting.length; intI++)
    {
        if (arrWaiting[intI] === 'messages')
        {
            callApi('FETCH', {}, handleResults);
        }
    }
}

// poll: PING every pollSeconds from the LOGIN reply
```

---

## 7. Rules of thumb

- Always display `message` when it isn't empty. Everything else comes from `data`.
- Switch on `row[0]`. Ignore row types you don't know, since new ones may be added.
- Don't treat `result` as a boolean. New codes are coming.
- Keep polling. Two minutes of silence signs the device out.
- PING only says what's waiting. Nothing arrives until you FETCH.
- Display your own PRIVMSG/NOTICE locally, because they aren't echoed.
- Keep the GUID private.
- Private channels you can't see look exactly like channels that don't exist (`403`). That's deliberate.

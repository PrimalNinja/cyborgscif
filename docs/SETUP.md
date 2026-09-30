# CyborgSCIF — Server Setup

CyborgSCIF is the IRC-style chat server behind `api.php`. It has no database:

- **State** (devices, people, nicks, channels) is kept in small JSON files under a data folder.
- **Messages and history** go through **ZOSCII MQ**: one queue per channel and one per person.

---

## Requirements

- **CyborgSCIF:** PHP 5.4 or later, with `allow_url_fopen` on, since every ZOSCII MQ call is an HTTP POST. It needs no database and no cURL.
- **ZOSCII MQ:** anywhere CyborgSCIF can reach over HTTP — it does not have to be the same machine, and CyborgSCIF never touches its disk. It must:
  - have `ALLOW_PUBLISH` and `ALLOW_FETCH` set to `TRUE` in its `index.php`
  - include the same-second counter (CCCC) fix, or messages published within the same second can be missed
  - run with the **same clock and PHP timezone** as CyborgSCIF, because message names start with the publish time and CyborgSCIF compares against its own clock
  - be served by something that handles more than one request at a time. A CyborgSCIF request waits on its MQ calls, so a single-worker PHP server shared by both would deadlock.
- A local disk for CyborgSCIF's data folder. Its lock files work on network filesystems only as far as those honour exclusive create.

---

## Install

1. Put the CyborgSCIF files in a folder the web server serves, e.g. `/var/www/chat/`:
   ```
   api.php  inc-config.php  inc-response.php  inc-store.php  inc-mq.php  inc-esb.php  inc-irc.php  demo.html
   ```
2. Install ZOSCII MQ, e.g. `/var/www/zosciimq/`, and set up its `claireup.php` cron job. That job is what removes chat history older than the retention days.
3. Edit `inc-config.php`:

   | Setting | What it is |
   |---|---|
   | `DATA_DIR` | CyborgSCIF's data folder. It must be writable by the web server and should be **outside the web root**, since the files hold login GUIDs. |
   | `MQ_URL` | ZOSCII MQ's `index.php` URL. Everything goes through it: publish and fetch. |
   | `MQ_QUEUE_PREFIX` | Prefix for chat queue names (default `cc`), so they don't mix with radio or other queues. |
   | `MQ_CHANNEL_RETENTION_DAYS` | How long channel history is kept (default 30). |
   | `MQ_PERSON_RETENTION_DAYS` | How long direct messages wait for a person (default 1). |
   | `MQ_TIMEOUT` | Seconds to wait on one MQ call (default 5). |
   | `ESB_FETCH_MAX` | Most messages one FETCH will collect (default 100). MQ hands over one message per call, so this is also the most MQ calls a FETCH can make. |
   | `PING_TIMEOUT` | Seconds of silence before a device is signed out (default 120). |
   | `SERVER_NAME`, `NETWORK_NAME`, `$MOTD_LINES` | What users see on connect. |

4. Open `demo.html` in a browser, pick a nickname, and `/join #test`.

The data folder and its subfolders are created on the first request.

---

## What's on disk

```
data/
├── devices/<guid>.json            one per logged-in browser/app; file time = last call
├── people/<identity>.json         nick, registration, and the channel list
├── nicks/<hex of nick>            who holds a nick (contents: identity)
├── channelnames/<hex of name>     which channel has a name (contents: channel GUID)
├── channels/<guid>/channel.json   owner, name, topic, modes, key, limit
├── channels/<guid>/members/<identity>   who's in the channel right now
└── reaper.stamp                   when timed-out devices were last checked

```

On the ZOSCII MQ side, two kinds of queue are used. CyborgSCIF only ever names
them, it does not read the folders:

```
cc-c-<channel guid>    channel messages and history
cc-p-<person queue id> direct messages, invites, kicks for one person
```

A person's channel list works like CyborgShell spaces:

```json
"channels": [
	{
		"key": "3f2a9c1e-…",
		"alias": "#cpc",
		"datetime": "2026-09-20 05:27:00",
		"isactive": true,
		"level": "op",
		"invitedby": ""
	}
]
```

- `key` is the channel GUID.
- `alias` is the name at the time of joining.
- `isactive` means in it now. On a private channel, an inactive entry is an invite.
- `level` is `member`, `voice` or `op`.

With the built-in login, a person is the same as their device GUID. A real login (such as CyborgShell users) can later map several devices to one person without changing the file layout.

Everything can be inspected with `ls` and `cat`. The files hold the GUIDs people log in with, so treat the data folder like a password store.

---

## How writes are kept safe

- **Whole-file writes** go to a temp file and are then renamed over the original, so a reader always sees a complete file.
- **Read-change-write** (a person file, `channel.json`, a device's read positions) happens under a lock file created with `fopen(..., 'x')`, which only one request can create. Others wait. A lock older than `LOCK_STALE` seconds is treated as left behind by a crashed request.
- **Claims** (nicks, channel names) are files created with `'x'`, so two people can't take the same nick at the same moment.
- **Joining** only creates a small member file, so many people joining the same channel at once don't clash.
- **Reading queues** is ZOSCII MQ's problem, not CyborgSCIF's. MQ waits out its own publish locks before answering a fetch.

This was tested with 8 server workers running 12 simultaneous joins and 11 people sending at once. Nothing was lost or duplicated, and messages sent one after another arrived in that order.

---

## Timeouts and clean-up

- A device that makes no call for `PING_TIMEOUT` seconds is removed. The check runs at most every `REAP_INTERVAL` seconds, triggered by any request, so no cron job is needed.
- When a person's last device times out, their channels see a QUIT, they leave their channels (private channels keep the invite), and their nick is freed.
- An empty public channel is removed when its last member leaves. An empty private channel stays.
- Old messages are removed by ZOSCII MQ's `claireup.php` according to the retention days.

---

## Known limits

- **A call per message.** ZOSCII MQ hands over one message per HTTP call, so a FETCH costs one call per waiting message plus one per queue, and a PING costs up to one per queue. Fine on a LAN or the same box; a batching action on MQ would fix it.
- **No transactions.** If something fails halfway through a request, part of it may already be done. For example, someone has joined but the JOIN message didn't reach the channel.
- **Lost responses.** FETCH moves the device's read position when it answers. If that response never reaches the client, those rows aren't sent again. `HISTORY` covers channel messages. Direct messages would need an acknowledgement added to FETCH.
- **Scans.** `LIST`, `ACCESS`, kicking someone who's offline, and the timeout check read every channel, person or device file. That's fine for hundreds, but it gets slow with many thousands.
- **One chat server.** CyborgSCIF's own state is files on its own disk, so several CyborgSCIF servers would have to share that folder. ZOSCII MQ is no longer part of that.

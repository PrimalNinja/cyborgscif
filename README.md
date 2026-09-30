# CyborgSCIF UNINTELLIGENCE Version v20261001 (EARLY ALPHA PREVIEW - NO SECURITY YET, COMING SOON)

An IRC-style chat server delivered as JSON webservices. One PHP entry point, no database, no sockets.

- **State** (devices, people, nicks, channels) is small JSON files on disk.
- **Messages and history** go through [ZOSCII MQ](https://github.com/PrimalNinja/cyborgzoscii) over HTTP: one queue per channel, one per person. MQ can be on another machine.
- **Commands and replies are IRC's** (RFC 1459 / 2812): NICK, USER, JOIN, PART, PRIVMSG, NOTICE, TOPIC, NAMES, LIST, WHOIS, AWAY, KICK, INVITE, MODE, plus PING/FETCH for polling, HISTORY for backlog and ACCESS for private channel lists.
- **Private channels** with an owner, ops, and an invite list that survives disconnects. Outsiders can't see the channel, its members or its history.
- **Payloads are passed through untouched**, so UNSIGNAL or any other encoding works and the server can't read it.
- **Clients** are anything that can POST JSON: Neptune, CyborgShell, `demo.html`, a bot, an AI, a game engine. A socket front end for ordinary IRC clients can reuse the same command functions.

## Requirements

- PHP 5.4+ with `allow_url_fopen` on. No database, no cURL.
- ZOSCII MQ reachable over HTTP, same clock and PHP timezone, with `ALLOW_PUBLISH` and `ALLOW_FETCH` on and the same-second counter (CCCC) fix.
- Local disk for the data folder.

## Quick start

1. Copy these files where the web server can serve them:
   `api.php inc-config.php inc-response.php inc-store.php inc-mq.php inc-esb.php inc-irc.php demo.html`
2. In `inc-config.php` set `DATA_DIR` (outside the web root) and `MQ_URL`.
3. Set up ZOSCII MQ's `claireup.php` cron job; it's what expires old history.
4. Open `demo.html`, pick a nickname, `/join #test`.

Everything else, including timeouts, is handled on request. There is no cron job of its own.

## Request and response

```json
{ "command": "JOIN", "parameters": [ { "name": "channel", "value": "#cpc" } ] }
```

```json
[ { "result": 0, "message": "", "data": [ ["JOIN", "julian!jc@cyborgscif", "#cpc"] ] } ]
```

`result` is 0 for success and 1 for an error. `message` is shown to the user when it isn't empty. `data` holds rows in IRC order, so a socket front end can print them as IRC lines.

## Documentation

- **[SETUP.md](SETUP.md)** — install, config, what's on disk, how writes are kept safe, known limits.
- **[API.md](API.md)** — every command, its parameters, replies and errors, for writing a client.
- **[USER-GUIDE.md](USER-GUIDE.md)** — the `/commands` for people using a client.

## License

MIT License - (c) 2026 Cyborg Unicorn Pty Ltd.

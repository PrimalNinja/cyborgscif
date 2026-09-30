# CyborgSCIF — User Guide

CyborgSCIF works like IRC. You pick a nickname, join channels, and talk. It is available in Neptune and CyborgShell.

Commands are typed starting with `/`. Anything typed without a `/` is sent as a message to the channel you're in.

---

## Getting connected

Your client logs in and keeps the connection alive for you. The one thing you need is a nickname:

```
/nick julian
```

- Nicknames can be up to 30 characters: letters, numbers, backtick, and `_ - [ ] { } | ^`. They can't start with a number or `-`.
- If the name is taken you'll be told, so pick another.
- You can change your nickname at any time with `/nick`. Everyone in your channels sees the change.

If your client is closed or loses its connection for more than 2 minutes, you are signed out and everyone in your channels sees you leave. When you come back on the same device, you log straight back in.

---

## Channels

Channel names start with `#`, e.g. `#cpc`. There are two kinds.

**Public channels** work like normal IRC. Anyone can find them, join them, and read their history. A public channel disappears when the last person leaves.

**Private channels** can only be joined by people who've been invited. People outside can't see that the channel exists, who is in it, or what was said. A private channel stays even when nobody is in it.

### Joining and leaving

```
/join #cpc                 join a channel (creates it if it doesn't exist)
/join #cpc secretkey       join a channel that has a key (+k)
/part #cpc                 leave a channel
/part #cpc back later      leave with a message
/quit                      sign out completely (leaves all channels)
/quit gone to bed          sign out with a message
```

If you create a channel, you are its **owner**.

### Finding channels and people

```
/list                      list public channels (and private ones you're invited to)
/names #cpc                who's in a channel
/whois yenny               info about someone
/topic #cpc                show a channel's topic
```

In name lists, `@` in front of a name means channel operator, and `+` means voiced.

---

## Talking

```
hello everyone             message the current channel
/msg yenny hi there        private message to one person
/msg #cpc hello            message a channel you're not looking at
/notice yenny heads up     a notice (no automatic replies are sent back)
/me waves                  action message, shown as "* julian waves"
```

Messages are up to 400 characters.

### Away

```
/away at lunch             mark yourself away; anyone who messages you gets this reply
/away                      mark yourself back
```

---

## Who can do what

| Role | How you get it | What you can do |
|---|---|---|
| **Owner** | You created the channel | Everything ops can do, plus make the channel private or public. Can't be kicked or have op taken away. |
| **Operator (@)** | Owner or another op gives it to you | Kick, invite (on private channels), set the topic, change channel modes, give or take op and voice |
| **Voice (+)** | An op gives it to you | Talk when the channel is moderated (+m) |
| **Member** | Joined the channel | Talk, and set the topic if +t is off |

On a public channel, the first person in gets op. On a private channel, op is only given by the owner or another op, and ops keep it when they leave and come back.

---

## Running a channel

### Topic

```
/topic #cpc Amstrad CPC talk     set the topic
/topic #cpc                      show the topic
```

When the channel is set to `+t`, only ops can change the topic. New channels start with `+t`.

### Giving and taking op / voice

```
/mode #cpc +o yenny          make yenny an operator
/mode #cpc -o yenny          remove operator
/mode #cpc +v bob            give bob voice
/mode #cpc +ov yenny bob     several at once
```

### Kicking

```
/kick #cpc bob               remove bob from the channel
/kick #cpc bob spamming      with a reason
```

On a public channel, a kicked person can just join again. On a private channel, a kick also removes their invite, so they can't come back unless they're invited again.

### Channel modes

```
/mode #cpc                   show the current modes
/mode #cpc +m                set a mode
/mode #cpc -m                remove a mode
```

| Mode | Meaning | Who can set it |
|---|---|---|
| `+i` | **Private** — invite only, hidden from outsiders, kept when empty | Owner only |
| `+n` | No messages from people outside the channel (always on for private channels) | Ops |
| `+t` | Only ops can change the topic | Ops |
| `+m` | Moderated — only ops and voiced people can talk | Ops |
| `+k key` | Channel needs a key to join, e.g. `/mode #cpc +k secret` | Ops |
| `+l number` | Limit how many people can be in the channel, e.g. `/mode #cpc +l 20` | Ops |

The owner is never blocked by `+k` or `+l`.

---

## Private channels

### Making a channel private

The owner types:

```
/mode #mychannel +i
```

Everyone already in the channel at that moment is added to the invite list automatically, and anyone who was an op stays an op.

To make it public again:

```
/mode #mychannel -i
```

### Inviting people

On a private channel, only ops can invite:

```
/invite yenny #mychannel
```

The person must be online. They'll get a notice that they were invited, and they can then `/join #mychannel`. They stay invited until they're kicked, even when they leave the channel or go offline.

On a public channel, anyone in it can send an invite. It's just a notice to the other person, since anyone can join anyway.

### Seeing who's invited

Ops can list everyone with access:

```
/access #mychannel
```

This shows the owner, each invited person, whether they're an op or member, and **who invited them**. If an op turns out to be a problem, the owner can see who they brought in and remove those people too.

### Removing people

```
/kick #mychannel bob
```

This removes bob from the channel and from the invite list. If bob is offline, it still works: the name is matched against the invite list, using the last nickname bob had.

Only the owner can't be kicked. To deal with a bad op, the owner can first take op away (`/mode #mychannel -o bob`) and then kick them.

---

## Channel history

Channels keep their recent history, so you can see what was said while you were away. That includes messages, notices, joins, leaves, quits, nick changes, kicks, topic changes and mode changes.

```
/history #cpc                show the latest messages
/history #cpc 100            show the latest 100
```

To scroll further back, your client asks for the page before the oldest message it's showing. Keep going until it tells you there's nothing older.

- History is kept for 30 days (the server can change this), and older messages drop off.
- When a public channel disappears (last person leaves), a new channel with the same name starts with no history.
- A private channel keeps its history while it exists, and only people who can see the channel can read it.

---

## Secure messages (UNSIGNAL)

You can send plain text or UNSIGNAL Protocol messages in any channel or private message. The server only passes UNSIGNAL messages on, and it can't read them. Only people holding the right ROM can.

Being in a private channel controls who **receives** the messages. UNSIGNAL controls who can **read** them.

---

## Quick reference

| Command | What it does |
|---|---|
| `/nick name` | Set or change your nickname |
| `/join #chan [key]` | Join or create a channel |
| `/part #chan [message]` | Leave a channel |
| `/quit [message]` | Sign out |
| `/msg target text` | Message a person or channel |
| `/notice target text` | Send a notice |
| `/me action` | Action message |
| `/topic #chan [text]` | Show or set the topic |
| `/names #chan` | Who's in a channel |
| `/list` | List channels |
| `/whois nick` | Info about someone |
| `/away [message]` | Set or clear away |
| `/invite nick #chan` | Invite someone |
| `/kick #chan nick [reason]` | Remove someone |
| `/mode #chan [modes] [args]` | Show or change channel modes |
| `/mode yournick +i` | Set your own user mode |
| `/access #chan` | Invite list of a private channel (ops) |
| `/history #chan [count]` | Channel history |

---

## Messages you might see

| Message | Meaning |
|---|---|
| Nickname is already in use | Someone else has it, so pick another |
| Erroneous nickname | The name has characters that aren't allowed, or is too long |
| You have not registered | Set a nickname first |
| Cannot join channel (+i) | It's private and you're not invited |
| Cannot join channel (+k) | Wrong or missing key |
| Cannot join channel (+l) | The channel is full |
| You have joined too many channels | The limit is 20 at once |
| No such channel | It doesn't exist, or it's private and you can't see it |
| No such nick/channel | That person isn't online |
| You're not channel operator | You need op for that |
| You're not on that channel | Join it first |
| Cannot send to channel | +n and you're outside, or +m and you don't have voice |
| Cannot kick the channel owner | The owner can't be removed |
| Only the channel owner can change +i | Only the owner can make a channel private or public |

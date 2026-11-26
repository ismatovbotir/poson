# Telegram group fixtures (plan 44, T2 revised)

Hand-written updates for the group ingest, built from the Bot API
documentation (https://core.telegram.org/bots/api: `Update`, `Message`,
`ChatMemberUpdated`, `ChatMember`, `Message.migrate_to_chat_id`,
`Message.migrate_from_chat_id`, `Message.sender_chat`,
`Message.is_automatic_forward`, `Message.new_chat_members`,
`Message.left_chat_member`, `Contact`).

- **Bot API version checked:** 9.x as listed on core.telegram.org, on **2026-11-26**.
- **No live capture was made** (owner decision: no dev bot, no capture tool).
  A shape difference found live becomes a NEW hand-written fixture, never a
  pasted real update (T2-8).
- `update_id` is `0`; tests set their own.

## Fake ids and names (reserved forms)

| What | Value | Form |
|---|---|---|
| Basic group | `-9990020001` | negative, 10 digits |
| Supergroup | `-1009990020001` | `-100` prefix + id (the basic group migrates to it) |
| Channel | `-1009990040001` | `-100` prefix |
| Owner / client / staff / stranger | `9990000001` / `9990010001` / `9990000002` / `9990000099` | |
| Another bot / this bot / anonymous-admin bot / channel service user | `9990000500` / `9990000600` / `9990000700` / `9990000701` | `is_bot` true except the service user |

Usernames are `fake_*`; phones `+99890000xxxx`; `file_id` / `file_unique_id`
only as the sentinels `FAKE_FILE_ID_MUST_NOT_BE_STORED` /
`FAKE_UNIQUE_ID_MUST_NOT_BE_STORED` (media fixtures), which a test asserts
never reach the database.
`tests/Feature/Office/Groups/GroupFixturesLintTest.php` (T2-2) fails on any
other file id, any phone outside the fake range, a `t.me` / invite link, a
username that is not `fake_*` and any 9+ digit id outside these forms. It
also checks that every fixture is used by a test (T2-5).

## Files

| File | Shows |
|---|---|
| `message_group`, `message_supergroup`, `message_reply` | text in a group / supergroup, a reply |
| `message_edited` | `edited_message` of `message_supergroup` (same id, same sender) |
| `my_chat_member_added`, `my_chat_member_admin`, `my_chat_member_kicked` | the bot's own status |
| `chat_member_join` | another member's status (bot is admin) |
| `new_chat_members`, `left_chat_member` | service messages |
| `migrate_to_chat_id`, `migrate_from_chat_id` | the two halves of a group becoming a supergroup |
| `sender_chat_anonymous`, `sender_chat_linked_channel`, `automatic_forward` | `sender_chat` and `is_automatic_forward` |
| `message_from_bot` | a bot sender |
| `contact_own`, `contact_other` | a contact whose `user_id` is / is not the sender |
| `media_photo`, `media_document` | media carrying a (sentinel) `file_id`; a file name with a phone |
| `start_command` | `/start@bot` in a group |
| `private_edited_message`, `channel_post` | updates that must never reach the group ingest |

## Shape assumptions to verify on the first live group (T2-6)

1. The anonymous admin message has `from` = the GroupAnonymousBot user and `sender_chat` = the group.
2. A linked channel's automatic forward has `from` = the Telegram service user, `sender_chat` = the channel and `is_automatic_forward` true.
3. `migrate_to_chat_id` arrives in the old chat, `migrate_from_chat_id` in the new one.
4. `chat_member` arrives only while the bot is an admin and `chat_member` is in `allowed_updates`.

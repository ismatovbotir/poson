# Telegram Business fixtures (plan 41, VO-T0)

Synthetic updates built from the Bot API documentation
(https://core.telegram.org/bots/api: `BusinessConnection`, `BusinessBotRights`,
`BusinessMessagesDeleted`, `Message.business_connection_id`,
`Message.sender_business_bot`, `getBusinessConnection`). **No live capture was
made** (owner decision: no T0 spike). Every id, name, username and phone is fake:

- Telegram user / chat ids: `99900xxxxx` (owner `9990000001`, client `9990010001`,
  stranger `9990000099`, bot `9990000500`). A lint test
  (`tests/Feature/Office/BusinessFixturesLintTest.php`) refuses any 9+ digit
  integer outside this range (except `date` / `edit_date`) and any `file_id`.
- Phones: `+99890000xxxx`. Connection ids: `fake-conn-NNNN`.
- `update_id` is `0`; tests set their own.

## Shape assumptions to verify on the first real connection

All of them are coded in one place: `App\Support\Telegram\Bot\BusinessUpdate`
(docblock "SHAPE ASSUMPTIONS", `fromUpdate()`, `connectionFields()`, `author()`).

| # | Assumption | If it is wrong |
|---|---|---|
| A1 | In `business_message` / `edited_business_message`, `chat` is the private chat between the business account and the counterpart, so `chat.id` = the **client's** user id for both directions. | Chats would be keyed wrongly: change the chat id source in `fromMessage()`. |
| A2 | `from` = the client for an incoming message, = the owner (`BusinessConnection.user.id`) for a message the owner sent from their own Telegram app. The owner is compared with the **stored** connection owner, never a message field. | `author()` would drop owner messages (`from` neither owner nor chat). |
| A3 | Messages the bot sends on the owner's behalf carry `sender_business_bot` (phase 2); phase 1 ignores them. | Echoes could be stored as owner messages. |
| A4 | `deleted_business_messages.chat.id` is the same client chat id and `message_ids` are the ids `business_message` carried. | Deletes would not null the stored text: check `deleted()` in `HandleBusinessUpdate`. |
| A5 | `business_connection` (and `getBusinessConnection`) carry `user`, `user_chat_id`, `date`, optional `rights` (BusinessBotRights, optional `true` flags) and `is_enabled` (false on disconnect). A legacy top-level `can_reply` is also read. | Connection rows without owner / enabled state: check `connectionFields()`. |
| A6 | The owner's own chat with the bot stays an ordinary `message` without `business_connection_id` (customer bot, unchanged). A `message` that carries `business_connection_id` is dropped. | Owner ↔ bot messages would be dropped. |

## First real connection checklist

1. Deploy with Virtual office ingest **off**. Admin → Telegram → Bot → "Update allowed updates" (keeps pending updates).
2. Set the owner's Telegram id in Virtual office → Settings (or `OFFICE_OWNER_TELEGRAM_IDS`).
3. Connect the bot in Telegram Business → Chatbots with **selected chats only**; check the connection row (enabled, allowed).
4. Switch ingest on, send one message from a test client and one owner reply; check the Unmarked stub (A1, A2).
5. Mark the chat as a client, edit and delete a message; check the stored row is updated / nulled (A4).
6. Disconnect and reconnect; check `is_enabled` and the audit rows (A5).
7. Raw captures stay local and are deleted afterwards; only sanitized fixtures are committed.

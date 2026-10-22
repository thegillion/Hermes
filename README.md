# Hermes — UISP SMS Plugin

> Two-way SMS messaging and automated client notifications for UISP, powered by Twilio.

Hermes adds a full SMS inbox to your UISP admin panel, embeds a conversation widget on every client overview page, and automatically texts clients when invoices are created, become overdue, or payments are received.

---

## Features

- **Two-way SMS inbox** — send and receive messages with clients in a chat-style interface
- **Client overview widget** — SMS conversation embedded directly on each client's UISP page
- **Dashboard widget** — shows all unread incoming messages with one-click mark-as-read
- **Automated notifications** — configurable SMS triggers for invoice and payment events
- **Client auto-match** — incoming messages are automatically matched to UISP client records by phone number
- **SQLite storage** — fast, reliable message history with no file locking issues
- **JSON migration tool** — built-in page to migrate from the old `messages.json` format
- **Twilio signature validation** — webhook requests verified as genuine Twilio calls (skipped automatically on private/local IP installs)
- **Discord alerts** — every incoming SMS is also posted to a Discord channel so nothing gets missed
- **Unanswered-text reminders** — pings the Discord channel if a text gets no reply within N minutes
- **Auto-notification tagging** — automated messages shown with a ⚡ tag in the inbox and client widget

---

## Requirements

- UISP 2.1.0 or newer
- PHP 8.1+ with the `pdo_sqlite` extension
- Composer
- A [Twilio](https://twilio.com) account with an SMS-capable phone number

---

## File Structure

```
README.md
src/
  manifest.json              Plugin metadata, config fields, menu, and widget registration
  composer.json              PHP dependencies (UCRM Plugin SDK + Twilio SDK)
  main.php                   Scheduled task — rebuilds the client directory cache
  public.php                 HTTP entry point — routes all requests
  plugin_admin.css           Injects Hermes icon into the UISP sidebar
  .gitignore                 Excludes vendor/ and data/ from git
  data/
    .gitkeep                 Ensures data/ directory exists in the zip
    hermes.db                Created at runtime — SQLite messages + client directory cache
    messages.json            Legacy JSON store (kept as backup after migration)
  includes/
    config.php               Constants, services, Twilio credentials, webhook URL
    helpers.php              Phone normalization, SQLite CRUD, thread building
    clients.php              Client directory — cached phone → client lookup
    ears.php                 Twilio incoming SMS webhook listener
    send.php                 Outbound SMS handler
    notifications.php        UISP event handler — automated SMS notifications
    discord.php              Posts incoming SMS to a Discord webhook
    migrate.php              One-time migration page from messages.json to SQLite
    pages/
      inbox.php              Main SMS inbox UI (sidebar + chat layout)
      thread.php             Single conversation thread view
      new_message.php        New conversation compose view
      empty.php              Empty state (no thread selected)
      widget_admin.php       Dashboard new messages widget
      widget_client.php      Client overview SMS widget
```

---

## Setup

### 1. Install dependencies

From inside the `src/` directory:

```bash
composer install
```

### 2. Pack the plugin

```bash
./vendor/bin/pack-plugin
```

This creates `hermes.zip` one level up.

### 3. Upload to UISP

1. Log in to UISP as an administrator
2. Go to **System → Plugins**
3. Click **Upload Plugin** and select `hermes.zip`
4. Enable the plugin

### 4. Configure Twilio credentials

Go to **System → Plugins → Hermes → Configure** and fill in:

| Field | Description |
|---|---|
| **Public URL** | Your UISP public domain e.g. `https://uisp.yourdomain.com` — used for Twilio signature validation. Leave blank on local/test installs. |
| **Twilio Account SID** | From your [Twilio Console](https://console.twilio.com) dashboard |
| **Twilio Auth Token** | From your Twilio Console dashboard |
| **Twilio From Number** | Your Twilio number in E.164 format e.g. `+15551234567` |
| **Discord Webhook URL** | Optional. Posts every incoming SMS to a Discord channel. In Discord: **Server Settings → Integrations → Webhooks → New Webhook → Copy Webhook URL**. |
| **Discord Reminder (minutes)** | Optional, e.g. `5`. If an incoming text has no reply and isn't marked read after this long, Hermes pings the channel once for that conversation. |
| **Discord Reminder Ping** | `@here` (default), `@everyone`, or a role as `<@&ROLE_ID>` (Discord Developer Mode → right-click role → Copy Role ID). |
| **Webhook Key** | A long random string (the SMS Inbox suggests one). Protects the billing notification webhook — see step 6. |

### 5. Set the Twilio webhook

In the [Twilio Console](https://console.twilio.com):

1. Go to **Phone Numbers → Manage → Active Numbers**
2. Click your SMS-capable number
3. Under **Messaging Configuration → A message comes in**, set:
   - **Webhook** (HTTP POST) → `https://your-uisp-domain.com/crm/_plugins/hermes/public.php?action=webhook`
4. Save

### 6. Set the UISP event webhook

For automated invoice/payment notifications, go to **System → Webhooks → Endpoints** and add:

- **URL:** `https://your-uisp-domain.com/crm/_plugins/hermes/public.php?key=YOUR_WEBHOOK_KEY`
- **Events:** Invoice - Add, Invoice - Edit, Payment - Add, Service - Suspend, Service - Activate

### 7. Set the plugin execution period (recommended)

Set **System → Plugins → Hermes → Execution period** so `main.php` runs in the background. Each run:

- sends any due Discord **reply reminders** — use the shortest period UISP offers if reminders are on, since a reminder goes out at the next run after the timer expires (any open Hermes page also checks, every 10–60 seconds)
- refreshes the cached client directory used to show client names (only when it's due — at most every 15 minutes)

Click **Execute manually** once after installing to fill the client directory immediately.

> **Security:** this endpoint is public, so Hermes rejects events that don't carry the matching `?key=`. It also never trusts the event body: the invoice, payment or service is re-fetched from UISP, and the recipient and every placeholder value come from that record. Until a Webhook Key is set, events are still accepted and the SMS Inbox shows a warning banner.

> **Note:** On local/private IP installs (10.x, 192.168.x, localhost) Twilio signature validation is automatically skipped. Set the Public URL config field when going live.

---

## Notification Templates

Configure SMS templates under **System → Plugins → Hermes → Configure**. Leave a field blank to disable that notification.

Templates support `%%entity.field%%` placeholders pulled directly from the UISP event payload:

### Invoice notifications (`invoice.add`, `invoice.overdue`, `invoice.near_due`)

| Placeholder | Example output |
|---|---|
| `%%client.firstName%%` | John |
| `%%client.lastName%%` | Smith |
| `%%client.name%%` | John Smith (or the company name for business clients) |
| `%%invoice.number%%` | 000042 |
| `%%invoice.total%%` | 79.99 USD |
| `%%invoice.dueDate%%` | May 16, 2026 |
| `%%invoice.currencyCode%%` | USD |

**Example template:**
```
Hi %%client.firstName%%, invoice #%%invoice.number%% for %%invoice.total%% is due on %%invoice.dueDate%%. Reply to this message with any questions.
```

### Payment notifications (`payment.add`)

| Placeholder | Example output |
|---|---|
| `%%client.firstName%%` | John |
| `%%client.name%%` | John Smith |
| `%%payment.amount%%` | 79.99 USD |
| `%%payment.currencyCode%%` | USD |

**Example template:**
```
Hi %%client.firstName%%, we received your payment of %%payment.amount%%. Thank you!
```

### Service notifications (`service.suspend`, `service.activate`)

| Placeholder | Example output |
|---|---|
| `%%client.firstName%%` | John |
| `%%service.name%%` | 100Mbps Residential |

**Example template (suspend):**
```
Hi %%client.firstName%%, your service has been suspended. Please contact us to restore it.
```

---

## Usage

- Click **SMS Inbox** in the UISP sidebar to open the full inbox
- Open any client in UISP — the Hermes SMS widget appears on their overview page
- The **dashboard widget** shows all unread incoming messages; click a sender to expand and mark as read
- In the inbox, click **+ New** to start a conversation with any client or number
- Press **Ctrl+Enter** to send a message from the keyboard
- Automated notification messages are shown with a ⚡ auto tag in both the inbox and client widget
- The inbox polls every 10 seconds and reloads only when new messages arrive

---

## Migrating from messages.json

If you were running a previous version of Hermes that stored messages in `messages.json`, open the inbox after upgrading — a yellow banner will appear with a **Run Migration →** link. The migration page shows how many messages will be imported, preserves read/unread status, and writes a flag to `messages.json` when complete so it never prompts again. The original JSON file is kept as a backup.

---

## Troubleshooting

| Issue | Solution |
|---|---|
| "Access denied" on plugin page | Make sure you are logged in to UISP as an admin |
| SMS not sending | Check Twilio credentials in plugin config |
| Incoming messages not appearing | Verify the Twilio webhook URL ends with `?action=webhook` and your server is publicly accessible |
| Invalid Twilio signature error | Set the **Public URL** config field to your exact public domain |
| Automated notifications not firing | Verify UISP webhook endpoint is set to `public.php` (not `main.php`) and the event template field is not blank |
| Log says "rejected event — missing or wrong webhook key" | The `?key=` on the UISP webhook endpoint URL doesn't match the plugin's **Webhook Key** |
| Reminder pings arrive late | They go out on the next plugin run after the timer expires — shorten the **Execution period** |
| Reminder pings don't notify anyone | Check **Discord Reminder Ping**; a role must be written `<@&ROLE_ID>` and the role must allow mentions |
| Incoming texts not showing in Discord | Check the plugin log for "Discord:" lines. The URL must be a full `https://discord.com/api/webhooks/…` link. |
| `$0` invoice notification sent | Enable or disable the "Send $0 Invoice Notifications" checkbox in config |
| Conversations show a phone number instead of a client name | The number isn't on any UISP client contact, or the directory cache is still filling — click **Execute manually** on the plugin page and reload |
| No phone number on client widget | UISP requires a phone number in the client's contact info |
| Plugin shows wrong version | Re-upload the zip — UISP caches the manifest at upload time |

Plugin errors are written to **System → Plugins → Hermes → Log**.

---

## Changelog

### 1.5.1
- **Fixed:** messages linked to a client that was later deleted in UISP no longer trigger a "could not fetch client" API call and log line on every inbox load. Deleted clients are remembered and re-checked once a day; those conversations use the phone number lookup instead.

### 1.5.0
- Optional Discord alerts for every incoming SMS (client name, message, link to the client in UISP). Customer texts can't ping `@everyone` or roles.
- Optional Discord reminder that pings `@here` or a role when a text has gone N minutes without a reply or being marked read — once per conversation, never for texts from before the feature was turned on.

### 1.4.0
- **Fixed:** client names now show in the SMS Inbox and dashboard widget, not just the client widget. The old lookup called a UCRM endpoint that doesn't exist and only read the first 500 clients.
- **Fixed:** business clients show their company name instead of "Unknown".
- **Fixed:** the client widget showed the *oldest* 50 messages; it now shows the latest 50.
- **Fixed:** `%%client.*%%` placeholders now work for `payment.add` and every other event.
- **Security:** the UISP event webhook requires a **Webhook Key** (`?key=` on the endpoint URL), and notification content is always re-fetched from UISP instead of taken from the request, so forged events can't pick the recipient or inject text.
- Incoming SMS are tagged with the matching client, and older messages are back-filled automatically.
- Conversations are grouped by a stored phone key, so `+1859…` and `859…` land in the same thread.
- Source moved into `src/` in git; built zips and runtime data are no longer committed.

## Contact

Kentucky Fi — [kentuckyfi.com](https://kentuckyfi.com) · 859-710-WiFi

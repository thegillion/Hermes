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
  main.php                   UISP background/scheduled task entry point
  public.php                 HTTP entry point — routes all requests
  plugin_admin.css           Injects Hermes icon into the UISP sidebar
  .gitignore                 Excludes vendor/ and data/ from git
  data/
    .gitkeep                 Ensures data/ directory exists in the zip
    hermes.db                Created at runtime — SQLite message database
    messages.json            Legacy JSON store (kept as backup after migration)
  includes/
    config.php               Constants, services, Twilio credentials, webhook URL
    helpers.php              Phone normalization, SQLite CRUD, thread building
    clients.php              UCRM API — loads clients and phone → client map
    ears.php                 Twilio incoming SMS webhook listener
    send.php                 Outbound SMS handler
    notifications.php        UISP event handler — automated SMS notifications
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

### 5. Set the Twilio webhook

In the [Twilio Console](https://console.twilio.com):

1. Go to **Phone Numbers → Manage → Active Numbers**
2. Click your SMS-capable number
3. Under **Messaging Configuration → A message comes in**, set:
   - **Webhook** (HTTP POST) → `https://your-uisp-domain.com/crm/_plugins/hermes/public.php?action=webhook`
4. Save

### 6. Set the UISP event webhook

For automated invoice/payment notifications, go to **System → Webhooks → Endpoints** and add:

- **URL:** `https://your-uisp-domain.com/crm/_plugins/hermes/public.php`
- **Events:** Invoice - Add, Invoice - Edit, Payment - Add, Service - Suspend, Service - Activate

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
| `$0` invoice notification sent | Enable or disable the "Send $0 Invoice Notifications" checkbox in config |
| No phone number on client widget | UISP requires a phone number in the client's contact info |
| Plugin shows wrong version | Re-upload the zip — UISP caches the manifest at upload time |

Plugin errors are written to **System → Plugins → Hermes → Log**.

---

## Contact

Kentucky Fi — [kentuckyfi.com](https://kentuckyfi.com) · 859-710-WiFi

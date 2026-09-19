# Real-time Meta lead capture (webhook)

Two things must both be true for a Lead Ads submission to reach the CRM within
seconds: Meta must be *allowed* to send it (token permissions + Page
subscription), and the CRM must be *reachable and listening* (webhook URL,
verify token, queue worker). The 15-minute poll keeps working regardless, so
nothing is lost while you set this up.

## 1. Token with the right permissions (Meta Business Settings)

1. business.facebook.com → **Settings → Users → System users** → your system user.
2. **Assign assets**: the Facebook Page (Full control), the Ad account, the WhatsApp account.
3. **Generate new token** → pick the CRM app → tick:
   - `pages_show_list`, `pages_read_engagement`, `pages_manage_metadata`
   - `leads_retrieval`, `pages_manage_ads`
   - `ads_read`, `ads_management`, `business_management`
   - (WhatsApp) `whatsapp_business_messaging`, `whatsapp_business_management`
4. Copy the token → CRM **Settings → Integrations → Meta tab → System user token** → Save.

`pages_manage_metadata` is the one that was missing: without it Meta refuses the
Page subscription with error #200.

## 2. Register the webhook on the app (once)

developers.facebook.com → your app → **Webhooks**:

1. Object **Page** → *Subscribe to this object*.
   - Callback URL: `https://prevoir.panvelcity.in/api/webhooks/meta`
   - Verify token: the value under CRM **Settings → Integrations → Webhook tab** (click *Generate* if empty, then Save).
   - Meta calls the URL with `hub.challenge`; the CRM answers it.
2. In the Page fields list tick **leadgen** → Subscribe.
3. Object **WhatsApp Business Account** → same URL/token → tick **messages**, **message_template_status_update**, **calls**.
4. App **Settings → Basic → App secret** → paste into CRM **Meta tab → App secret** (every delivery is HMAC-checked against it).
5. The app must be **Live** (not Development mode) for leads from real users to be delivered.

## 3. Subscribe the Page (per Page, in the CRM)

CRM **Settings → Integrations → Ads tab**:

1. **Discover** → pick the Page → **Save** (this stores the Page access token).
2. **Subscribe Page to Lead Ads** → success message.
3. **Test connection** → the `lead_ads` row must say *Page is subscribed to leadgen*.

Repeat for every Page that runs lead ads.

## 4. Test it

Meta **Lead Ads Testing Tool** (developers.facebook.com/tools/lead-ads-testing):
pick the Page and a form → *Create lead* → within seconds the lead shows under
**Enquiries** with source *Meta*, routed by its campaign and assigned by the
online-agent rules. Delete the test lead from the tool afterwards.

Or, inside the CRM, **Marketing → Lead forms → bug icon (Add a test lead)**
runs a fake submission through the same import path without touching Meta.

## 5. What runs on the server

| Piece | Where | Purpose |
|---|---|---|
| `POST /api/webhooks/meta` | nginx → PHP-FPM | receives the batch, verifies signature, queues `ImportMetaLeadJob` |
| `ivfcrm-queue.service` | systemd, `queue:work redis` | fetches the lead from Graph, creates + assigns it |
| `sync-meta-lead-forms` | cron, every 15 min | polls every auto-sync form as a backstop |
| `assign-pending-leads` | cron, every minute | assigns leads that arrived while nobody was online |

Check health: **Settings → Integrations → Webhook tab** lists the last 15
deliveries with status; `storage/logs/queue.log` shows `ImportMetaLeadJob` runs.

## Troubleshooting

- *Test connection* → `lead_ads` says not subscribed → redo section 3; if it
  mentions `pages_manage_metadata`, redo section 1.
- Deliveries arrive but no lead → the form's phone question is not mapped:
  **Marketing → Lead forms → sliders icon → Field mapping**, map it to *Mobile*.
- Nothing arrives at all → app not Live, or callback URL/verify token wrong in
  the App Dashboard, or the signature fails because the App secret differs.

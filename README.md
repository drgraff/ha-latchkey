# Latchkey

**A PIN-protected web button for Home Assistant, hosted anywhere.**

Give a neighbour, cleaner or dog-walker a link that does *one thing* in your
home - open the front gate, say - without giving them a Home Assistant
account, your HA address or any password that matters. Optionally the page can
also tell them whether anyone has been home in the last 24 hours ("do the bins
need putting out?").

The page runs on ordinary PHP web hosting (shared hosting is fine), separate
from Home Assistant. Your HA address and secrets never reach the visitor's
browser.

<p align="center"><i>Front gate · PIN · [Has anyone been home?] · [Open / close gate]</i></p>

**Blueprints:** [![Import the Latchkey web button blueprint](https://my.home-assistant.io/badges/blueprint_import.svg)](https://my.home-assistant.io/redirect/blueprint_import/?blueprint_url=https%3A%2F%2Fgithub.com%2Fdrgraff%2Fha-latchkey%2Fblob%2Fmain%2Fhomeassistant%2Fblueprints%2Fautomation%2Flatchkey%2Flatchkey_button.yaml) web button &nbsp;
[![Import the Latchkey presence push blueprint](https://my.home-assistant.io/badges/blueprint_import.svg)](https://my.home-assistant.io/redirect/blueprint_import/?blueprint_url=https%3A%2F%2Fgithub.com%2Fdrgraff%2Fha-latchkey%2Fblob%2Fmain%2Fhomeassistant%2Fblueprints%2Fautomation%2Flatchkey%2Flatchkey_presence.yaml) presence push (optional)

## How it works

```
 visitor's phone            your web hosting (PHP)                 Home Assistant
 ───────────────            ──────────────────────                 ──────────────
 enters PIN, taps  ──POST──▶ checks the PIN (hashed),   ──HTTPS──▶  webhook automation
 the button                 lockout & cooldown, then               (Latchkey blueprint):
                            adds the shared secret                 checks the secret and
                                                                   your access switch,
                            status.php  ◀──HTTPS (secret)──────    runs your action,
 sees "someone has  ◀────── stores presence pushed by HA           notifies you
 been home…" (optional)
```

- **The PIN is checked on the web server**, so the page can say "wrong PIN"
  (Home Assistant webhooks can't send an answer back). It's stored only as a
  hash. 5 wrong PINs in 15 minutes lock that page for 30 minutes, and HA tells
  you.
- **Home Assistant only acts on requests carrying the shared secret**, which
  lives in the server's private config and in HA - never in the page. Someone
  who discovers your webhook URL still can't use it.
- **A kill switch** (an `input_boolean`) disables everything instantly from
  your HA dashboard.
- **Several pages, each with its own PIN.** E.g. one page that also shows
  "has anyone been home?", and a button-only page you're happy to share more
  widely. Each page has its own wrong-PIN lockout; the press cooldown is shared.
- **Presence is pushed *from* HA** (on every change, hourly and at start), so
  the web server never needs an HA login. Data older than 3 hours shows as
  "unknown" rather than stale.

## Before you start: is this a good idea for *your* device?

Anyone who has the link **and** the PIN can press the button, at any time,
from anywhere. Only put something behind Latchkey that you'd be comfortable
with a trusted neighbour operating while you're away - a front gate, a light,
a pet door. **Not** your garage door, your front-door lock or an alarm.

You'll be notified every time it's used, and you can switch it off instantly.

## Requirements

- Home Assistant reachable from the internet over HTTPS (your own domain /
  DuckDNS, or a Nabu Casa remote URL).
- Web hosting with **PHP 8.0+ and the cURL extension**, HTTPS, and a folder
  outside the web root. SSH access makes setup easier but isn't required.
- The hosting must be allowed to make **outgoing** connections to your HA's
  port. Many shared hosts block unusual ports such as **8123** - see
  [Troubleshooting](#troubleshooting).

## Setup

### 1. Generate your secrets

You need three random values. On any Linux/macOS machine:

```sh
openssl rand -hex 20    # webhook id
openssl rand -hex 32    # shared secret
openssl rand -hex 8     # a page folder name (repeat for each page)
```

### 2. Web server

1. Upload `server/latchkey/` to a folder **outside** your web root, e.g.
   `/home/you/latchkey/`. Copy `config.example.php` to `config.php` and fill in
   `ha_webhook_url` (ending in your webhook id), `secret`, your pages and texts.
   `chmod 600 config.php`.
2. Pick (or create) a site or subdomain for the pages. Copy
   `server/public_html/.htaccess`, `robots.txt` and `index.html` into its web
   root (they stop directory listings and search engines).
3. For each page, create a folder with a **long random name** and put a copy
   of `server/public_html/example-page/index.php` in it. Set
   `$LATCHKEY_PAGE` to the page's name in `config.php`, and fix the `require`
   path. If any page shows presence, also copy `status.php` into **one** page
   folder - that's where HA pushes to.
4. Set each page's PIN (4-8 digits) over SSH:

   ```sh
   echo "neighbours 123456" | php /home/you/latchkey/setpin.php
   ```

   No SSH? Run `php -r 'echo password_hash("123456", PASSWORD_DEFAULT);'`
   anywhere and paste the hash into `config.php`.

### 3. Home Assistant

1. Create an `input_boolean` as your kill switch, e.g.
   *Settings → Devices & services → Helpers → Toggle* "Latchkey access".
2. Import the **Latchkey - web button** blueprint and create an automation
   from it:

   [![Open your Home Assistant instance and show the blueprint import dialog with this blueprint pre-filled.](https://my.home-assistant.io/badges/blueprint_import.svg)](https://my.home-assistant.io/redirect/blueprint_import/?blueprint_url=https%3A%2F%2Fgithub.com%2Fdrgraff%2Fha-latchkey%2Fblob%2Fmain%2Fhomeassistant%2Fblueprints%2Fautomation%2Flatchkey%2Flatchkey_button.yaml)

   Fill in your webhook id, the shared secret, the kill
   switch, and - **for now - an action that toggles a light**. Add
   notifications if you like; `{{ latchkey_page }}` holds the page name.
3. Optional, for "Has anyone been home?":
   - add the `rest_command` (and, if useful, the "Someone home" template
     sensor) from `homeassistant/snippets/configuration.yaml`, plus the two
     entries from `snippets/secrets.yaml`, then reload *REST commands* and
     *Template entities* under *Developer tools → YAML*;
   - create an `input_datetime` helper (date **and** time) "Last home" and set
     it to roughly when someone was last home;
   - import **Latchkey - presence push** and create an automation from it:

     [![Open your Home Assistant instance and show the blueprint import dialog with this blueprint pre-filled.](https://my.home-assistant.io/badges/blueprint_import.svg)](https://my.home-assistant.io/redirect/blueprint_import/?blueprint_url=https%3A%2F%2Fgithub.com%2Fdrgraff%2Fha-latchkey%2Fblob%2Fmain%2Fhomeassistant%2Fblueprints%2Fautomation%2Flatchkey%2Flatchkey_presence.yaml)

### 4. Test with a light, then switch to the real thing

Open your page on your phone and try:

- a wrong PIN → "Wrong PIN. 4 tries left."
- the right PIN → your test light toggles, you get a notification
- pressing again straight away → "Just pressed - wait a moment."
- kill switch off → nothing happens (and the "blocked" notification fires)
- "Has anyone been home?" → the right answer, and the light does **not** toggle

When it all behaves, change the blueprint automation's action to your real
device (e.g. `switch.turn_on` on a gate relay that pulses itself off).

## Day-to-day

- **Change a PIN:** `echo "<page> <new PIN>" | php /home/you/latchkey/setpin.php`
- **Clear a lockout:** delete `state.json` in your private latchkey folder.
- **Disable everything:** turn the kill switch off in HA.
- **A link leaked?** Rename the page's folder (new random name) and send the
  new link to the people who should have it. If you think the shared secret
  leaked, generate a new one and update `config.php`, the blueprint
  automation and `secrets.yaml`.

## Troubleshooting

- **"Couldn't reach the house"** - the server can't reach HA. From the
  server: `curl -I https://your-ha:8123/manifest.json`. A connection that fails
  instantly usually means the host's **outgoing firewall** blocks the port.
  Ask your host to allow outbound TCP to that port (give them your HA hostname,
  as home IPs change), or use a URL on port 443 (e.g. Nabu Casa).
- **The page says "Done" but nothing happened** - HA webhooks always answer
  "OK", so the page can't tell. Check the kill switch and the automation's
  trace in HA (*Settings → Automations → … → Traces*).
- **Status always "unknown"** - check the presence automation's trace and
  that `latchkey_status_url` points at the page folder holding `status.php`.
  Testing `status.php` without the secret returns 404 - that's intended.
- **Shared hosting with no free SSL for subdomains** - most hosts include a
  free certificate option in their control panel (on cPanel hosts, look under
  *Security*); the page must be served over HTTPS.

## What's in the box

```
server/latchkey/              -> private folder, outside the web root
  latchkey_app.php            the page + PIN check + call to HA
  status_receiver.php         stores presence pushed by HA
  setpin.php                  sets a page's PIN (hashed)
  config.example.php          copy to config.php
server/public_html/           -> your (sub)domain's web root
  example-page/index.php      one per page, in a long random folder name
  example-page/status.php     only in one page folder, if you use presence
  .htaccess, robots.txt, index.html
homeassistant/
  blueprints/automation/latchkey/latchkey_button.yaml
  blueprints/automation/latchkey/latchkey_presence.yaml
  snippets/configuration.yaml, snippets/secrets.yaml
```

## Licence

MIT - see [LICENSE](LICENSE). No warranty: you are responsible for what you
put behind a public button.

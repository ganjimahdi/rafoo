=== Rafoo – Offline-Ready Assets & Updates ===
Contributors: wbweb
Tags: offline, cdn, google fonts, performance, iran
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keeps WordPress fast and working when the international internet is cut off. Local CDN libraries and fonts, instant failure of unreachable requests.

== Description ==

When the international internet is cut off or heavily filtered, a WordPress site hosted inside the country keeps running, but it slows to a crawl: every page waits for jQuery from a CDN, fonts from Google, avatars from Gravatar, and the dashboard hangs on requests to servers it can no longer reach.

Rafoo fixes this without touching the files of your theme or plugins.

**Libraries and fonts from your own server**

* Finds JavaScript and CSS files your site loads from jsDelivr, unpkg, cdnjs, the jQuery CDN, Google Hosted Libraries, BootstrapCDN and the Font Awesome CDN, including the ones themes and page builders write straight into the page.
* Copies them to `wp-content/uploads/rafoo`, from a domestic mirror or from the original source, and serves them from your domain. No extra DNS lookup or TLS handshake, so pages are faster even when the internet works.
* Every file is checked before it is used: against its `integrity` hash, against the original file, or against a signed mirror index. A file that cannot be checked is used only during an outage and is checked again as soon as the internet is back.
* Fonts, images and other files referenced by a stylesheet are copied with it.
* Google Fonts are copied with `font-display: swap`. During an outage, fonts that were never copied are skipped so pages do not wait for them.
* A daily check compares every local file with its recorded hash.

**Automatic outage mode**

* Every minute Rafoo checks a few servers outside and inside Iran in parallel, in the background. Visitors never wait for it.
* Three failed rounds in a row switch on outage mode, three successful rounds switch it off. If even the servers inside Iran are unreachable, the problem is your own server's network and the mode is left alone.
* You can always fix the mode by hand.

**No more frozen dashboard**

* During an outage, requests from WordPress and plugins to servers outside Iran fail immediately instead of waiting for a timeout. Iranian domains, your own site and servers you allow are still contacted.
* At any time, a server that fails twice within two minutes is skipped for three minutes.

**Services that cannot be copied**

* Letter avatars instead of Gravatar, during outages or always.
* During an outage, YouTube, Vimeo and Google Maps frames are replaced with a short note and a link.

**Setup in a minute**

* After activation a short setup offers three ready-made setups or a step-by-step choice. Every part can be switched on or off later.

**More help during outages**

* WordPress's own outside files (emoji images, the Google Fonts list of the font library, remote block patterns, Openverse) stop loading.
* Analytics scripts such as Google Analytics, Tag Manager, Hotjar, Clarity and the Facebook pixel can be removed during outages or always.
* Sign-in buttons for Google, Facebook and similar services are hidden while they cannot work.
* Emails that fail because the mail server cannot be reached are kept and sent later. An optional domestic mail server can be used only during outages.
* A log shows which plugin or theme connects to which server.

**Built-in captcha**

* A proof-of-work check runs silently in the visitor's browser. No cookies and no outside service.
* Protects login, registration, lost password, comments, Contact Form 7, WPForms and WooCommerce (login, registration, classic and block checkout).
* During an outage it takes the place of reCAPTCHA, hCaptcha and Turnstile, so forms that use them keep working.

**Safe updates**

* Before a plugin or theme is updated, the previous version is kept. After the update the home page, login page and dashboard are checked. If the site broke, the previous version is put back by itself, and only the update that caused it.
* Saved versions can also be put back by hand.

**Backups and moving the site**

* Full or changes-only backups of files and database on a schedule, made in short steps so they work on shared hosting.
* Copies on this host and on other servers over SFTP, FTPS or FTP. Rafoo finds the right connection and folder by itself.
* Optional encryption with a password (XChaCha20-Poly1305).
* Restore checks the site afterwards and puts the previous database back if the restored site does not load.
* Copy the whole site to another WordPress site with Rafoo using a one-time code, directly between the two servers, with a short final pass for WooCommerce orders. A different domain and table prefix are handled.
* Move to an empty host with a small installer.

**Built to stay out of the way**

* A page costs one small option lookup. The page HTML is only searched when it mentions a known CDN.
* Page caches of WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround Optimizer, Breeze and Autoptimize are cleared when the mode or the list of local files changes.
* Works on Multisite, PHP 7.4 to 8.4, Apache, LiteSpeed and Nginx.
* WP-CLI: `wp rafoo status`, `mode`, `check`, `scan`, `sync`, `assets`, `verify`, `purge`.
* Persian and English, right-to-left and left-to-right.
* Nothing is sent anywhere about your visitors. Usage reports are off unless you turn them on.

== External services ==

Rafoo talks to the following services. None of them receives anything about your visitors.

**Connection checks.** Once a minute, from your server, Rafoo sends a small request to each of these addresses and only records whether an answer arrived. The request carries the user agent `Rafoo/<version>` and nothing else.

* Google: `https://www.google.com/generate_204` ([terms](https://policies.google.com/terms), [privacy](https://policies.google.com/privacy))
* Cloudflare: `https://www.cloudflare.com/cdn-cgi/trace` ([terms](https://www.cloudflare.com/website-terms/), [privacy](https://www.cloudflare.com/privacypolicy/))
* Apple: `https://captive.apple.com/hotspot-detect.html` ([terms](https://www.apple.com/legal/internet-services/terms/site.html), [privacy](https://www.apple.com/legal/privacy/))
* jsDelivr: `https://cdn.jsdelivr.net/` ([terms and privacy](https://www.jsdelivr.com/terms))
* Rafoo: `https://rafoo.ir/api/v1/status`, which may also return a signed outage notice ([terms and privacy](https://rafoo.ir/privacy))
* Wbweb mirrors: `https://mirror.wbweb.ir` and `https://wordpress.wbweb.ir` ([terms and privacy](https://wbweb.ir/privacy))

**Copying files.** When your site uses a library or font from a public CDN, Rafoo downloads that same file once from the Wbweb mirror (`https://mirror.wbweb.ir`) and, while the internet works, from the original CDN, to compare and store it. Google Fonts stylesheets and font files are downloaded from `fonts.googleapis.com` and `fonts.gstatic.com` ([Google privacy](https://policies.google.com/privacy)). Only the file address is sent.

**Usage reports (off by default).** Only if an administrator turns them on in Rafoo › Settings, a report is sent once a week to `https://rafoo.ir/api/v1/report`, and a short notice to `https://rafoo.ir/api/v1/install` when Rafoo is activated or deactivated. The report contains a random site ID, the versions of WordPress, PHP and Rafoo, the site language, whether it is a Multisite, the number of local files, the addresses of CDN files the mirror did not have, and the slugs and versions of installed plugins and themes. It never contains the site address, user data or visitor data. Turning reports off deletes the site ID.

**Moving a site (only when used).** When an administrator makes a transfer code under Rafoo › Backup, the address of this site's endpoint and a random one-time key are sent to `https://rafoo.ir/api/v1/pair`, which returns a short code that expires within a day. Site data never passes through rafoo.ir; the copy goes directly between the two sites. A long code that works without rafoo.ir is always shown as well.

**Backup servers (only when added).** Backups are sent only to servers an administrator adds under Rafoo › Backup, using the login entered there.

**Suggestions.** When an administrator writes a message under Rafoo › Feedback and presses Send, the message, the optional contact detail and the software versions are sent to `https://rafoo.ir/api/v1/feedback`.

Rafoo's terms and privacy policy: [rafoo.ir/privacy](https://rafoo.ir/privacy).

== Installation ==

1. Install and activate Rafoo from Plugins › Add New.
2. Open Rafoo in the admin menu. It scans a few pages of the site within a minute and starts copying files in the background.
3. On low-traffic sites, add a real cron job that requests `wp-cron.php` every minute so connection checks run on time.

== Frequently Asked Questions ==

= Does Rafoo change my theme or plugin files? =

No. Addresses are replaced while the page is generated. Deactivate Rafoo and everything is exactly as before.

= Will my files break if the mirror is down? =

No. Files already copied are on your own server. Rafoo also works without rafoo.ir; the signed notice only makes switching faster.

= Which files are copied? =

Only exact versions from the CDNs listed above, for example `jquery@3.7.1`. Addresses such as `latest` or version ranges are left alone.

= Does it work with Content Security Policy and Subresource Integrity? =

Local files are the same bytes as the originals, so `integrity` attributes keep working. A policy that allows `'self'` allows them.

= Does Rafoo work outside Iran? =

Yes. Local copies of CDN files and fonts make any site faster and more private. Outage mode simply never switches on.

= Where is the source of the admin screen? =

The readable Svelte source is in the `admin-ui` folder of the plugin. Build it with `pnpm install` and `pnpm build`.

== Credits ==

The Rafoo screen uses the Vazirmatn font by Saber Rastikerdar, licensed under the SIL Open Font License 1.1 (`assets/fonts/OFL.txt`). It is loaded only on the Rafoo page and nowhere else in the dashboard.

SFTP connections use phpseclib 3 (MIT license) with paragonie/constant_time_encoding (MIT license), bundled in `vendor` under the `Rafoo\Vendor` namespace so they never clash with copies used by other plugins.

== Changelog ==

= 1.1.0 =
* Setup after activation with three ready-made setups.
* Built-in captcha, and replacement of reCAPTCHA, hCaptcha and Turnstile during outages.
* Safe updates with automatic rollback.
* Backups, restore, remote servers and site transfer.
* Outage help for WordPress's own outside files, analytics scripts, social sign-in and email.
* Log of outside requests.

= 1.0.0 =
* First release.

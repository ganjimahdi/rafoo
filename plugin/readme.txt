=== Rafoo – Offline-Ready Assets & Updates ===
Contributors: wbweb
Tags: offline, cdn, google fonts, performance, iran
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.6.2
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

**Outage rehearsal**

* See your site exactly as it would work if the international internet went down today, before a real outage. Only your browser sees the outage; visitors, search engines and the page cache keep the normal site.
* Rafoo opens your home page, latest post, a page, the WooCommerce shop, product, cart, checkout and account pages, the login page, the dashboard, the post editor and any other address you add, once normally and once in outage mode, with a live preview.
* The report names every library from a foreign CDN, Google Font, Gravatar image, captcha, video, analytics script, social sign-in, unknown address, server request to a foreign service, PHP error and JavaScript error that appears only during an outage, and the plugin or theme behind each one.
* Most problems are fixed with one button: download a library or font to the Rafoo library, turn on the right protection, or block or allow an address. A score from 0 to 100 and the last ten rehearsals show how ready the site is.
* Browse yourself for 15 minutes to test forms, the cart and payment by hand, with the same report at the end.

**Rafoo Shield**

* A firewall that checks every request before other plugins load, or before WordPress itself with one click. A normal page costs about 0.2 ms.
* Stops SQL injection, cross-site scripting, file reading, command execution, object injection, dangerous uploads, server-side request forgery, XML entity attacks, vulnerability scanners and probes for sensitive files, after decoding the input the way PHP reads it.
* Locks sign-in after repeated failures, limits request floods, closes XML-RPC multicall and hides the user list from visitors.
* Three modes: Smart, tuned so real visitors are never stopped; Strict, for sites that are attacked often; and Fortress, for the hours of a heavy attack, where each new visitor passes a few-second browser check once. Payment returns from Iranian gateways and verified search engine bots are never challenged.
* Editors and administrators can save page builder content freely, while visitors cannot post scripts.
* Community defense (off by default): when at least three member sites on different networks report the same attacker, rafoo.ir sends a signed list to every member, along with virtual patches for vulnerable plugin versions that are installed on the site.
* Optional blocking at the ArvanCloud or Cloudflare edge, and a dashboard card with a one-click switch to fortress mode.

**Automatic outage mode**

* Every minute Rafoo checks a few servers outside and inside Iran in parallel, in the background. Visitors never wait for it. The signed notice from rafoo.ir is fetched every five minutes, or at once when the outside checks fail.
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

**Multiple hosts with geo DNS (beta)**

* Keep full copies of the site on up to three more hosts in other countries. Visitors reach the nearest healthy host, and when the international internet is cut, visitors in Iran keep reaching the host inside Iran while visitors abroad reach the copies.
* One address for everyone: every host serves your own domain. Technical subdomains redirect to it and are marked noindex, so search engines see one site and no duplicate content.
* Copies follow the main host within seconds: only the changed tables and files are sent. Copies are read-only; logins, comments, forms, carts and orders are passed to the main host.
* Rafoo checks every host from the other hosts each minute and updates DNS by itself through ArvanCloud, Gcore, Cloudflare, Amazon Route 53 or Bunny DNS, or through cPanel and DirectAdmin on the hosts. A manual mode lists the exact records for any other DNS.
* Step-by-step guides for every part, from creating an A record to changing nameservers at IRNIC and setting up Search Console.

**Browser and internal requests**

* A small script at the top of every page makes browser requests to unreachable servers fail at once during an outage, so sliders, chat widgets and page builders do not hang.
* A block list stops any host or address you never want, on the server, in the page and in the browser.
* Identical requests a page sends to its own AJAX or REST address at the same moment go out once. WordPress Heartbeat slows down in smart mode without breaking autosave or post locks.
* One Pause switch turns off every change Rafoo makes, to test or to find a conflict.

**Site Health and Multisite**

* Tools › Site Health shows whether outage detection runs on time, whether local copies can be saved, and whether Rafoo is paused or fixed in a mode by hand. The Info tab has a Rafoo section for support requests.
* On Multisite, the network administrator can set Rafoo once for every site: as a starting point for new sites, or applied to all sites and locked. Privacy and backup choices stay with each site.

**Built to stay out of the way**

* A page costs one small option lookup. The page HTML is only searched when it mentions a known CDN.
* Page caches of WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround Optimizer, Breeze and Autoptimize are cleared when the mode or the list of local files changes.
* Works on Multisite, PHP 7.4 to 8.4, Apache, LiteSpeed and Nginx.
* WP-CLI: `wp rafoo status`, `mode`, `check`, `scan`, `sync`, `assets`, `verify`, `purge`.
* In English, Persian, Arabic, Turkish and Russian, right-to-left and left-to-right, with dates and numbers in the user's own format.
* A help button next to every section and switch explains it in two sentences.
* Nothing is sent anywhere about your visitors. Usage reports are off unless you turn them on.

== External services ==

Rafoo talks to the following services. None of them receives anything about your visitors.

**Connection checks.** Once a minute, from your server, Rafoo sends a small request to each of these addresses and only records whether an answer arrived. The request carries the user agent `Rafoo/<version>` and nothing else.

* Google: `https://www.google.com/generate_204` ([terms](https://policies.google.com/terms), [privacy](https://policies.google.com/privacy))
* Cloudflare: `https://www.cloudflare.com/cdn-cgi/trace` ([terms](https://www.cloudflare.com/website-terms/), [privacy](https://www.cloudflare.com/privacypolicy/))
* Apple: `https://captive.apple.com/hotspot-detect.html` ([terms](https://www.apple.com/legal/internet-services/terms/site.html), [privacy](https://www.apple.com/legal/privacy/))
* jsDelivr: `https://cdn.jsdelivr.net/` ([terms and privacy](https://www.jsdelivr.com/terms))
* Rafoo: `https://rafoo.ir/api/v1/status`, which may also return a signed outage notice and the number of the latest Rafoo version ([terms and privacy](https://rafoo.ir/privacy))
* Wbweb mirrors: `https://mirror.wbweb.ir` and `https://wordpress.wbweb.ir` ([terms and privacy](https://wbweb.ir/privacy))

**Copying files.** When your site uses a library or font from a public CDN, Rafoo downloads that same file once from the Wbweb mirror (`https://mirror.wbweb.ir`) and, while the internet works, from the original CDN, to compare and store it. Google Fonts stylesheets and font files are downloaded from `fonts.googleapis.com` and `fonts.gstatic.com` ([Google privacy](https://policies.google.com/privacy)). Only the file address is sent.

**Usage reports (off by default).** Only if an administrator turns them on in Rafoo › Settings, a report is sent once a week to `https://rafoo.ir/api/v1/report`, and a short notice to `https://rafoo.ir/api/v1/install` when Rafoo is activated or deactivated. The report contains a random site ID, the versions of WordPress, PHP and Rafoo, the site language, whether it is a Multisite, the number of local files, the addresses of CDN files the mirror did not have, and the slugs and versions of installed plugins and themes. It never contains the site address, user data or visitor data. Turning reports off deletes the site ID.

**Moving a site (only when used).** When an administrator makes a transfer code under Rafoo › Backup, the address of this site's endpoint and a random one-time key are sent to `https://rafoo.ir/api/v1/pair`, which returns a short code that expires within a day. Site data never passes through rafoo.ir; the copy goes directly between the two sites. A long code that works without rafoo.ir is always shown as well.

**Cooperative network (off by default).** Only if an administrator joins the network under Rafoo › Monitoring, the site address, a public signing key, the chosen check interval, the edition and version are sent to `https://rafoo.ir/api/v1/peers/join`. When the Monitoring page is opened, or Recheck is pressed, a signed request to `https://rafoo.ir/api/v1/peers/wake` or `/peers/recheck` asks the watching members to check the site at once; rafoo.ir then calls their `?rafoo-ping` address with a signed task. rafoo.ir checks the site once at `?rafoo-ping`, derives the country and network of the server from the request (the IP address is not stored) and sends back a signed list of a few other member sites. The site then requests those members' `?rafoo-ping` address in the background, every one or five minutes, and sends the results (time, whether a signed answer arrived, response time) to `https://rafoo.ir/api/v1/peers/receipts`. Other members request this site's `?rafoo-ping` address the same way. No page, content, user or visitor data is checked or sent. Leaving the network or deactivating Rafoo deletes the membership. When a member stops contacting rafoo.ir, rafoo.ir checks its `?rafoo-ping` address and removes it once it no longer answers as Rafoo; if Rafoo is still active there, it joins again by itself, unless the rafoo.ir administrator removed and blocked it.

**Community defense (off by default).** Only if an administrator turns on community defense under Rafoo › Shield, the site address, a public signing key, the edition, version and shield mode are sent to `https://rafoo.ir/api/v1/shield/join`, and rafoo.ir checks the site once at `?rafoo-ping`. Every ten minutes, a signed request to `https://rafoo.ir/api/v1/shield/report` sends the IP addresses the shield stopped in that time, with the attack type, the number of stopped requests, the time and the slug of a targeted plugin, at most 500 addresses. Signed-in users, addresses on the allow list, private addresses and visitors who were not stopped are never sent. Every fifteen minutes, or when the status answer names a newer list, a signed request to `https://rafoo.ir/api/v1/shield/feed` downloads the signed list of addresses reported by several sites and the virtual patches. Turning it off, or deactivating Rafoo, calls `https://rafoo.ir/api/v1/shield/leave` and deletes the membership and the list.

**CDN edge blocking (only when connected).** When an administrator connects ArvanCloud or Cloudflare under Rafoo › Shield, the IP addresses blocked for an hour or more (at most 200) are sent to that service as a firewall rule, with the key entered there: ArvanCloud `https://napi.arvancloud.ir` or Cloudflare `https://api.cloudflare.com` (terms and privacy linked above). Disconnecting removes the rule.

**Rafoo updates (copies from rafoo.ir or GitHub).** A copy of Rafoo downloaded from rafoo.ir or GitHub asks `https://rafoo.ir/api/v1/releases/rafoo.json` (Rafoo Complete: `rafoo-complete.json`) for the latest version at most every six hours, and `https://rafoo.ir/api/v1/releases/rafoo/info` (or `rafoo-complete/info`) for the list of changes when an administrator opens the details window, sending only the language (Persian or English), or at once when the status answer names a newer version, and downloads updates from `https://rafoo.ir/downloads/`, checking each file against its signed SHA-256 hash. Nothing about the site is sent. The copy from wordpress.org updates only through wordpress.org.

**Backup servers (only when added).** Backups are sent only to servers an administrator adds under Rafoo › Backup, using the login entered there.

**Multiple hosts (only when used).** Hosts of one group talk only to each other, over signed and encrypted requests to `admin-ajax.php?action=rafoo_geo` and `?rafoo-ping` on their technical addresses; nothing goes through rafoo.ir. When an administrator chooses a DNS service under Rafoo › Geo DNS and enters its key, Rafoo sends the domain's DNS records (host names and IP addresses of the hosts) to that service only:

* ArvanCloud: `https://napi.arvancloud.ir` ([terms](https://www.arvancloud.ir/en/terms), [privacy](https://www.arvancloud.ir/en/privacy))
* Gcore: `https://api.gcore.com` ([terms and privacy](https://gcore.com/legal))
* Cloudflare: `https://api.cloudflare.com` ([terms](https://www.cloudflare.com/website-terms/), [privacy](https://www.cloudflare.com/privacypolicy/))
* Amazon Route 53: `https://route53.amazonaws.com` ([terms](https://aws.amazon.com/service-terms/), [privacy](https://aws.amazon.com/privacy/))
* Bunny DNS: `https://api.bunny.net` ([terms](https://bunny.net/tos/), [privacy](https://bunny.net/privacy/))
* cPanel or DirectAdmin: the panel address of each host, entered by the administrator.

To check that search engine bots are genuine (for Geo DNS and for the shield), Rafoo looks up the reverse DNS name of the bot's IP address with the server's own resolver, once a day per address. To check the domain's DNS, it asks the domain's own nameservers directly.

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

= Does it work with Elementor, Divi and other page builders? =

Yes. The editors of Elementor (including the Hello theme), Divi, Beaver Builder, Bricks and Oxygen, and the Customizer, load normally during an outage. Rafoo's first script is marked so that cache and "delay JavaScript" plugins leave it alone.

= Something looks wrong after activating Rafoo. What should I do? =

Press Pause on the Rafoo overview. The site then works as if Rafoo were not installed. If the problem goes away, turn off the switches in Settings one at a time to find the cause, and tell us under Rafoo › Feedback.

= How does it work on Multisite? =

The internet state is shared by the whole network, and each site has its own copies, log and backups. Network administrators can set Rafoo for all sites under Network Admin › Rafoo.

= Will copies on other hosts cause duplicate content? =

No. Every host serves the same pages at your own address, and the technical subdomains of the hosts redirect to it with a noindex header. Search engines see one site. Rafoo › Geo DNS checks this on every host.

= Can I translate Rafoo? =

Yes. All text is in the `rafoo` text domain and a `rafoo.pot` file is in the `languages` folder.

= Where is the source of the admin screen? =

The built files in `assets` come from readable Svelte and JavaScript source. The full source is public at https://github.com/ganjimahdi/rafoo in the `admin-ui` folder, with the same version tag as the plugin. Build it with `pnpm install` and `pnpm build`. It is left out of the plugin zip so the plugin installs faster.

== Screenshots ==

1. Overview: the internet state, the mode, local files and the Pause switch.
2. Libraries and fonts the site loads from public CDNs, with their local copies.
3. Connection checks and the log of outside requests, with a Block button per host.
4. Settings, with a help button next to every section and switch.
5. Backups: schedule, encryption, other servers and moving the site.
6. The optional cooperative monitoring network.
7. Network settings on Multisite.
8. The outage rehearsal report, with one-click fixes.

== Credits ==

The Rafoo screen uses the Vazirmatn font by Saber Rastikerdar, licensed under the SIL Open Font License 1.1 (`assets/fonts/OFL.txt`). It is loaded only on the Rafoo page and nowhere else in the dashboard.

SFTP connections use phpseclib 3 (MIT license) with paragonie/constant_time_encoding (MIT license), bundled in `vendor` under the `Rafoo\Vendor` namespace so they never clash with copies used by other plugins.

== Changelog ==
= 1.6.2 =
* All Persian text was rewritten in shorter sentences, everyday words and the terms Iranian users already know, following one glossary across the plugin, rafoo.ir and the monitoring point.
* In the Persian Shield screens, “temporary security patch” and “community defense” are explained where they first appear.
* No change to English or other languages, settings or behavior.

= 1.6.1 =
* The Rafoo icon appears in Dashboard › Updates and in the plugin details window of copies from rafoo.ir.
* View details and View version details show the Rafoo introduction, a banner and the changes of each version from rafoo.ir, with an update button, instead of “Plugin not found”.
* Rafoo Complete reports the WordPress version it was tested with.

= 1.6.0 =
* Rafoo Shield: a firewall that stops attacks before other plugins load, or before WordPress with one click, in three modes: Smart (on by default), Strict and Fortress.
* Stops SQL injection, cross-site scripting, file reading, command execution, object injection, dangerous uploads, SSRF, XML entity attacks, scanners and sensitive file probes; locks sign-in after repeated failures; limits floods; closes XML-RPC multicall and the public user list.
* Fortress mode asks each new visitor for a few-second browser check, without any outside service. Payment returns and verified search engine bots pass.
* Community defense (off by default): addresses reported by several independent sites are sent to every member as a signed list, with virtual patches for vulnerable plugin versions.
* Blocking at the ArvanCloud or Cloudflare edge, a Shield tab, a Security card in Settings, a card in the overview and a dashboard widget with a switch to fortress mode.

= 1.5.1 =
* Fix: copies of Rafoo from rafoo.ir did not offer new versions. Both Rafoo and Rafoo Complete now show updates from rafoo.ir in WordPress updates, a few minutes after each release, and install them with their signed hash checked. Updates keep working while Rafoo is paused.
* The cooperative network checks members that went quiet and removes those that no longer answer as Rafoo, so removed plugins and deleted points no longer count as downtime. A site that is still active joins again by itself.

= 1.5.0 =
* Outage rehearsal: test the whole site in outage mode, only for you, with a live preview, comparison with normal mode, a report that names the plugin or theme behind each problem, one-click fixes through the Rafoo library and settings, a score, history and a 15-minute browse-yourself mode.
* Monitoring shows results within seconds: opening the page asks the watching members to check the site at once, and a Recheck button checks a failed point again.
* Every site is checked from three points inside Iran and two abroad, shown as First, Second and Third check without revealing any member's network or location.
* A site sent to the Iranian filtering page is reported as filtered.
* rafoo.ir is asked for its outage notice every five minutes instead of every minute, each site waits a little when rafoo.ir is busy, and checks are spread over time.
* The plugin zip is about a third smaller and installs faster: compiled translations only, and the readable admin source moved to GitHub.
* The rafoo.ir network is ready for up to a million member sites.
* Fix: on shared hosting, free space no longer shows the whole server disk; it points to your hosting quota instead.
* Fix: mirror coverage (Complete) now reads the real list of the Wbweb mirror and shows Up to date, Update ready, Older version in the mirror or Not in the mirror.
* Paid and other non-wordpress.org plugins and themes, such as Divi, are never taken from the mirror and keep updating from their vendor.
* The Multiple hosts tab is now called Geo DNS.

= 1.4.0 =
* Multiple hosts with geo DNS (beta): up to three full copies of the site on hosts in other countries, one address for search engines, read-only copies that pass logins, comments and orders to the main host, health checks from every host, and automatic DNS through ArvanCloud, Gcore, Cloudflare, Amazon Route 53, Bunny DNS, cPanel or DirectAdmin, with step-by-step guides.
* New backup format for very large sites: independent .tar.gz parts (128 MB to 2 GB) that open with 7-Zip or tar, written only by appending, with streaming encryption and resume points, so backups no longer need twice the space or fail on large archives.
* Backups work on hosts with little memory, keep going when a file changes or disappears during the backup, check free space first, and send each finished part to the backup servers at once.
* A Check button opens a whole backup without writing anything, the task log can be viewed and copied, and every failure explains its cause and fix.
* Choose the part size and folders or patterns to leave out of backups.
* Backups made with Rafoo 1.3 can still be restored.

= 1.3.0 =
* Browser guard: during an outage, requests that scripts make in the browser (fetch, XHR, beacons, WebSocket, and scripts, styles, frames and images added later) to unreachable servers fail at once instead of hanging, and the resulting errors stay quiet.
* Block list: stop any host or address everywhere, on the server, in the page and in the browser, also from a button in the request log.
* WordPress Heartbeat in smart mode and merging of identical requests a page sends to its own AJAX or REST address.
* The manual scan also opens pages with your own login, so files that only signed-in users load get local copies too.
* Pause: one switch turns off every change Rafoo makes, for testing or finding a conflict.
* A help button next to every section and switch.
* Works with Elementor and Hello, Divi, Beaver Builder, Bricks, Oxygen and the Customizer, and keeps its first script out of cache and delay-JS optimizations.
* Site Health: four checks and a Rafoo section in the Info tab.
* Multisite: network settings, as a starting point for new sites or applied to every site.
* Arabic, Turkish and Russian translations. Dates, numbers and percentages follow the user's language everywhere, and the restore page for an empty host is translated too.
* The dashboard now answers in each user's own language, even when it differs from the site language.

= 1.2.0 =
* Monitoring: an opt-in cooperative network of Rafoo sites that check each other from inside and outside Iran, with five points in each and a choice of every one or five minutes.
* Checks run in the background after a reply is sent, need no cron job and pause automatically when they cannot help, for example during an international shutdown.

= 1.1.0 =
* Setup after activation with three ready-made setups.
* Built-in captcha, and replacement of reCAPTCHA, hCaptcha and Turnstile during outages.
* Safe updates with automatic rollback.
* Backups, restore, remote servers and site transfer.
* Outage help for WordPress's own outside files, analytics scripts, social sign-in and email.
* Log of outside requests.

= 1.0.0 =
* First release.

== Upgrade Notice ==
= 1.6.2 =
Clearer Persian text everywhere. No change to settings or behavior.

= 1.6.1 =
Shows the Rafoo icon in updates and the details of each new version from rafoo.ir.

= 1.6.0 =
Adds Rafoo Shield, a firewall that is on in Smart mode after the update. Real visitors are not stopped; the Shield tab shows every stopped request with a button to allow it.

= 1.5.1 =
Fixes updates from rafoo.ir: after this version, new versions of Rafoo appear in WordPress updates by themselves.

= 1.5.0 =
Outage rehearsal, monitoring results within seconds with Recheck, three checks inside Iran and two abroad, and a smaller plugin zip.

= 1.4.0 =
Multiple hosts with geo DNS (beta) and a new backup format for very large sites. Rafoo on the other site must also be 1.4 to move a site.

= 1.3.0 =
Browser guard, block list, Pause switch, Site Health checks, Multisite network settings and three new languages.

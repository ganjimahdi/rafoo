# Rafoo (رفو)

Free WordPress plugin that keeps sites fast and working when the international internet is cut off or heavily filtered. Built for sites hosted in Iran, useful anywhere: local copies of CDN files and fonts make every site faster and more private.

> Rafoo is built by a group of Iranian developers for the Iranian web community, and for everyone who keeps a website open when the internet is not. All of its infrastructure is paid for by [Wbweb](https://wbweb.ir), so Rafoo stays free for everyone.
>
> رفو را جمعی از توسعه‌دهندگان ایرانی برای جامعه وب ایران ساخته‌اند؛ برای همه کسانی که می‌خواهند سایتشان حتی وقتی اینترنت قطع است باز بماند. همه هزینه‌های زیرساخت آن را [Wbweb](https://wbweb.ir) پرداخت می‌کند تا رفو برای همه رایگان بماند.

**Website:** [rafoo.ir](https://rafoo.ir) · **Guide:** [rafoo.ir/guide](https://rafoo.ir/guide) · **Live Iran internet monitor:** [rafoo.ir/monitor](https://rafoo.ir/monitor) · **Made by** [Wbweb](https://wbweb.ir)

## Editions

| | Rafoo | Rafoo Complete (رفو کامل) |
| --- | --- | --- |
| Main file | `plugin/rafoo.php` | `plugin/rafoo-complete.php` |
| Where | [rafoo.ir](https://rafoo.ir), wordpress.org (in review) | [rafoo.ir](https://rafoo.ir) |
| Local copies, outage mode, browser guard, captcha, safe updates, backups, multiple hosts with geo DNS (beta), monitoring, Site Health, Multisite | yes | yes |
| Updates of WordPress, plugins, themes and translations from the signed Iranian mirror | no | yes |
| Backup servers | SFTP, FTPS, FTP | also implicit FTPS (port 990), and FTP over cURL on hosts without PHP's FTP extension |

Both editions are free and share the same code. Install only one; activating Rafoo Complete deactivates Rafoo. Ready-to-install zips of every version are on the [Releases](../../releases) page and on [rafoo.ir](https://rafoo.ir/#download), with the same SHA-256 hashes.

## Features

**Files and fonts**
- Finds JavaScript and CSS from jsDelivr, unpkg, cdnjs, the jQuery CDN, Google Hosted Libraries, BootstrapCDN and the Font Awesome CDN, also inside page HTML written by themes and page builders.
- Copies them to `wp-content/uploads/rafoo` from the Wbweb mirror inside Iran or the original CDN, checks each file against its `integrity` hash, the original file or a signed mirror index, and serves it from your domain.
- Copies Google Fonts with `font-display: swap`, with the font files referenced by each stylesheet. A daily check compares every local file with its recorded hash.
- Optional scan with your own login, so files that only signed-in users load are copied too.

**Outage mode**
- Checks servers outside and inside Iran every minute, in the background. Three failed rounds switch outage mode on, three good rounds switch it off. If even domestic servers fail, the problem is the server's own network and the mode is left alone. A signed notice from rafoo.ir can speed up the switch; your own checks always decide.
- During an outage, server requests to foreign hosts fail at once instead of waiting for a timeout, and a host that fails twice in two minutes is skipped for three minutes.

**Browser and internal requests**
- Browser guard: a 4.5 KB script first in `<head>` makes `fetch`, XHR, beacons, WebSocket and late-added scripts, styles, frames and images to unreachable hosts fail at once during an outage. Unknown hosts get a 4-second limit instead of being blocked, so Iranian services on `.com` keep working.
- Block list: hosts, `*.` wildcards or URL prefixes stopped everywhere (server, page, browser), also from a button in the request log.
- Merging of identical in-flight GET requests to the site's own admin-ajax and REST, and a smart WordPress Heartbeat (60 s in editors, 120 s elsewhere).
- Pause: one switch turns off every change Rafoo makes; checks, copies, backups and monitoring keep running.

**Outside services**
- Letter avatars instead of Gravatar, placeholders for YouTube, Vimeo and Google Maps, removal of analytics scripts, hiding of social sign-in buttons, WordPress's own outside files (emoji, font library list, remote patterns, Openverse), and a mail queue with an optional domestic SMTP server for outages.
- Built-in proof-of-work captcha for login, registration, comments, Contact Form 7, WPForms and WooCommerce. It also stands in for reCAPTCHA, hCaptcha and Turnstile during an outage.

**Safety and backups**
- Safe updates: the previous version is kept, the site is checked after each update and only the update that broke it is rolled back.
- Full or incremental backups in short steps (shared hosting friendly) as independent `.tar.gz` parts of 128 MB to 2 GB that open with 7-Zip or tar, written only by appending, with resume points, a backup check, a task log and low-memory operation. Optional streaming XChaCha20-Poly1305 encryption, destinations over SFTP, FTPS or FTP with automatic detection, restore with automatic fallback, site-to-site transfer with a one-time code, and moving to an empty host with a small installer.

**Multiple hosts with geo DNS (beta)**
- A main host (usually in Iran) and up to three full copies on hosts in other countries, all serving the same domain. Each host also has a technical subdomain that only Rafoo uses; normal visits to it get a 301 to the main address with `X-Robots-Tag: noindex`, so search engines see one site.
- The first copy reuses the site transfer. After that the main host records which tables change and copies compare only those, in key ranges with checksums, plus a merge of sorted file lists. Changes reach the copies within seconds.
- Copies are read-only: POST requests, wp-admin, login, REST writes, WooCommerce cart, checkout and account pages and requests with login or cart cookies are relayed to the main host with a signed header. If the main host cannot be reached, a clear 503 page is shown.
- Every host checks the others each minute. A copy is taken out of DNS only after three failed checks while no other host still reaches it; the main host always stays in.
- Routing through ArvanCloud, Gcore, Cloudflare (Load Balancing), Amazon Route 53 or Bunny DNS, through cPanel or DirectAdmin zones on each host (both hosts' nameservers on the domain), or manual records for any other DNS. Live DNS check from every host, search engine checks (redirects, robots.txt, sitemap, canonical) and crawl statistics with reverse-DNS verified bots. Certificate checks are answered by whichever host has the file.

**Monitoring**
- Opt-in cooperative network of Rafoo sites that check each other from five points inside and five outside Iran.

**Site Health, Multisite and languages**
- Four Site Health checks (connection state, checks on time, local copies, pause) and a Rafoo section in Site Health › Info.
- Multisite: the internet state is shared by the network; files, logs and backups are per site. Network Admin › Rafoo sets settings for every site, either as a starting point for new sites or applied to all sites and locked. Privacy and backup choices stay with each site.
- English, Persian, Arabic, Turkish and Russian; right-to-left and left-to-right; dates, numbers and percentages in each user's own format. A help button next to every section and switch.
- Compatible with Elementor (and Hello), Divi, Beaver Builder, Bricks, Oxygen, the Customizer and the major cache plugins, whose page caches are cleared when the mode or the list of local files changes.

## Requirements

WordPress 6.5 or later, PHP 7.4 to 8.4. Apache, LiteSpeed or Nginx. Multisite is supported (multiple hosts not yet). Backups need zlib; encryption and multiple hosts need sodium. Restoring backups made before 1.4 needs ZipArchive.

## Installation

1. Install the zip from Plugins › Add New › Upload, or from wordpress.org once it is listed.
2. Activate it. A short setup offers three ready-made setups or a step-by-step choice.
3. On low-traffic sites add a real cron job that requests `wp-cron.php` every minute, so outages are noticed on time. Site Health tells you if checks are late.

## Settings

| Section | Settings |
| --- | --- |
| Files | serve local copies (always, only during outages, off) · read page HTML · local Google Fonts · WordPress's own outside files · scan with my account |
| Browser and internal requests | browser guard · merge repeated requests · Heartbeat (smart, default) · block list |
| Outside services | local avatars · built-in captcha · analytics scripts (each: off, during outages, always) · video and map placeholders · hide social sign-in · keep unsent emails |
| Updates and safety | updates from the mirror and translations (Complete only) · safe updates · automatic backups |
| Outside requests | request log · always allowed servers |
| Privacy | anonymous usage reports (off by default) |

Every setting is explained by its help button in the dashboard and in the [guide](https://rafoo.ir/guide).

## WP-CLI

```
wp rafoo status [--format=table|json|csv|yaml]   connection state and asset counts
wp rafoo mode auto|offline|online                set the mode by hand
wp rafoo check                                   check the connection now
wp rafoo scan                                    find CDN files on a few pages
wp rafoo sync [--budget=120]                     download pending copies
wp rafoo assets [--format=...]                   list local copies
wp rafoo verify                                  check local files against their hashes
wp rafoo purge                                   clear supported page caches
```

## For developers

Actions: `rafoo_mode_changed( bool $offline )`, `rafoo_map_changed`, `rafoo_settings_changed( array $after, array $before )`, `rafoo_purged( array $plugins )`, `rafoo_rest_routes( string $namespace )`.

Filters:

| Filter | Use |
| --- | --- |
| `rafoo_offline` | override the detected mode |
| `rafoo_external_targets`, `rafoo_domestic_targets` | connection check addresses |
| `rafoo_allowed_host` | allow a host during an outage |
| `rafoo_domestic_hosts`, `rafoo_foreign_hosts` | host lists used by the server and browser guard |
| `rafoo_browser_guard` | the browser guard configuration |
| `rafoo_analytics_hosts`, `rafoo_social_login_selectors` | what is removed or hidden |
| `rafoo_captcha_difficulty`, `rafoo_captcha_scripts`, `rafoo_captcha_verify`, `rafoo_captcha_store_api` | captcha behaviour |
| `rafoo_health_targets`, `rafoo_rollback_max_bytes` | safe updates |
| `rafoo_backup_exclude`, `rafoo_backup_step_seconds`, `rafoo_backup_background`, `rafoo_transfer_endpoint`, `rafoo_loopback_url` | backups and transfer |
| `rafoo_geo_api_base`, `rafoo_geo_sslverify`, `rafoo_geo_exclude`, `rafoo_geo_skip_tables`, `rafoo_geo_dynamic`, `rafoo_geo_dynamic_paths`, `rafoo_geo_relay_timeout` | multiple hosts |

REST namespace `rafoo/v1`; every route needs `manage_options`, and mode, pause, backups and network settings need `manage_network_options` on Multisite.

## Layout

```
plugin/        plugin code shared by both editions (src/Updates is Rafoo Complete only)
admin-ui/      Svelte 5 source of the admin screen and the browser guard (built into plugin/assets)
```

Build with `pnpm install` and `pnpm build` in `admin-ui/`. Translations are in `plugin/languages` (`rafoo.pot` for new languages).

## Privacy

Nothing about visitors is sent anywhere. Usage reports and the monitoring network are off unless an administrator turns them on. Every outside service is listed in [`plugin/readme.txt`](plugin/readme.txt) under External services. Details: [rafoo.ir/privacy](https://rafoo.ir/privacy).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE). The Rafoo and Wbweb names and logos are not covered by the GPL; publish modified versions under a different name.

---

## فارسی

رفو افزونه رایگان و متن‌باز وردپرس است. وقتی اینترنت بین‌الملل قطع یا کند می‌شود، سایت‌های میزبانی‌شده در ایران را سریع و سالم نگه می‌دارد. دو نسخه دارد:

- **رفو:** نسخه مخزن وردپرس.
- **رفو کامل:** همه امکانات رفو، به‌اضافه به‌روزرسانی وردپرس، افزونه‌ها، قالب‌ها و ترجمه‌ها از مخزن ایرانی با امضای دیجیتال.

امکانات: نسخه محلی کتابخانه‌ها و فونت‌ها از آینه داخلی، تشخیص خودکار قطعی، نگهبان مرورگر و فهرست سیاه، ادغام درخواست‌های تکراری و Heartbeat هوشمند، کلید توقف، کپچای داخلی، به‌روزرسانی امن، پشتیبان و انتقال سایت حتی برای سایت‌های بسیار بزرگ، چند هاست با DNS جغرافیایی (بتا) بدون آسیب به سئو، موتور پایش، سلامت سایت، تنظیمات شبکه Multisite و رابط فارسی، انگلیسی، عربی، ترکی و روسی با دکمه راهنما برای هر بخش.

دانلود، راهنما و پایش لحظه‌ای اینترنت ایران در [rafoo.ir](https://rafoo.ir). پرسش و گزارش اشکال را در [Issues](../../issues) یا از [rafoo.ir/contact](https://rafoo.ir/contact) بفرستید.

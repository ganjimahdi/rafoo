# Changelog

All notable changes to Rafoo. Both editions share one version number: **Rafoo** (wordpress.org edition) and **Rafoo Complete** (adds updates from the signed Iranian mirror). Rafoo Point, the standalone monitoring point, has its own versions at the end.

Downloads: [GitHub Releases](https://github.com/ganjimahdi/rafoo/releases) and [rafoo.ir](https://rafoo.ir).

## [1.6.4] - 2026-11-12
### Added
- **Files.ir backup destination**, in both editions, off by default. Files.ir is a storage service inside Iran that gives up to 5 GB free. Scheduled and manual backups are sent there in the same parts as to the other places, old backups are deleted there by the same keeping rules, and a check or restore downloads from Files.ir when the local copy is gone.
  - Connect with an API key from the Files.ir account. Rafoo checks the key, picks the personal drive (or the first workspace the key may use), creates the folder `Rafoo/<site host>`, and uploads, reads back and deletes a test file before saving it.
  - The key is stored encrypted with the site's own key (libsodium secretbox), only its last four characters are shown, and it is sent only to the Files.ir API host, never to the storage addresses Files.ir hands out for uploads and downloads. It cannot be hashed, because Files.ir needs the key itself on every request.
  - Uploads follow the Files.ir API: direct upload, S3 single and multipart upload, and resumable tus upload, whichever Files.ir chooses. Large parts go in pieces within Rafoo's short backup steps and continue after an interruption; a part with the same name is replaced. With the PHP cURL extension, parts are streamed from disk; without it, pieces of up to 64 MB are sent from memory.
  - The card shows the folder, used and free space, speed, the last send and a Test button. Turning Files.ir off keeps the key; Remove the key deletes it.
  - The Rafoo team has no business relationship with Files.ir; the Files.ir team gave permission for this connection.
- **Where backups are kept.** A new card at the top of the Backup page with three places: this host, other servers (SFTP, FTPS or FTP) and Files.ir. The new `backup_remote` setting (on by default, so nothing changes after the update) stops sending to the servers without deleting them. With every place off, copies stay on this host so no backup is lost.
- The privacy policy text and the readme's External services section describe Files.ir.

## [1.6.3] - 2026-11-11
### Fixed
- **Search engine crawlers could be banned.** A verified Googlebot that crawled a few spam links with attack-like parameters could reach the reputation limit and be banned on the whole site, and its address could be reported to community defense and listed for every member. Verified crawlers are now never banned, rate limited, challenged, reported to rafoo.ir or blocked at the CDN. Only the single attack-like request gets 403.
- **DNS lookups during visits.** Checking a crawler used a reverse and forward DNS lookup inside the visitor's request, which could hold the page for seconds. Crawlers of Google, Bing, Apple and DuckDuckGo are now recognized from their official IP ranges shipped with the plugin (`dev/crawlers`, built by `tools/crawlers.py`). Other crawlers, such as Yandex, are queued and confirmed by DNS in the background every minute; until then they are treated as normal visitors. A crawler name from an address that fails the check counts as suspicious (group `fakebot`, never reported).
### Changed
- **Status codes.** The browser check answers `503` with `Retry-After: 30`, so a search engine that ever meets it retries later instead of dropping the page. Blocks stay `403`, limits `429` with `Retry-After`.
- **Responses in steps.** When a browser's score reaches the ban limit, it first gets a browser check; it is banned only when the score keeps rising to twice the limit. Scripts that are not browsers are banned at the limit as before. Passing the check halves the score and doubles the limit for as long as the pass lasts.
- **Community list in Smart mode.** Foreign addresses on the list now get a browser check instead of a block. Without passing it they cannot sign in, use XML-RPC or send POST requests, but they can still load files and pages from a page cache. Strict and Fortress still block them. Iranian addresses keep the browser check only.
- **Fewer writes.** On hosts without APCu, requests from an address below a quarter of the first threshold are counted by sampling (one in four, counted as four), so about four times fewer counter files are written while floods are still caught near the limit. Requests from verified crawlers write nothing.
- **Smaller page script.** When no outage, block list or rehearsal needs the full browser guard, Rafoo adds only the 0.7 KB request merger (`assets/dedupe.js`) instead of the 6.8 KB guard.
- **rafoo.ir** drops reports for crawler addresses and never lists them.

## [1.6.2] - 2026-11-11
### Changed
- **Persian text.** Every Persian string of the plugin (1,525), the rafoo.ir site and panel, the monitoring point and the GitHub README was rewritten in shorter sentences and everyday words. One glossary (`docs/WRITING-FA.md`) now fixes one term per concept, for example آدرس instead of نشانی, مسدود کردن for block, and پچ امنیتی موقت instead of the literal وصله مجازی for a virtual patch.
- In the Persian Shield screens, the temporary security patch and community defense are explained where they first appear.
- English and the other languages, settings and behavior are unchanged.
- **Admin panel.** The rafoo.ir Shield page uses the same layout as the other panel pages.

## [1.6.1] - 2026-11-11
### Fixed
- **Plugin icon.** Copies of Rafoo from rafoo.ir and GitHub showed the grey plug in Dashboard › Updates. The Rafoo icon (SVG, 128 and 256 px) now ships with the plugin and is used in the update list and the details window, also during an outage.
- **Version details.** “View details” and “View version details” opened a window with “Plugin not found” or a WordPress.org error, because Rafoo is not served from WordPress.org. Rafoo now answers the details window itself: an introduction, a banner (right-to-left for Persian and Arabic screens), requirements, the WordPress version it was tested with, and the changes of each version, read in Persian or English from a signed answer of `rafoo.ir/api/v1/releases/{edition}/info`. The Update Now button in the window installs the signed package. Without rafoo.ir the window still opens and links to the guide.
- Rafoo Complete now carries a `Tested up to` header, so WordPress no longer reports it as untested.
### Note
- The icon and the details window come from the installed Rafoo, so sites on 1.6.0 or older still see the old window once while updating to 1.6.1.

## [1.6.0] - 2026-11-11
### Added
- **Rafoo Shield**, a web application firewall in both editions, on in Smart mode by default.
  - Runs when Rafoo is included, with Rafoo moved to the front of the plugin list, or before WordPress with one click through `auto_prepend_file`. The setting is written to `.htaccess` or `.user.ini` depending on the server and is removed on deactivation.
  - Stops SQL injection, cross-site scripting, file reading, command execution, PHP object injection, dangerous uploads (PHP files, scripts in SVG), SSRF, XML entity attacks, vulnerability scanners and probes for configuration files, backups and version control folders. Inputs are decoded up to three times before matching.
  - Locks sign-in after repeated failures (WordPress login, WooCommerce, XML-RPC, application passwords), limits request floods and 404 bursts, closes XML-RPC multicall and hides the user list from visitors.
  - Three modes: **Smart**, **Strict** and **Fortress**. Fortress asks each new visitor for a few-second browser check, built in and without outside services. Payment returns from Iranian gateways, Rafoo's signed endpoints and verified search engine bots always pass.
  - Editors and administrators keep using page builders freely: their posted content skips the content rules, while uploads, object injection and virtual patches are still checked.
  - Reads the real visitor address behind Cloudflare, ArvanCloud, Geo DNS copies and your own proxies, without trusting forged headers.
  - Stop pages in the site language with a reference code, and matching JSON answers for REST and AJAX.
- **Community defense** (off by default). Member sites report the addresses they stopped every ten minutes, signed with the site key. rafoo.ir lists an address only when at least three members on two networks report it, with new members counting half. Addresses abroad are blocked; Iranian addresses get a browser check because mobile networks share addresses. Listings last 6 hours, then 24 hours, 3 days and 7 days on repeat. Members download a signed list, and the status pulse announces new lists.
- **Virtual patches** from rafoo.ir, applied only on sites with the vulnerable version of the named plugin.
- **CDN edge blocking**: long blocks are sent to ArvanCloud firewall rules or Cloudflare IP access rules, at most 200, and removed when they expire. The Geo DNS key can be reused.
- Shield tab with attack types, busiest addresses and paths, events with Allow and Block buttons, lists and settings; a Security card in Settings, a card in the overview and a dashboard widget with a one-click switch to fortress mode.
- rafoo.ir admin page for community defense: members, reports, listed addresses with release, trending targeted plugins and a virtual patch editor that tests every pattern before saving.
### Changed
- The suggested privacy policy text describes the shield and its cookies.

## [1.5.1] - 2026-11-09

### Fixed
- **Update system.** Copies of Rafoo installed from rafoo.ir did not offer new versions in WordPress updates. The standard edition had no update check at all, and Rafoo Complete only saw a new version after up to a day of caches. Both editions now check `rafoo.ir` for their own release, and the update shows in WordPress updates a few minutes after each release. Every package is downloaded from `rafoo.ir/downloads/` and installed only when its signed SHA-256 hash and size match.
- Updates of Rafoo itself keep working while Rafoo is paused.
- The rafoo.ir admin page of the network no longer fails when the network has a single member.

### Added
- **Update pulse.** The signed status answer from rafoo.ir, which Rafoo already reads every few minutes, now carries the number of the latest version. When it names a newer one, Rafoo fetches the signed release and adds it to WordPress updates at once.
- **Member verification in the cooperative network.** rafoo.ir checks every member that stops contacting it. Each check asks the member's `?rafoo-ping` address for a signed answer:
  - A member that still answers stays as a monitored site but no longer watches others.
  - A member whose site opens without a Rafoo answer (plugin removed, point deleted, key changed, domain gone) is taken out of the checks at once and removed after a second answer an hour later.
  - A member that cannot be reached at all stays monitored for a week, then is taken out, and is removed after two weeks.
  - A watcher seeing no Rafoo answer brings the check forward.
  - Checks are put off while the international internet is down for members abroad, or when most checks fail at once, so a network problem on rafoo.ir's side removes nobody.
- **Manual removal.** The rafoo.ir administrator can check any member on demand, remove it from the network, and optionally block it from joining again. Removed members are listed with the reason, and blocks can be lifted.
- A site that was removed while Rafoo is still active there joins again by itself, at most once an hour, unless it was blocked.

### Changed
- The copy submitted to wordpress.org (`rafoo-1.5.1-wporg.zip`) has no update code and updates only through wordpress.org. The copies on rafoo.ir and GitHub carry the `Update URI: https://rafoo.ir/rafoo` header.

### Note
- Sites running the standard edition 1.5.0 or older need one manual update to 1.5.1. After that, updates arrive by themselves. Rafoo Complete 1.4.0 and 1.5.0 find 1.5.1 on their own within a day.

## [1.5.0] - 2026-11-09

### Added
- **Outage rehearsal.** Test the whole site in outage mode in your own browser only, with a live preview and a comparison with normal mode. Visitors, crawlers and page caches keep seeing the normal site.
- The rehearsal opens the home page, latest post, a page, the WooCommerce shop, product, cart, checkout and account, login, dashboard, post editor and up to five custom addresses.
- Its report names the plugin or theme behind each problem and offers one-click fixes through the Rafoo library and settings. It also gives a 0–100 score, keeps the last ten runs and has a 15-minute browse-yourself mode.
- Monitoring results arrive within seconds: opening the Monitoring page asks the watching members to check the site at once.
- A **Recheck** button repeats a failed check.
- A site sent to the Iranian filtering page is reported as filtered.

### Changed
- Every site is checked from three points inside Iran and two abroad, shown as First, Second and Third check, without revealing any member's network or location.
- rafoo.ir is asked for its outage notice every five minutes instead of every minute, or at once when the site's own checks fail. Each site waits a little when rafoo.ir is busy, and checks are spread over time.
- The plugin zip is about a third smaller and installs faster. It ships compiled translations only, and the readable admin source lives in this repository.
- The rafoo.ir network is ready for up to a million member sites.
- The **Multiple hosts** tab is now called **Geo DNS**.

### Fixed
- On shared hosting, free space showed the whole server disk (for example hundreds of gigabytes). It now points to the hosting quota. Hosts can pass the real quota with the `rafoo_backup_quota` filter.
- Mirror coverage (Complete) said "Not in the mirror" for items the mirror has. It now reads the real list of the Wbweb mirror and shows Up to date, Update ready, Older version in the mirror, Not in the mirror or Updates from its vendor.
- Paid and other non-wordpress.org plugins and themes, such as Divi, are never taken from the mirror and keep updating from their vendor.
- Help notes no longer run past the edge of the screen.

## [1.4.0] - 2026-11-05

### Added
- **Multiple hosts with geo DNS (beta).** Keep up to three full copies of the site on hosts in other countries, all on one address for search engines.
- The copies are read-only and pass logins, comments and orders to the main host. Every host checks the others.
- DNS is set automatically through ArvanCloud, Gcore, Cloudflare, Amazon Route 53, Bunny DNS, cPanel or DirectAdmin, with step-by-step guides.
- A **Check** button opens a whole backup without writing anything.
- The backup task log can be viewed and copied.
- Choose the part size and the folders or patterns to leave out of backups.

### Changed
- New backup format for very large sites: independent `.tar.gz` parts of 128 MB to 2 GB that open with 7-Zip or tar. Parts are written only by appending, with streaming encryption and resume points, so a backup no longer needs twice the space or fails on a large archive.
- Backups work on hosts with little memory and keep going when a file changes or disappears during the backup.
- Free space is checked before a backup starts, and each finished part is sent to the backup servers at once.
- Every failure explains its cause and how to fix it.

### Compatibility
- Backups made with Rafoo 1.3 can still be restored.

## [1.3.0] - 2026-11-04

### Added
- **Browser guard.** During an outage, requests that scripts make in the browser to unreachable servers fail at once instead of hanging, and the resulting errors stay quiet. This covers fetch, XHR, beacons, WebSocket, and scripts, styles, frames and images added later.
- **Block list.** Stop any host or address everywhere: on the server, in the page and in the browser. A button in the request log adds a host to it.
- WordPress Heartbeat in smart mode.
- Merging of identical requests a page sends to its own AJAX or REST address.
- The manual scan can open pages with your own login, so files that only signed-in users load get local copies too.
- **Pause:** one switch turns off every change Rafoo makes, for testing or finding a conflict.
- A help button next to every section and switch.
- Site Health: four checks and a Rafoo section in the Info tab.
- Multisite network settings, used as a starting point for new sites or applied to every site.
- Arabic, Turkish and Russian translations.

### Changed
- Works with Elementor and Hello, Divi, Beaver Builder, Bricks, Oxygen and the Customizer.
- Rafoo's first script is kept out of cache and delay-JS optimizations.
- Dates, numbers and percentages follow the user's language everywhere. The restore page for an empty host is translated too.
- The dashboard answers in each user's own language, even when it differs from the site language.

## [1.2.0] - 2026-11-03

### Added
- **Monitoring.** An opt-in cooperative network of Rafoo sites that check each other from inside and outside Iran, every one or five minutes.
- Checks run in the background after a reply is sent, need no cron job, and pause automatically when they cannot help, for example during an international shutdown.

## [1.1.0] - 2026-11-03

### Added
- Setup after activation, with three ready-made setups and a new settings page.
- Built-in proof-of-work captcha for login, registration, comments, Contact Form 7, WPForms and WooCommerce.
- The built-in captcha stands in for reCAPTCHA, hCaptcha and Turnstile during outages.
- Safe updates: the site is checked after each update, and only the update that broke it is rolled back.
- Previous versions are kept, with a manual rollback.
- Backups (full or only what changed), restore with automatic fallback, and backup servers over SFTP, FTPS or FTP.
- Site-to-site transfer with a one-time code, and moving to an empty host with a small installer.
- Outage help for WordPress's own outside files (emoji, font library, remote patterns, Openverse).
- Removal of analytics scripts, hiding of social sign-in buttons, and an email queue with an optional domestic SMTP server.
- Log of outside requests.
- Complete: language packs from the signed mirror.

## [1.0.0] - 2026-11-02

First release.

- Finds JavaScript and CSS loaded from jsDelivr, unpkg, cdnjs, the jQuery CDN, Google Hosted Libraries, BootstrapCDN and the Font Awesome CDN, also when themes and page builders write them into the page.
- Copies those files to `wp-content/uploads/rafoo` from the Wbweb mirror inside Iran or the original CDN, and serves them from your domain.
- Every copy is checked against its `integrity` hash, the original file or a signed mirror index.
- Google Fonts are copied with `font-display: swap`. A daily check compares every local file with its recorded hash.
- Automatic outage mode: servers inside and outside Iran are checked every minute. Three failed rounds switch the mode on and three good rounds switch it off.
- During an outage, server requests to foreign hosts fail at once instead of waiting for a timeout.
- Letter avatars instead of Gravatar, and placeholders for YouTube, Vimeo and Google Maps during outages.
- Page caches of the common cache plugins are cleared when the mode or the list of local files changes.
- WP-CLI commands: `wp rafoo status`, `mode`, `check`, `scan`, `sync`, `assets`, `verify`, `purge`.
- Multisite, PHP 7.4 to 8.4, Apache, LiteSpeed and Nginx. Persian and English, right-to-left and left-to-right.
- Complete: updates of WordPress, plugins and themes from the signed Iranian mirror.

## Rafoo Point

A standalone PHP script (`point/`) that turns an empty host into a monitoring point of the cooperative network, without WordPress.

### [Point 2.1.1] - 2026-11-11
- Clearer Persian text on the status page, the home page and in `README.txt`, with Persian digits for counts and times.
- Points on 2.x update to 2.1.1 by themselves.

### [Point 2.1.0] - 2026-11-09
- After the rafoo.ir administrator removes and blocks a point, it waits a day before asking to join again instead of every ten minutes.
- Points on 2.0 update to 2.1 by themselves.

### [Point 2.0.0] - 2026-11-09
- Bundles sodium_compat, so the sodium PHP extension is no longer needed.
- Answers wake requests from rafoo.ir and checks a site at once.
- Detects the Iranian filtering page.
- Updates itself from a signed rafoo.ir release. The previous version is kept in `data/previous.php`. Turn it off with `config.php` (`'auto_update' => false`) or run `php index.php --update` by hand.
- Checks in batches and backs off when rafoo.ir is busy.
- Points on 1.0 need one manual update to 2.0. Later versions arrive on their own.

### [Point theme 1.0.0] - 2026-11-08
- A minimal theme for WordPress sites that act as monitoring points. On activation it creates the point page and makes it the home page. Persian, right-to-left, with the Vazirmatn font bundled and no outside requests.

### [Point 1.0.0] - 2026-11-06
- First release: answers signed `?rafoo-ping` requests, checks the sites rafoo.ir assigns every minute, and sends signed results.
- Public home page, and a status page at `?rafoo-status`.

---

## خلاصه فارسی

- **۱٫۶٫۴:** پشتیبان‌گیری در Files.ir (فایلز)، در هر دو نسخه رفو و به‌طور پیش‌فرض خاموش. Files.ir یک سرویس ذخیره‌سازی داخل ایران است و تا ۵ گیگابایت فضای رایگان می‌دهد. اتصال با کلید API انجام می‌شود. کلید رمزگذاری‌شده ذخیره می‌شود و فقط به API سرویس Files.ir فرستاده می‌شود. پشتیبان‌های زمان‌بندی‌شده و دستی تکه‌تکه ارسال می‌شوند و اگر ارسال قطع شود، از همان‌جا ادامه پیدا می‌کند. کارت تازه «محل نگهداری پشتیبان‌ها» بالای صفحه پشتیبان آمده است: همین هاست، سرورهای دیگر (SFTP، FTPS یا FTP) و Files.ir، هر کدام با کلید روشن و خاموش. تیم رفو با Files.ir همکاری تجاری ندارد.
- **۱٫۶٫۳:** سپر رفو دیگر هیچ‌وقت ربات واقعی گوگل، بینگ، اپل و داک‌داک‌گو را مسدود، محدود یا گزارش نمی‌کند و از آن بررسی مرورگر نمی‌خواهد؛ فقط همان یک درخواست حمله‌مانند ۴۰۳ می‌گیرد. ربات‌ها از روی IPهای رسمی شناخته می‌شوند و وسط بازدید هیچ جست‌وجوی DNS انجام نمی‌شود. بررسی مرورگر با کد ۵۰۳ پاسخ می‌دهد تا موتورهای جست‌وجو آن را موقت بدانند. پاسخ پله‌ای: مرورگر مشکوک اول بررسی مرورگر می‌گیرد و فقط اگر ادامه دهد مسدود می‌شود. در حالت هوشمند، IP خارجی فهرست جمعی بررسی مرورگر می‌گیرد. روی هاست بدون APCu حدود چهار برابر کمتر فایل نوشته می‌شود و اسکریپت هر صفحه از ۶٫۸ به ۰٫۷ کیلوبایت رسید.
- **۱٫۶٫۲:** همه متن‌های فارسی افزونه، سایت و پنل rafoo.ir، نقطه پایش و GitHub با جمله‌های کوتاه‌تر و اصطلاح‌های آشنا بازنویسی شد؛ مثلاً «پچ امنیتی موقت» به‌جای «وصله مجازی». در سپر رفو، «پچ امنیتی موقت» و «ایمنی جمعی» همان‌جا که اولین بار دیده می‌شوند توضیح دارند. تنظیمات و رفتار افزونه تغییری نکرد.
- **۱٫۶٫۱:** لوگوی رفو در به‌روزرسانی‌های وردپرس، و پنجره «مشاهده جزئیات» با معرفی، تصویر و تغییرات هر نسخه از rafoo.ir.
- **۱٫۶٫۰:** سپر رفو: فایروالی که پیش از وردپرس اجرا می‌شود، با سه حالت هوشمند، سخت‌گیر و سنگر؛ ایمنی جمعی و پچ امنیتی موقت؛ مسدود کردن IP مهاجم در CDN ابرآروان و کلادفلر.
- **۱٫۵٫۱:** مشکل سیستم به‌روزرسانی برطرف شد: هر دو نسخه رفو، معمولی و کامل، نسخه تازه rafoo.ir را چند دقیقه بعد از انتشار در به‌روزرسانی‌های وردپرس نشان می‌دهند و فقط بعد از بررسی امضای دیجیتال نصب می‌کنند. شبکه هم‌یاری عضوی را که دیگر تماس نمی‌گیرد بررسی می‌کند و اگر رفو واقعاً آنجا نصب نباشد، حذفش می‌کند. مدیر rafoo.ir هم می‌تواند یک عضو را دستی حذف و مسدود کند. سایت‌هایی که نسخه معمولی ۱٫۵٫۰ یا قدیمی‌تر دارند، باید یک بار دستی به ۱٫۵٫۱ به‌روزرسانی شوند.
- **۱٫۵٫۰:** مانور قطعی، نتیجه پایش در چند ثانیه و دکمه «بررسی دوباره»، ۳ بررسی داخل و ۲ بررسی خارج ایران، تشخیص فیلترینگ، فایل zip کوچک‌تر و آمادگی شبکه برای یک میلیون سایت. رفع مشکل: فضای خالی روی هاست اشتراکی، پوشش مخزن از فهرست واقعی مخزن Wbweb، و دست نزدن به افزونه و قالب تجاری مثل Divi. زبانه «چند هاست» حالا «Geo DNS» نام دارد.
- **۱٫۴٫۰:** چند هاست با Geo DNS (بتا) و قالب تازه پشتیبان برای سایت‌های خیلی بزرگ.
- **۱٫۳٫۰:** نگهبان مرورگر، فهرست سیاه، Heartbeat هوشمند، دکمه توقف، دکمه راهنما، سلامت سایت، تنظیمات شبکه Multisite و زبان‌های عربی، ترکی و روسی.
- **۱٫۲٫۰:** موتور پایش و شبکه هم‌یاری رفو.
- **۱٫۱٫۰:** راه‌اندازی بعد از فعال‌سازی، کپچای داخلی، به‌روزرسانی امن با برگشت خودکار، پشتیبان‌گیری و انتقال سایت، و کمک هنگام قطعی برای ایمیل، ابزارهای آمار و ورود با شبکه‌های اجتماعی.
- **۱٫۰٫۰:** اولین نسخه: کپی محلی فایل‌های CDN و Google Fonts، حالت قطعی خودکار و لغو فوری درخواست‌های خارجی هنگام قطعی.
- **نقطه پایش ۲٫۱٫۱:** متن ساده‌تر در صفحه وضعیت و عددهای فارسی. نقطه‌های ۲٫x خودشان به‌روزرسانی می‌شوند.
- **نقطه پایش ۲٫۱٫۰:** بعد از اینکه مدیر rafoo.ir نقطه را حذف و مسدود کند، یک روز صبر می‌کند و بعد دوباره درخواست عضویت می‌دهد. نقطه‌های ۲٫۰ خودشان به‌روزرسانی می‌شوند.
- **نقطه پایش ۲٫۰٫۰:** بدون نیاز به افزونه sodium، بررسی فوری، تشخیص فیلترینگ و به‌روزرسانی خودکار با امضای دیجیتال.

[1.6.4]: https://github.com/ganjimahdi/rafoo/releases/tag/v1.6.4
[1.6.3]: https://github.com/ganjimahdi/rafoo/releases/tag/v1.6.3
[1.6.2]: https://github.com/ganjimahdi/rafoo/releases/tag/v1.6.2
[1.6.1]: https://github.com/ganjimahdi/rafoo/releases/tag/v1.6.1
[1.6.0]: https://github.com/ganjimahdi/rafoo/releases/tag/v1.6.0
[1.5.1]: https://github.com/ganjimahdi/rafoo/releases/tag/v1.5.1
[1.5.0]: https://github.com/ganjimahdi/rafoo/releases/tag/v1.5.0
[1.4.0]: https://github.com/ganjimahdi/rafoo/releases/tag/v1.4.0
[1.3.0]: https://github.com/ganjimahdi/rafoo/releases/tag/v1.3.0
[1.2.0]: https://github.com/ganjimahdi/rafoo/releases/tag/v1.2.0
[1.1.0]: https://github.com/ganjimahdi/rafoo/releases/tag/v1.1.0
[1.0.0]: https://rafoo.ir
[Point 2.1.1]: https://github.com/ganjimahdi/rafoo/releases/tag/v1.6.2
[Point 2.1.0]: https://github.com/ganjimahdi/rafoo/releases/tag/v1.5.1
[Point 2.0.0]: https://github.com/ganjimahdi/rafoo/releases/tag/v1.5.0
[Point theme 1.0.0]: https://github.com/ganjimahdi/rafoo/releases/tag/v1.5.0
[Point 1.0.0]: https://github.com/ganjimahdi/rafoo/tree/main/point

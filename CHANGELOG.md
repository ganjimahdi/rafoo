# Changelog

All notable changes to Rafoo. Both editions share one version number: **Rafoo** (wordpress.org edition) and **Rafoo Complete** (adds updates from the signed Iranian mirror). Rafoo Point, the standalone monitoring point, has its own versions at the end.

Downloads: [GitHub Releases](https://github.com/ganjimahdi/rafoo/releases) and [rafoo.ir](https://rafoo.ir).

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

- **۱٫۵٫۰:** مانور قطعی، نتیجه پایش در چند ثانیه و دکمه «بررسی مجدد»، ۳ بررسی داخل و ۲ بررسی خارج ایران، تشخیص فیلترینگ، zip کوچک‌تر، آمادگی شبکه برای یک میلیون سایت. رفع ایراد: فضای خالی روی هاست اشتراکی، پوشش مخزن از فهرست واقعی مخزن Wbweb، و دست نزدن به افزونه و قالب تجاری مثل Divi. زبانه «چند هاست» حالا «Geo DNS» نام دارد.
- **۱٫۴٫۰:** چند هاست با DNS جغرافیایی (بتا) و قالب تازه پشتیبان برای سایت‌های بسیار بزرگ.
- **۱٫۳٫۰:** نگهبان مرورگر، فهرست سیاه، Heartbeat هوشمند، کلید توقف، دکمه راهنما، سلامت سایت، تنظیمات شبکه Multisite و زبان‌های عربی، ترکی و روسی.
- **۱٫۲٫۰:** موتور پایش و شبکه هم‌یاری رفو.
- **۱٫۱٫۰:** راه‌اندازی بعد از فعال‌سازی، کپچای داخلی، آپدیت امن با برگشت خودکار، پشتیبان و انتقال سایت، و کمک در قطعی برای ایمیل، تحلیل و ورود اجتماعی.
- **۱٫۰٫۰:** نخستین نسخه: نسخه محلی فایل‌های CDN و Google Fonts، حالت قطعی خودکار و رد فوری درخواست‌های خارجی در قطعی.
- **نقطه پایش ۲٫۰٫۰:** بی‌نیاز از افزونه sodium، بررسی فوری، تشخیص فیلترینگ و به‌روزرسانی خودکار امضاشده.

[1.5.0]: https://github.com/ganjimahdi/rafoo/releases/tag/v1.5.0
[1.4.0]: https://github.com/ganjimahdi/rafoo/releases/tag/v1.4.0
[1.3.0]: https://github.com/ganjimahdi/rafoo/releases/tag/v1.3.0
[1.2.0]: https://github.com/ganjimahdi/rafoo/releases/tag/v1.2.0
[1.1.0]: https://github.com/ganjimahdi/rafoo/releases/tag/v1.1.0
[1.0.0]: https://rafoo.ir
[Point 2.0.0]: https://github.com/ganjimahdi/rafoo/releases/tag/v1.5.0
[Point theme 1.0.0]: https://github.com/ganjimahdi/rafoo/releases/tag/v1.5.0
[Point 1.0.0]: https://github.com/ganjimahdi/rafoo/tree/main/point

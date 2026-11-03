# Rafoo (رفو)

Free WordPress plugin that keeps sites hosted in Iran fast and working when the international internet is cut off.

**Website:** [rafoo.ir](https://rafoo.ir) · **Live Iran internet monitor:** [rafoo.ir/monitor](https://rafoo.ir/monitor) · **Made by** [Wbweb](https://wbweb.ir)

## Editions

| Edition | Main file | Where | Updates from the Iranian mirror |
| --- | --- | --- | --- |
| Rafoo | `plugin/rafoo.php` | [rafoo.ir](https://rafoo.ir), wordpress.org (in review) | no |
| Rafoo Complete (رفو کامل) | `plugin/rafoo-complete.php` | [rafoo.ir](https://rafoo.ir) | yes, signed |

Both editions are free and share the same code. Install only one; activating Rafoo Complete deactivates Rafoo.

Ready-to-install zip files of every version are on the [Releases](../../releases) page and on [rafoo.ir](https://rafoo.ir/#download).

## What it does

- Copies CDN libraries (jsDelivr, unpkg, cdnjs, jQuery, Bootstrap, Font Awesome) and Google Fonts to the site and serves them locally, checked against their integrity hash.
- Detects outages every minute and fails requests to unreachable outside servers instantly, so the dashboard does not freeze.
- Built-in proof-of-work captcha that replaces reCAPTCHA, hCaptcha and Turnstile during outages.
- Safe updates with automatic rollback, encrypted backups to SFTP, FTPS or FTP, restore and site transfer.
- Rafoo Complete also updates WordPress, plugins, themes and translations from a signed Iranian mirror.

Full description: [`plugin/readme.txt`](plugin/readme.txt).

## Requirements

WordPress 6.5 or later, PHP 7.4 or later. Multisite is supported.

## Layout

```
plugin/        plugin code shared by both editions (src/Updates is Rafoo Complete only)
admin-ui/      Svelte 5 source of the admin screen (built into plugin/assets)
```

Build the admin screen with `pnpm install` and `pnpm build` in `admin-ui/`.

## Privacy

Nothing about visitors is sent anywhere. Usage reports are off unless an administrator turns them on. Details: [rafoo.ir/privacy](https://rafoo.ir/privacy).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE). The Rafoo and Wbweb names and logos are not covered by the GPL; publish modified versions under a different name.

---

## فارسی

رفو افزونه رایگان و متن‌باز وردپرس است. وقتی اینترنت بین‌الملل قطع یا کند می‌شود، سایت‌های میزبانی‌شده در ایران را سریع و سالم نگه می‌دارد. دو نسخه دارد:

- **رفو:** نسخه مخزن وردپرس.
- **رفو کامل:** همه امکانات رفو، به‌اضافه به‌روزرسانی وردپرس، افزونه‌ها، قالب‌ها و ترجمه‌ها از مخزن ایرانی با امضای دیجیتال.

دانلود، راهنما و پایش لحظه‌ای اینترنت ایران در [rafoo.ir](https://rafoo.ir). پرسش و گزارش اشکال را در [Issues](../../issues) یا از [rafoo.ir/contact](https://rafoo.ir/contact) بفرستید.

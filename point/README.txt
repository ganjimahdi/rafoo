نقطه پایش رفو (Rafoo Point) — نسخه 2.0.0

این پوشه یک نقطه شبکه هم‌یاری رفو می‌سازد، بدون وردپرس. نقطه دقیقاً مثل افزونه رفو کار می‌کند:
به درخواست امضاشده ?rafoo-ping پاسخ می‌دهد، هر دقیقه سایت‌هایی را که rafoo.ir به آن می‌سپارد می‌سنجد
و نتیجه را امضاشده به rafoo.ir می‌فرستد. هیچ صفحه، داده یا بازدیدکننده‌ای بررسی نمی‌شود.

پیش‌نیاز
- PHP 7.2 یا بالاتر با افزونه curl (در بیشتر هاست‌ها روشن است)
- افزونه sodium لازم نیست: اگر هاست آن را نداشته باشد، کتابخانه sodium_compat همراه نقطه به کار می‌رود.
- پوشه نقطه و پوشه data قابل نوشتن باشد
- بهتر است SSL (https) روی دامنه فعال باشد

نصب
1. محتوای این zip را در public_html هاست خالی (یا یک زیرپوشه مثل public_html/rafoo-point) بگذارید.
2. اگر هاست فایل پیش‌فرض index.html دارد، آن را پاک کنید (اجباری نیست؛ ولی بهتر است).
3. صفحه وضعیت را باز کنید: https://example.ir/?rafoo-status . اگر «شناسه عضو» عددی مثل #12 نشان داد، نقطه وصل شده است.
   صفحه اصلی (https://example.ir/) برای همه بازدیدکنندگان فقط معرفی کوتاه نقطه را نشان می‌دهد.
4. در کنترل پنل هاست یک Cron Job با زمان‌بندی * * * * * (هر دقیقه) بسازید و دستوری را که صفحه وضعیت نشان می‌دهد وارد کنید:
   curl -s -o /dev/null https://example.ir/?rafoo-tick=1
   یا اگر هاست اجازه می‌دهد:
   php /home/USER/public_html/index.php
5. تمام. آمار در زبانه «موتور پایش» سایت‌های عضو کم‌کم پر می‌شود.

تازه‌های 2.0
- بررسی فوری: وقتی مدیر یک سایت عضو صفحه پایش را باز می‌کند یا «بررسی مجدد» را می‌زند، rafoo.ir نقطه را بیدار می‌کند و نقطه همان لحظه آن سایت را می‌سنجد.
- تشخیص فیلترینگ: اگر پاسخ سایت صفحه فیلترینگ باشد، نتیجه «فیلتر شده» ثبت می‌شود.
- به‌روزرسانی خودکار امن: هر چند ساعت نسخه تازه از rafoo.ir گرفته می‌شود و فقط وقتی جایگزین می‌شود که امضا و هش آن درست باشد. نسخه قبلی در data/previous.php می‌ماند.
  برای خاموش کردن، فایل config.php بسازید:  <?php return array( 'auto_update' => false );
  برای بررسی دستی:  php index.php --update
- مصرف کمتر: بررسی‌ها در دسته‌های ۸تایی انجام می‌شوند و اگر rafoo.ir مشغول باشد، نقطه کمی صبر می‌کند.

نکته‌ها
- پوشه data کلید امضای همین نقطه است. هرگز آن را از یک هاست به هاست دیگر کپی نکنید؛ برای هر هاست zip را تازه باز کنید.
- هر هاست فقط یک نقطه. نقطه‌ها روی شرکت‌های میزبانی و شهرهای مختلف، نتیجه دقیق‌تری می‌دهند.
- اگر فایروال یا افزونه امنیتی دارید، درخواست‌های ?rafoo-ping را مجاز کنید.
- نقطه روی هاست‌های دیگر و سایت‌های همان سرور اثری ندارد: فقط همین پوشه را می‌نویسد و هر دقیقه چند درخواست کوچک می‌فرستد.
- برای حذف، پوشه را پاک کنید. rafoo.ir نقطه بی‌پاسخ را پس از ۳ روز کنار می‌گذارد.
- sodium_compat از Paragon Initiative Enterprises با مجوز ISC است (sodium_compat/LICENSE).

---

Rafoo Point 2.0.0 — a cooperative monitoring point without WordPress.
Upload the files to an empty host, open https://your-host/?rafoo-status once, and add a cron job every
minute with the command shown on that page. The home page only shows a short public introduction.
Needs PHP 7.2+ with curl and a writable folder. The sodium extension is optional: the bundled
sodium_compat (ISC license) is used when it is missing.
New in 2.0: instant checks when rafoo.ir wakes the point, filtering detection, signed automatic
updates (turn off with 'auto_update' => false in config.php; check by hand with php index.php --update)
and lighter batches with back-off.
Never copy the data folder to another host: it holds this point's signing key.

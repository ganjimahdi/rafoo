نقطه پایش رفو (Rafoo Point) — نسخه ۲٫۱٫۱

با این پوشه، بدون وردپرس، یک نقطه پایش در شبکه هم‌یاری رفو می‌سازید. نقطه دقیقاً مثل افزونه رفو کار می‌کند:
به درخواست ?rafoo-ping (با امضای دیجیتال) پاسخ می‌دهد، هر دقیقه سایت‌هایی را که rafoo.ir تعیین می‌کند بررسی می‌کند
و نتیجه را با امضای دیجیتال به rafoo.ir می‌فرستد. هیچ صفحه، داده یا اطلاعات بازدیدکننده‌ای بررسی نمی‌شود.

پیش‌نیاز
- PHP 7.2 یا بالاتر با افزونه curl (در بیشتر هاست‌ها روشن است)
- افزونه sodium لازم نیست: اگر هاست آن را نداشته باشد، کتابخانه sodium_compat که همراه نقطه است استفاده می‌شود.
- پوشه نقطه و پوشه data باید قابل نوشتن باشند.
- بهتر است SSL (https) روی دامنه فعال باشد.

نصب
1. محتوای این فایل zip را در public_html یک هاست خالی (یا یک زیرپوشه مثل public_html/rafoo-point) قرار دهید.
2. اگر هاست فایل پیش‌فرض index.html دارد، آن را پاک کنید (اجباری نیست، اما بهتر است).
3. صفحه وضعیت را باز کنید: https://example.ir/?rafoo-status . اگر «شناسه عضو» یک عدد مثل #12 نشان داد، نقطه وصل شده است.
   صفحه اصلی (https://example.ir/) برای همه بازدیدکننده‌ها فقط یک معرفی کوتاه از نقطه نشان می‌دهد.
4. در کنترل پنل هاست یک کرون (Cron Job) با زمان‌بندی * * * * * (هر دقیقه) بسازید و دستوری را که صفحه وضعیت نشان می‌دهد وارد کنید:
   curl -s -o /dev/null https://example.ir/?rafoo-tick=1
   یا اگر هاست اجازه می‌دهد:
   php /home/USER/public_html/index.php
5. تمام. نتیجه‌ها کم‌کم در زبانه «موتور پایش» سایت‌های عضو نمایش داده می‌شوند.

تازه‌های ۲٫۱
- اگر مدیر rafoo.ir نقطه را از شبکه حذف و مسدود کند، نقطه به‌جای اینکه هر ده دقیقه دوباره امتحان کند، یک روز صبر می‌کند و بعد دوباره درخواست عضویت می‌دهد.

تازه‌های ۲٫۰
- بررسی فوری: وقتی مدیر یک سایت عضو صفحه پایش را باز می‌کند یا روی «بررسی دوباره» می‌زند، rafoo.ir به نقطه خبر می‌دهد و نقطه همان لحظه آن سایت را بررسی می‌کند.
- تشخیص فیلترینگ: اگر پاسخ سایت صفحه فیلترینگ باشد، نتیجه «فیلتر شده» ثبت می‌شود.
- به‌روزرسانی خودکار امن: هر چند ساعت نسخه تازه از rafoo.ir دریافت می‌شود و فقط وقتی جایگزین می‌شود که امضای دیجیتال و هش آن درست باشد. نسخه قبلی در data/previous.php نگه داشته می‌شود.
  برای خاموش کردن، فایل config.php بسازید:  <?php return array( 'auto_update' => false );
  برای بررسی دستی:  php index.php --update
- مصرف کمتر: بررسی‌ها در گروه‌های ۸تایی انجام می‌شوند و اگر rafoo.ir شلوغ باشد، نقطه کمی صبر می‌کند.

نکته‌ها
- کلید امضای دیجیتال همین نقطه در پوشه data است. هیچ‌وقت این پوشه را از یک هاست به هاست دیگر کپی نکنید؛ برای هر هاست، فایل zip را از نو باز کنید.
- روی هر هاست فقط یک نقطه بسازید. نقطه‌هایی که روی شرکت‌های هاستینگ و شهرهای مختلف باشند، نتیجه دقیق‌تری می‌دهند.
- اگر فایروال یا افزونه امنیتی دارید، درخواست‌های ?rafoo-ping را مجاز کنید.
- نقطه روی سایت‌های دیگر همان سرور اثری ندارد: فقط در همین پوشه می‌نویسد و هر دقیقه چند درخواست کوچک می‌فرستد.
- برای حذف، پوشه را پاک کنید. rafoo.ir نقطه‌ای را که دیگر پاسخ نمی‌دهد، بعد از دو بررسی با فاصله یک ساعت، از شبکه حذف می‌کند.
- sodium_compat از Paragon Initiative Enterprises با مجوز ISC است (sodium_compat/LICENSE).

---

Rafoo Point 2.1.1 — a cooperative monitoring point without WordPress.
Upload the files to an empty host, open https://your-host/?rafoo-status once, and add a cron job every
minute with the command shown on that page. The home page only shows a short public introduction.
Needs PHP 7.2+ with curl and a writable folder. The sodium extension is optional: the bundled
sodium_compat (ISC license) is used when it is missing.
New in 2.0: instant checks when rafoo.ir wakes the point, filtering detection, signed automatic
updates (turn off with 'auto_update' => false in config.php; check by hand with php index.php --update)
and lighter batches with back-off.
New in 2.1: a point removed and blocked by the rafoo.ir administrator waits a day before asking to join again.
Never copy the data folder to another host: it holds this point's signing key.

AGITECH SEEDLINGS WEBSITE: PERMANENT PHOTOS AND CATALOGUE
==========================================================

Why photos disappeared before
-----------------------------
A single HTML file can only remember things inside the browser it is opened in.
This package adds api.php, which stores your products, banners, events and PHOTOS
on your web hosting, so every browser and computer sees the same site.

WHAT YOU NEED
-------------
Web hosting with PHP 7.4 or newer (almost every cPanel host, including the one
for agitech.co.ke, has this).

STEP BY STEP (cPanel)
---------------------
1. Log in to your hosting, open "File Manager" and go to public_html
   (or the folder of the domain/subdomain you want to use).
2. Upload BOTH files into the SAME folder:
      index.html
      api.php
3. Open https://YOUR-DOMAIN/index.html#admin in your browser.
   (If you named it index.html and it is in the main folder, https://YOUR-DOMAIN/#admin also works.)
4. The first time, the page asks you to CREATE YOUR ADMIN PASSWORD.
   Do this straight away, before anyone else visits that page.
   Use at least 8 characters.
5. The admin header should show "Permanent server storage" in green.
   If it says "This browser only", api.php is not working: see Troubleshooting.
6. Optional: if you already built the site in a browser (with photos), open
   Admin > Backup > "Move this browser's site and photos to the server".
   This must be done from the SAME address you used before.
   If you used the file from your computer, open the old copy, go to Backup,
   copy the export text, then paste it into Backup > Import on the live site.
7. From now on every photo you upload is saved as a file in the "uploads" folder
   and shows on every browser and device.

WHAT IS STORED WHERE
--------------------
   uploads/   your photos (public, shown on the website)
   data/      catalogue, orders, messages, subscribers and the password hash
              (blocked from the web by .htaccess)

BACK UP
-------
Download the "data" and "uploads" folders from File Manager now and then.
Orders and messages are in the Admin panel, under Orders and Messages.

TROUBLESHOOTING
---------------
* "This browser only" badge: api.php is missing from the folder, or PHP is off.
  Open https://YOUR-DOMAIN/api.php?a=data . You should see text starting {"ok":true.
* "Cannot create folder" / "not writable": in File Manager set the site folder
  permission to 755 (or 775), then reload the admin page.
* Photo upload fails on large pictures: photos are shrunk automatically to 1600px
  before upload, so this normally does not happen. If it does, ask your host to
  raise upload_max_filesize.
* Forgot the password: in File Manager delete data/auth.json, then open
  #admin again to create a new password. Your products and photos are kept.
* If the site is behind Cloudflare or a cache plugin, do not cache api.php.

SECURITY NOTES
--------------
* The password is stored only as a secure hash. Sign-in is limited to 8 wrong
  tries per 15 minutes per connection.
* Only JPG, PNG, WebP and GIF pictures are accepted, and uploads can never run as code.
* Keep HTTPS turned on for your domain.

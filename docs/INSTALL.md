# Installation

The installer is a web wizard. There is no file to edit by hand, no Composer
install and no build step — upload the files, point the web root at `public/`,
and open `/install`.

---

## 1. Requirements

The wizard checks all of these on its first screen and will not continue until
the required ones pass.

**Required**

| | |
|---|---|
| PHP | 8.1 or newer |
| Extensions | `pdo`, `pdo_mysql`, `curl`, `openssl`, `json`, `mbstring`, `fileinfo`, `zip` |
| Database | MySQL 5.7.8+ or MariaDB 10.3+ |
| Writable | `config/`, `storage/`, `uploads/`, `logs/`, `backups/` |

**Recommended** — checked and reported, but not blocking:

| | |
|---|---|
| `gd` | PNG QR codes. Without it, QR codes are still produced as SVG. |
| `sodium` | XChaCha20-Poly1305 for secrets at rest. Without it, AES-256-GCM is used. |
| `phar`, `zlib` | Update packaging |
| `upload_max_filesize`, `post_max_size` | At least as large as your configured maximum upload |
| `memory_limit` | 128M or more |
| `max_execution_time` | 60s or more — updates need the time |

**Web server.** HTTPS is required in production — the application enforces it
once `force_https` is on. An nginx example is in `nginx.conf.example`. Apache
setup is below.

### Apache

**Preferred — point the document root at `public/`:**

```apache
<VirtualHost *:443>
    ServerName print.example.com
    DocumentRoot /var/www/krishna-printer/public

    <Directory /var/www/krishna-printer/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

`AllowOverride All` matters: without it Apache ignores `.htaccess`, no
rewriting happens, and every route returns Apache's own 404. `mod_rewrite`
must be enabled (`a2enmod rewrite`).

**Shared hosting — document root fixed at `public_html`:** upload the whole
project there. The root `.htaccess` forwards every request into `public/`, so
no panel change is needed. Each directory that must not be served carries its
own `Require all denied`, and anything that is not a real file inside `public/`
goes to the front controller — so no PHP source outside `public/` is ever
served or executed.

Both layouts are tested: `/install` returns the wizard, and `/config/app.php`,
`/app/Core/Router.php` and `/storage/` are refused.

### Troubleshooting: "Not Found — Apache Server at … Port 443"

Apache's *own* 404 page (rather than the application's) means the request never
reached PHP. Work down this list:

1. **Is `.htaccess` being read?** In the vhost or `<Directory>` block for your
   document root, set `AllowOverride All`. On cPanel this is usually already
   set; on a self-managed server it defaults to `None`, which silently disables
   every `.htaccess` in the project.
2. **Is `mod_rewrite` enabled?** `a2enmod rewrite && systemctl restart apache2`.
   Check with `apachectl -M | grep rewrite`.
3. **Did the root `.htaccess` upload?** It starts with a dot, so many FTP
   clients hide it and skip it. Confirm it exists next to `README.md`.
4. **Where does the document root point?** If `https://your-domain/public/install`
   works but `https://your-domain/install` does not, the document root is the
   project root and step 3 is your problem.
5. **Is PHP running at all?** If `index.php` downloads as a file instead of
   executing, PHP is not wired into Apache for this vhost.

---

## 2. Create the database

```sql
CREATE DATABASE krishna_printer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'kpms'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT ALL PRIVILEGES ON krishna_printer.* TO 'kpms'@'localhost';
FLUSH PRIVILEGES;
```

An empty database is all that is needed. The wizard creates every table.

---

## 3. Run the wizard

Open `https://your-domain/install`. Until installation finishes, every other URL
redirects here.

| Step | What happens |
|---|---|
| 1. Server requirements | Checks the table above and shows each result. |
| 2. Database | Tests your credentials by actually connecting, and reports the server version. Wrong credentials are rejected with the reason. |
| 3. Application | Site name, public URL, timezone, currency. |
| 4. Administrator account | The first admin. Weak passwords are refused. |
| 5. System setup | Uploads, retention, printing defaults. |
| 6. Database migration | Runs the migrations and reports each one. |
| 7. Default settings | Seeds the admin, your first location with its QR code, and a starting price list. |
| 8. Security lock | Writes `config/config.php`, generates the encryption key, and locks the installer. |

At the end you get the QR URL for your first location. Print it and stick it on
the printer.

---

## 4. After installing

**The installer locks itself.** Step 8 writes `storage/installed.lock`; while it
exists, `/install` returns 403. Verified as tests `T28.11` and `T28.12`. You do
not need to delete anything by hand, but removing the `/install` route entirely
at the web-server level is a reasonable extra precaution.

**`config/config.php` holds live credentials and the encryption key.** It is
written mode `0600`, is in `.gitignore`, and is preserved across updates. Never
commit it. Verified as test `T28.13`.

**Set up cron.** Nothing prints without it:

```cron
* * * * * php /path/to/krishna-printer/bin/worker.php --once >> /path/to/logs/worker.log 2>&1
* * * * * php /path/to/krishna-printer/bin/cron.php         >> /path/to/logs/cron.log 2>&1
```

`worker.php --once` dispatches queued jobs and reclaims expired leases, then
exits; it can also be run as a service with `--daemon`. It is only needed for
printers the server reaches directly — printers driven by a location agent do
not need it, because the agent pulls its own work.

`cron.php` runs every minute and decides internally what is due: health checks,
expired-upload cleanup, payment reconciliation and scheduled backups. See
[OPERATIONS.md](OPERATIONS.md).

**Add your printers.** Admin → Printers → Add. Then, on each one:

1. **Test connection** — confirms the server can reach it.
2. **Probe capabilities** — reads what the printer itself reports.
3. **Verify by test print** — for anything a probe cannot settle.

Until a capability is probed or manually verified it is not offered to
customers, and a printer with no verified capabilities accepts no jobs. This is
deliberate; see [PRINTING.md](PRINTING.md).

**Install the location agent** if the printer is not directly reachable from the
server — which is the normal case, and the required one for a Canon PIXMA
GM4070. Admin → Print agents → Register, then run the shown command on the
shop's Linux box. Copy the token when it is displayed; only its hash is stored
and it cannot be shown again.

---

## 5. Upgrading later

Admin → System → Updates. Enter the repository, branch and a token once.
**Check for update** shows the incoming version, commit, message, date, author
and changed files. **Update now** takes a snapshot, applies the update, runs
migrations, and rolls back automatically if any step fails.

`config/config.php`, `uploads/`, `storage/`, `logs/` and `backups/` are preserved
across every update. See [OPERATIONS.md](OPERATIONS.md).

---

## 6. Reinstalling from scratch

Only on a system you are willing to wipe:

```bash
rm -f storage/installed.lock config/config.php
mysql -e "DROP DATABASE krishna_printer; CREATE DATABASE krishna_printer
          CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

This destroys every job, location and uploaded file. Take a backup first.

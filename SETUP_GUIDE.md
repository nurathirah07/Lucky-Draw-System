# ASTECH Lucky Draw – Setup Guide (v2)

## Running it
1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Database:
   - **New install:** create an empty database in phpMyAdmin (e.g. `astech_lucky`), click it, then **Import** `FULL_DATABASE_SETUP.sql`.
   - **Moving an existing system** (e.g. to Hostinger): **Export** your database from phpMyAdmin and **Import** that file instead. It also copies your settings, codes and entries.
3. In `config.php`, set `$base_url` to this computer's LAN IP, e.g. `http://192.168.0.116/astech_lucky`.
4. Register an admin at `admin_register.php`, then log in at `admin_lucky.php`.

## Admin sections (sidebar)
| Section | File | What you do there |
|---|---|---|
| Overview | `admin_lucky.php` | Sign in; key numbers, entries per day, QR code activity, latest entries, getting-started checklist |
| Customers | `admin_customers.php` | Search customers, filter winners, see each customer's product codes, WhatsApp them, export CSV |
| QR codes | `generate_qr.php` | **Batches** tab: create and print codes. **Connection test** tab: check phones can reach the system |
| Lucky draw | `admin_draw.php` | Spin the wheel, see and notify winners |
| Settings | `admin_settings.php` | Campaign name, start/end date, prizes; shows QR link and WhatsApp setup |

## Customer pages (Malay)
| Page | Purpose |
|---|---|
| `index.php` | Campaign status, countdown, how to scan with the phone camera, prizes |
| `lucky.php?pid=CODE` | Registration form (opened by scanning a QR code) |
| `check.php` | Customers check their entries by phone number |

### How customers scan
Customers scan the QR code with their **phone's own Camera app** (iPhone and Android read QR codes built in) and tap the link that appears. That opens `lucky.php?pid=CODE` on this site. The homepage explains these steps. The system has no camera function of its own.

The link inside each QR code comes from `$base_url`, so it must be correct before you print (see below).

### Keep the computer's IP address fixed
QR codes contain `$base_url`. If the router gives this computer a new IP (it changes after restarts), printed QR codes stop working. Reserve a fixed IP for this computer in the router (DHCP / address reservation), set `$base_url` in `config.php` to it, then print.

## Words used in the system
- **Product code** – the code printed in a QR sticker (e.g. `K7QF-9XM2`).
- **Entry** – one registered product code. One entry = one chance in the draw.
- **Customer** – one phone number. A customer can have many entries.
- **Batch** – a group of product codes created together.

## Typical campaign workflow
The Overview page shows this as a checklist until the first three steps are done.
1. **Settings** → set the campaign name, start/end date and prizes.
2. **QR codes** → create a batch → *Open* → *Print* → stick the codes on products.
3. **QR codes → Connection test** → scan with a phone on the same Wi-Fi.
4. Customers scan, register, and can check their entries at `check.php`.
5. **Lucky draw** → pick a prize → *Spin the wheel* → notify the winner on WhatsApp.

## How the rules work
- Only codes created in **QR Codes** can be registered; each code once only.
- Registration is only accepted between the campaign start and end date.
- Phone numbers are stored as `60XXXXXXXXX`, so `012-345 6789` and `+60 12 345 6789` are the same customer.
- If a returning customer types a different name, their customer record takes the latest name.
- Spin wheel: every registered product is one ticket. The winner is picked on the server with a secure random number; the wheel animation just shows the result. An entry can never win twice, and "Exclude customers who have already won" is on by default. Removing a winner puts the entry back into the draw.

## WhatsApp (Settings → WhatsApp)
Everything is managed in the admin panel – `config.php` only holds the database login and the QR base URL.
- **Admin WhatsApp buttons** (on by default, free): open WhatsApp with a ready-made message – the winner message on the Lucky draw page, the entry confirmation on the Customers page. You press send.
- **Messages:** the winner and entry confirmation texts can be edited, with a live preview. Placeholders such as `{name}`, `{customer_id}`, `{product_code}`, `{entries}`, `{prize}` and `{campaign}` are filled in automatically. "Reset to default" restores the original text.

## QR code batches
Any batch can be deleted (bin icon in the batch list, or *Delete batch* on the batch page). If some of its codes are registered, you choose:
- **Keep the registered entries** (default): only the codes are deleted. Customers keep those entries and stay in the lucky draw; the codes cannot be registered again.
- **Also delete the registered entries:** removes those entries and any winners drawn from them, updates each customer's entry count, and removes customers left with no entries.

The dialog shows how many entries, customers and winners are affected before you confirm. Deleting cannot be undone.

## Troubleshooting
- **Errors** are written to `logs/error.log` (visitors only see a generic message).
- **QR codes don't open on phones:** open *QR codes → Connection test*; it compares `$base_url` with this computer's IP. Phones must be on the same Wi-Fi.
- **"Kod produk tidak sah":** the code was not created in *QR codes*.

## Admin accounts
`admin_register.php` only works while **no admin account exists** (first-time setup, e.g. a fresh Hostinger install). After that it shows "Admin registration closed" and the sign-in page hides the sign-up link.
To add another admin later: set `$allow_admin_register = true;` in `config.php`, create the account at `admin_register.php`, then set it back to `false`.

## Privacy (PDPA)
Customers must tick a consent box on the registration form (also checked on the server). It links to `privacy.php` (Notis Privasi), which is also in the footer of every customer page. Add your business contact details to `privacy.php` under "Hak anda" so customers know how to reach you.

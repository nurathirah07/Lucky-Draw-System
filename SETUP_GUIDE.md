# ASTECH Lucky Draw – Setup Guide (v2)

## Running it
1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Database:
   - **New install:** import `FULL_DATABASE_SETUP.sql` in phpMyAdmin (Import tab).
   - **Upgrading from v1:** import `MIGRATION_v2.sql` (safe to run more than once).
3. In `config.php`, set `$base_url` to this computer's LAN IP, e.g. `http://192.168.0.116/astech_lucky`.
4. Register an admin at `admin_register.php`, then log in at `admin_lucky.php`.

## Pages
| Page | Who | Purpose |
|---|---|---|
| `index.php` | Public | Homepage with campaign status, countdown and prizes |
| `lucky.php?pid=CODE` | Public | Registration form (opened by scanning a QR code) |
| `check.php` | Public | Customers check their entries by phone number |
| `admin_lucky.php` | Admin | Login + dashboard (stats, charts, customers) |
| `admin_draw.php` | Admin | Spin wheel lucky draw + winners list |
| `generate_qr.php` | Admin | Create product codes and print QR sheets |
| `test_qr.php` | Admin | Check phones can reach the system (base URL check) |
| `admin_settings.php` | Admin | Campaign title, start/end date, prize list |
| `admin_lucky_export.php` | Admin | CSV export |

## Typical campaign workflow
1. **Campaign** → set the title, start/end date and prizes.
2. **QR Codes** → generate a batch of random codes → *View & print* → stick them on products.
3. **QR Codes → Test phone connection** → scan with a phone on the same Wi-Fi.
4. Customers scan, register, and can check their entries at `check.php`.
5. **Spin & Winners** → pick a prize → *Spin!* → notify the winner on WhatsApp.

## How the rules work
- Only codes created in **QR Codes** can be registered; each code once only.
- Registration is only accepted between the campaign start and end date.
- Phone numbers are stored as `60XXXXXXXXX`, so `012-345 6789` and `+60 12 345 6789` are the same customer.
- If a returning customer types a different name, their customer record takes the latest name.
- Spin wheel: every registered product is one ticket. The winner is picked on the server with a secure random number; the wheel animation just shows the result. An entry can never win twice, and "Exclude customers who have already won" is on by default. Removing a winner puts the entry back into the draw.

## WhatsApp
- **Works now (free):** "Notify" buttons open WhatsApp with a ready-made message to the winner/customer.
- Set `$wa_business_number` in `config.php` to show customers a "Save proof on WhatsApp" button after registering.
- **Automatic sending (optional):** needs a WhatsApp Cloud API account from Meta with approved message templates. Fill in `$wa_api_token`, `$wa_phone_number_id`, `$wa_template_confirm` and `$wa_template_winner` in `config.php`.

## Troubleshooting
- **Errors** are written to `logs/error.log` (visitors only see a generic message).
- **QR codes don't open on phones:** open *QR Codes → Test phone connection*; it compares `$base_url` with this computer's IP. Phones must be on the same Wi-Fi.
- **"Kod produk ini tidak sah":** the code was not created in *QR Codes*.

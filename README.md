# WP Logbook

An amateur radio logbook for WordPress. Log your contacts (QSOs) from the WordPress dashboard, import and export ADIF, show your log with an "Am I in your log?" lookup on your site, and optionally upload contacts to your [QRZ.com Logbook](https://logbook.qrz.com/) through the QRZ.com Logbook API.

The QRZ.com upload is optional. The logbook works the same with it switched off.

## Features

- **Quick QSO entry**: the start time follows a UTC clock until you change it. The band is filled in from the frequency, and RST defaults to 59, 599 or blank (dB reports) depending on the mode. While you type a callsign, the form shows your earlier contacts with that station and fills in name, QTH, grid and country from the last one. **Save & log another** keeps your band and mode.
- **Logbook screen**: search, band and mode filters, sorting, and bulk actions (ADIF export, delete, QRZ.com upload).
- **ADIF field layout**: 73 fields that follow the ADIF 3.1.8 specification (see [field layout](#logbook-field-layout)), covering contest exchanges, POTA/SOTA/WWFF, satellites, and paper/LoTW/eQSL QSL status.
- **ADIF import** with duplicate detection. Handles files from other logging programs: submodes written as modes (`MODE=USB`, `MODE=FT4`), frequencies in kHz, UTF-8 or Latin-1 text, and lengths counted in bytes or characters.
- **Export** to ADIF (`.adi`) or CSV, either everything or only selected QSOs.
- **Public display** with `[wp_logbook]` and `[wp_logbook_stats]`.
- **Optional QRZ.com Logbook upload**: on save, in the background, or in bulk. Edits replace the QRZ.com copy and deletions can be passed on.
- Translation-ready (`languages/wp-logbook.pot`).

## Requirements

- WordPress 6.0 or later
- PHP 7.4 or later
- For QRZ.com upload: a QRZ.com account and the Logbook API key of your QRZ.com logbook. QRZ.com may require a paid subscription for API uploads.

## Installation

1. Copy this folder to `wp-content/plugins/wp-logbook`, or upload a zip of it under **Plugins → Add New → Upload Plugin**.
2. Activate **WP Logbook**. Activation creates the `{prefix}wplb_qsos` table.
3. Under **Logbook → Settings**, enter your station callsign and grid square. They pre-fill every new QSO.
4. Log contacts under **Logbook → Log a QSO**, or import an existing log under **Logbook → Import / Export**.

## Logging contacts

All times are UTC. Required fields are callsign, start date and time, mode, and either a band or a frequency. A frequency must lie inside the chosen band. Grid squares are checked as Maidenhead locators (`FN31` or `FN31pr`), and callsigns are stored in upper case.

The mode picker lists common modes first. Some of them are ADIF submodes and are stored as the correct pair: USB is stored as `SSB`/`USB`, FT4 as `MFSK`/`FT4`, JS8 as `MFSK`/`JS8`, and DMR as `DIGITALVOICE`/`DMR`. Every other ADIF mode follows.

The less common fields are in collapsible sections: contacted station details, activations and contests, my station, propagation and satellite, QSL, and private notes.

## QRZ.com Logbook upload (optional)

### Setting it up

1. On QRZ.com, open your logbook, go to **Settings** and copy the **API key** of the logbook you want to upload to.
2. In WordPress, open **Logbook → Settings → QRZ.com Logbook**. Tick **Upload QSOs to my QRZ.com Logbook**, paste the key and click **Test connection**. The test shows the logbook's callsign, owner and QSO count. Then save.
   - You can also keep the key out of the database by adding this to `wp-config.php`:
     ```php
     define( 'WPLB_QRZ_API_KEY', 'XXXX-XXXX-XXXX-XXXX' );
     ```
3. Make sure your **station callsign** matches the callsign of the QRZ.com logbook. QRZ.com rejects QSOs whose `STATION_CALLSIGN` does not match, and the connection test warns you about a mismatch.

The key is stored in its own option that is not autoloaded, and it is never printed back into the settings page.

### How uploads work

Each QSO has a QRZ.com status, shown in the logbook list and on the edit screen:

| Status | Meaning |
|---|---|
| Not uploaded | Waiting to be uploaded. |
| On QRZ.com | Accepted by QRZ.com. The QRZ.com log ID is stored. |
| Changed since upload | Edited after upload. The next upload replaces the QRZ.com copy. |
| Upload failed | Rejected by QRZ.com. The reason is shown, for example `wrong station_callsign` or `outside date range`. |
| Not for QRZ.com | Never uploaded. Set it per QSO or with a bulk action. |

Settings:

- **Upload on save** (default on) uploads each QSO right after it is logged or edited, and the confirmation message reports the result.
- **Background upload** uploads up to 50 waiting QSOs every hour through WP-Cron.
- **Edits** (default on): an edited QSO is re-sent with `OPTION=REPLACE`. If you changed the callsign, band, mode or time, QRZ.com no longer sees the QSO as the same contact and creates a new record. In that case the plugin deletes the old record, using its stored log ID.
- **Deletions** (default off): deleting QSOs here also deletes them from QRZ.com.

**Upload now** on the logbook screen, and the bulk action **Upload to QRZ.com now**, send QSOs in batches of 10 with a progress bar, so large logs don't hit PHP time limits. An upload stops at the first problem that isn't specific to one QSO, such as an invalid key, `RESULT=AUTH`, a network error or an HTTP error, and leaves the remaining QSOs waiting. QSOs that QRZ.com reports as duplicates count as uploaded. Failed uploads can be queued again with **Retry them**. Editing a failed QSO also queues it again.

Every field is sent to QRZ.com except your private **notes** and the contacted station's **email** address. If a QSO has no station callsign, the one from the settings is sent.

### QRZ.com Logbook API calls

The plugin follows the [QRZ.com Logbook API](https://www.qrz.com/docs/logbook/QRZLogbookAPI.html): an HTTP `POST` to `https://logbook.qrz.com/api` with form-encoded `KEY` and `ACTION` fields. Responses are `name=value` pairs joined with `&`, for example `RESULT=OK&LOGID=130877825&COUNT=1`.

| Action | Used for | Parameters sent | Response fields read |
|---|---|---|---|
| `STATUS` | Test connection | – | `RESULT`, `COUNT`, logbook details (`BOOK_NAME`, `CALLSIGN`, `OWNER`, `CONFIRMED`, `DXCC_COUNT`, `START_DATE`, `END_DATE`), also when nested in `DATA` |
| `INSERT` | Uploading a QSO | `ADIF` (one record ending in `<EOR>`), `OPTION=REPLACE` for QSOs that were uploaded before | `RESULT` (`OK`, `REPLACE`, `FAIL`, `AUTH`), `LOGID`, `COUNT`, `REASON` |
| `DELETE` | Removing QSOs from QRZ.com | `LOGIDS` (comma-separated) | `RESULT` (`OK`, `PARTIAL`, `FAIL`), `COUNT`, `LOGIDS` |

Requests carry a descriptive `User-Agent` (`WP-Logbook/<version> (WordPress/<version>) <station callsign>`).

## Shortcodes

### `[wp_logbook]`

Shows a table of QSOs, newest first, with paging. If enabled, it also shows a callsign lookup where visitors can check whether they are in your log. Portable forms such as `K1ABC/P` match too.

| Attribute | Default | Description |
|---|---|---|
| `columns` | From settings | Comma-separated column keys, e.g. `time_on,callsign,band,mode,country`. Only public fields are allowed: `time_on`, `callsign`, `band`, `freq`, `mode`, `rst_sent`, `rst_rcvd`, `name`, `qth`, `gridsquare`, `country`, `comment`, `state`, `cnty`, `dxcc`, `cont`, `cqz`, `ituz`, `iota`, `sota_ref`, `pota_ref`, `wwff_ref`, `contest_id`, `station_callsign`, `tx_pwr`, `my_gridsquare`, `my_sota_ref`, `my_pota_ref`, `prop_mode`, `sat_name`, `confirmed`. |
| `limit` | From settings (25) | QSOs per page (1–500). |
| `search` | From settings (`yes`) | Show the callsign lookup (`yes` / `no`). |
| `band` | – | Only this band, e.g. `20m`. |
| `mode` | – | Only this mode or submode, e.g. `FT8`, `SSB` (includes USB and LSB) or `FT4`. |
| `station` | – | Only QSOs made as this station callsign. |
| `callsign` | – | Only QSOs with this station. |
| `pagination` | `yes` | Show paging links. |

Example: `[wp_logbook columns="time_on,callsign,band,mode,pota_ref" mode="SSB" limit="50"]`

### `[wp_logbook_stats]`

Shows totals (QSOs, unique callsigns, DXCC entities or countries, grid squares, first and latest QSO) and QSO counts per band and per mode.

| Attribute | Default | Description |
|---|---|---|
| `show` | `totals,bands,modes` | Which parts to show. |
| `station` | – | Only QSOs made as this station callsign. |

## Import and export

- **ADIF export** writes ADIF 3.1.8 `.adi` files in plain ASCII, as the ADI format requires. Accented letters are transliterated (`José` → `Jose`), so every reader agrees on field lengths. The QRZ.com state is included as `QRZCOM_QSO_UPLOAD_STATUS` / `QRZCOM_QSO_UPLOAD_DATE`.
- **CSV export** keeps the full UTF-8 text and all columns. Use it for spreadsheets or a complete backup.
- **ADIF import** skips QSOs already in the log, meaning the same callsign, band and mode within one minute. It fills in a station callsign for records without one, and can mark imported QSOs as already on QRZ.com. `APP_QRZLOG_LOGID` and `QRZCOM_QSO_UPLOAD_STATUS` from QRZ.com exports are kept. Records with problems are reported with the reason.

## Logbook field layout

QSOs are stored in their own table (`{prefix}wplb_qsos`). Each column corresponds to an ADIF field, using the field definitions, band plan and mode/submode lists of [ADIF 3.1.8](https://www.adif.org/). Dates and times are UTC. **Public** marks fields that can be shown with `[wp_logbook]`. **QRZ.com** marks fields sent when uploading.

**Contact**

| Field | ADIF | Format | Public | QRZ.com |
|---|---|---|:-:|:-:|
| Callsign | `CALL` | Callsign, max 32 | ✓ | ✓ |
| Start (UTC) | `QSO_DATE + TIME_ON` | Date + time (UTC) | ✓ | ✓ |
| End (UTC) | `QSO_DATE_OFF + TIME_OFF` | Date + time (UTC) |  | ✓ |
| Band | `BAND` | ADIF band | ✓ | ✓ |
| Frequency (MHz) | `FREQ` | MHz | ✓ | ✓ |
| Mode | `MODE` | ADIF mode | ✓ | ✓ |
| Submode | `SUBMODE` | ADIF submode (set by the mode picker) |  | ✓ |
| RST sent | `RST_SENT` | Text, max 10 | ✓ | ✓ |
| RST received | `RST_RCVD` | Text, max 10 | ✓ | ✓ |
| Name | `NAME` | Text, max 100 | ✓ | ✓ |
| QTH | `QTH` | Text, max 100 | ✓ | ✓ |
| Grid square | `GRIDSQUARE` | Maidenhead locator | ✓ | ✓ |
| Country | `COUNTRY` | Text, max 64 | ✓ | ✓ |
| Comment | `COMMENT` | Text, max 255 | ✓ | ✓ |

**Contacted station**

| Field | ADIF | Format | Public | QRZ.com |
|---|---|---|:-:|:-:|
| State / province | `STATE` | Text (upper case), max 32 | ✓ | ✓ |
| County | `CNTY` | Text, max 64 | ✓ | ✓ |
| DXCC entity code | `DXCC` | Integer 0–999 | ✓ | ✓ |
| Continent | `CONT` | Enumeration (NA, SA, EU, AF, OC, AS, AN) | ✓ | ✓ |
| CQ zone | `CQZ` | Integer 1–40 | ✓ | ✓ |
| ITU zone | `ITUZ` | Integer 1–90 | ✓ | ✓ |
| IOTA | `IOTA` | Text (upper case), max 10 | ✓ | ✓ |
| QSL via | `QSL_VIA` | Text, max 100 |  | ✓ |
| Email | `EMAIL` | Email, max 100 |  | — |

**Activations & contests**

| Field | ADIF | Format | Public | QRZ.com |
|---|---|---|:-:|:-:|
| SOTA reference | `SOTA_REF` | Text (upper case), max 20 | ✓ | ✓ |
| POTA reference(s) | `POTA_REF` | Text (upper case), max 100 | ✓ | ✓ |
| WWFF reference | `WWFF_REF` | Text (upper case), max 20 | ✓ | ✓ |
| Special interest group | `SIG` | Text, max 50 |  | ✓ |
| SIG information | `SIG_INFO` | Text, max 100 |  | ✓ |
| Contest ID | `CONTEST_ID` | Text (upper case), max 50 | ✓ | ✓ |
| Serial sent | `STX` | Integer 0–99999999 |  | ✓ |
| Exchange sent | `STX_STRING` | Text, max 50 |  | ✓ |
| Serial received | `SRX` | Integer 0–99999999 |  | ✓ |
| Exchange received | `SRX_STRING` | Text, max 50 |  | ✓ |

**My station**

| Field | ADIF | Format | Public | QRZ.com |
|---|---|---|:-:|:-:|
| Station callsign | `STATION_CALLSIGN` | Callsign, max 32 | ✓ | ✓ |
| Operator | `OPERATOR` | Callsign, max 32 |  | ✓ |
| TX power (W) | `TX_PWR` | Watts | ✓ | ✓ |
| Rig | `MY_RIG` | Text, max 100 |  | ✓ |
| Antenna | `MY_ANTENNA` | Text, max 100 |  | ✓ |
| My grid square | `MY_GRIDSQUARE` | Maidenhead locator | ✓ | ✓ |
| My city | `MY_CITY` | Text, max 100 |  | ✓ |
| My state / province | `MY_STATE` | Text (upper case), max 32 |  | ✓ |
| My county | `MY_CNTY` | Text, max 64 |  | ✓ |
| My country | `MY_COUNTRY` | Text, max 64 |  | ✓ |
| My DXCC entity code | `MY_DXCC` | Integer 0–999 |  | ✓ |
| My CQ zone | `MY_CQ_ZONE` | Integer 1–40 |  | ✓ |
| My ITU zone | `MY_ITU_ZONE` | Integer 1–90 |  | ✓ |
| My IOTA | `MY_IOTA` | Text (upper case), max 10 |  | ✓ |
| My SOTA reference | `MY_SOTA_REF` | Text (upper case), max 20 | ✓ | ✓ |
| My POTA reference(s) | `MY_POTA_REF` | Text (upper case), max 100 | ✓ | ✓ |
| My WWFF reference | `MY_WWFF_REF` | Text (upper case), max 20 |  | ✓ |
| My special interest group | `MY_SIG` | Text, max 50 |  | ✓ |
| My SIG information | `MY_SIG_INFO` | Text, max 100 |  | ✓ |

**Propagation & satellite**

| Field | ADIF | Format | Public | QRZ.com |
|---|---|---|:-:|:-:|
| Propagation mode | `PROP_MODE` | Enumeration (AS, AUE, AUR, BS, ECH, EME, ES, F2, FAI, GWAVE, INTERNET, ION, IRL, LOS, MS, RPT, RS, SAT, TEP, TR) | ✓ | ✓ |
| Satellite name | `SAT_NAME` | Text (upper case), max 30 | ✓ | ✓ |
| Satellite mode | `SAT_MODE` | Text (upper case), max 20 |  | ✓ |
| Receive band (split) | `BAND_RX` | ADIF band |  | ✓ |
| Receive frequency (MHz) | `FREQ_RX` | MHz |  | ✓ |

**QSL**

| Field | ADIF | Format | Public | QRZ.com |
|---|---|---|:-:|:-:|
| QSL card sent | `QSL_SENT` | Enumeration (Y, N, R, Q, I) |  | ✓ |
| QSL sent date | `QSLSDATE` | Date |  | ✓ |
| QSL sent via | `QSL_SENT_VIA` | Enumeration (B, D, E) |  | ✓ |
| QSL card received | `QSL_RCVD` | Enumeration (Y, N, R, I) |  | ✓ |
| QSL received date | `QSLRDATE` | Date |  | ✓ |
| QSL received via | `QSL_RCVD_VIA` | Enumeration (B, D, E) |  | ✓ |
| QSL message | `QSLMSG` | Text, max 255 |  | ✓ |
| LoTW sent | `LOTW_QSL_SENT` | Enumeration (Y, N, R, Q, I) |  | ✓ |
| LoTW sent date | `LOTW_QSLSDATE` | Date |  | ✓ |
| LoTW confirmed | `LOTW_QSL_RCVD` | Enumeration (Y, N, R, I) |  | ✓ |
| LoTW confirmed date | `LOTW_QSLRDATE` | Date |  | ✓ |
| eQSL sent | `EQSL_QSL_SENT` | Enumeration (Y, N, R, Q, I) |  | ✓ |
| eQSL sent date | `EQSL_QSLSDATE` | Date |  | ✓ |
| eQSL confirmed | `EQSL_QSL_RCVD` | Enumeration (Y, N, R, I) |  | ✓ |
| eQSL confirmed date | `EQSL_QSLRDATE` | Date |  | ✓ |

**Notes**

| Field | ADIF | Format | Public | QRZ.com |
|---|---|---|:-:|:-:|
| Notes (private) | `NOTES` | Multi-line text |  | — |

Bookkeeping columns: `qrz_status`, `qrz_logid`, `qrz_upload_date`, `qrz_message`, `created_at`, `updated_at`.

Bands follow the ADIF band plan, from `2190m` to `submm`, with frequency limits used to derive the band. Modes are the ADIF 3.1.8 mode list, and their submodes are stored separately (`MODE` + `SUBMODE`).

## Privacy and data

- Private notes and email addresses are never shown publicly and never sent to QRZ.com.
- Deleting the plugin keeps your log unless **Delete all QSOs and settings when the plugin is deleted** is ticked under **Logbook → Settings**.
- By default only administrators (`manage_options`) can use the logbook. The `wplb_capability` filter changes the capability for the logbook screens; the settings always need `manage_options`.

## For developers

Filters:

| Filter | Purpose |
|---|---|
| `wplb_capability` | Capability required for the logbook screens (default `manage_options`). |
| `wplb_fields` | Field definitions (labels and flags). |
| `wplb_common_modes` | Modes listed first in the mode picker. |
| `wplb_adif_fields` | ADIF fields written for a QSO (`$fields, $qso, $context` where context is `file` or `qrz`). |
| `wplb_qrz_request_args` | Arguments passed to `wp_remote_post()` for QRZ.com. |
| `wplb_qrz_endpoint` | QRZ.com Logbook API URL. |
| `wplb_qrz_batch_size` | QSOs per upload request from the logbook screen (default 10). |
| `wplb_qrz_cron_batch_size` | QSOs per background upload run (default 50). |

Actions: `wplb_qso_inserted ( $id, $row )`, `wplb_qso_updated ( $id, $changes )` and `wplb_before_delete_qsos ( $ids )`.

Tests and coding standards:

```sh
php tests/unit-tests.php                                     # no WordPress needed
WPLB_INTEGRATION=1 wp eval-file tests/integration-tests.php  # disposable site with an empty logbook; QRZ.com is mocked
phpcs                                                        # WordPress Coding Standards 3 + PHPCompatibilityWP (phpcs.xml.dist)
```

## References

- [QRZ.com Logbook API developer guide](https://www.qrz.com/docs/logbook/QRZLogbookAPI.html)
- [ADIF specification](https://www.adif.org/), version 3.1.8: fields, bands, modes and submodes

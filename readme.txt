=== WP Logbook ===
Contributors: kamodev
Tags: ham radio, amateur radio, logbook, adif, qrz
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Amateur radio QSO logbook with ADIF import/export, a filterable public log (table or tiles), and optional upload to the QRZ.com Logbook.

== Description ==

WP Logbook lets amateur radio operators keep their contact (QSO) log in WordPress.

* Several logbooks with their own titles, for example home station, POTA activations, contests or a second callsign. Each can have its own station callsign and its own QRZ.com logbook.
* Quick QSO entry: the start time follows a UTC clock, the band is filled in from the frequency, RST defaults to match the mode, and the form shows earlier contacts with the station.
* 73 fields that follow the ADIF 3.1.8 specification: contest exchanges, POTA/SOTA/WWFF, satellites, and paper/LoTW/eQSL QSL status.
* Import ADIF files from other logging programs into any logbook or a new one, with duplicate detection. Export one logbook or all of them to ADIF or CSV.
* Show your log on any page as a table with `[wp_logbook]` or as tiles with `[wp_logbook_tiles]`, with pagination and visitor filters for date, callsign, band, mode, grid square, state, county, POTA, SOTA and more. Each filter can be switched on or off per shortcode. The `logbook` attribute shows one logbook per page. Statistics with `[wp_logbook_stats]`.
* Optional: upload QSOs to your QRZ.com Logbook through the QRZ.com Logbook API, on save, in the background or in bulk. Edits replace the QRZ.com copy and deletions can be passed on.

= QRZ.com =

The QRZ.com upload is optional and off by default. When you switch it on, QSO data (except your private notes and the contacted station's email address) is sent to QRZ.com at https://logbook.qrz.com/api using your Logbook API key. QRZ.com may require a paid subscription for API uploads. See the QRZ.com terms of service and privacy policy.

== Installation ==

1. Upload the `wp-logbook` folder to `/wp-content/plugins/` or install the zip under Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. Enter your station callsign and grid square under Logbook → Settings.
4. Log contacts under Logbook → Log a QSO, or import a log under Logbook → Import / Export. Add more logbooks under Logbook → Logbooks.
5. Optional: to upload to QRZ.com, tick "Upload QSOs to my QRZ.com Logbook", paste the API key from your QRZ.com logbook settings, click "Test connection" and save.

== Frequently Asked Questions ==

= Where do I find my QRZ.com API key? =

On QRZ.com, open your logbook, go to Settings and copy the API key of the logbook you want to upload to. You can also define it in wp-config.php as `WPLB_QRZ_API_KEY`.

= QRZ.com rejects my QSOs with "wrong station_callsign" =

The station callsign of the QSO must match the callsign of the QRZ.com logbook that the API key belongs to. Check the station callsign under Logbook → Settings or on the QSO itself.

= Can I keep separate logs, for example for POTA activations or a club callsign? =

Yes. Add logbooks under Logbook → Logbooks and choose the logbook when you log a QSO or import a file. Show one logbook on a page with `[wp_logbook logbook="slug"]`. A logbook for another callsign can have that callsign's QRZ.com API key, so its QSOs go to the right QRZ.com logbook.

= Are times local or UTC? =

All times are UTC, as in ADIF.

= What happens to my log if I delete the plugin? =

It is kept, unless you tick "Delete all QSOs and settings when the plugin is deleted" in the settings.

== Changelog ==

= 1.2.0 =
* Several logbooks with their own titles: add, edit and delete them under Logbook → Logbooks. Existing QSOs go into a first logbook, "My logbook".
* Each logbook can have its own station callsign, its own QRZ.com API key, and can be kept off QRZ.com.
* Log a QSO: choose the logbook; the form remembers the last one used. Move QSOs between logbooks on the edit screen or with a bulk action.
* Import into any logbook or a new one; ADIF exports name the logbook so a backup goes back into the same logbooks. Export one logbook or all of them.
* Shortcodes: `logbook` attribute, `logbook` visitor filter and column, and `logbook` for `[wp_logbook_stats]`.
* Duplicate checks look within one logbook.

= 1.1.0 =
* New `[wp_logbook_tiles]` shortcode and `layout` attribute: show QSOs as tiles.
* Visitor filters on the public logbook: date range, callsign, band, mode, grid square, state, county, POTA, SOTA, country, continent, WWFF, IOTA, my POTA/SOTA, contest, satellite, station callsign and QSL confirmation. Each can be switched on or off with `filters` and `filter_<name>` attributes, or fixed to a value.
* Pagination options: `pagination`, `page_size`, and `page_sizes` for a visitor page-size menu. Numbered page links.
* The callsign filter replaces the separate "Am I in your log?" form.

= 1.0.0 =
* First release.

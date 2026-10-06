=== ShiftDB ===
Contributors: quberiqtechnologies
Tags: database, search replace, database cleanup, migration, optimization
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Safe WordPress database search, replace, cleanup, recovery, optimization, exports and diagnostics.

== Description ==

Developed by Quberiq Technologies. Support: contact@quberiqtechnologies.com

ShiftDB helps WordPress administrators inspect and maintain their WordPress database without providing arbitrary code execution or file-management functionality.

Version 1.0.1 includes:

* Dry-run search and replace.
* Serialized-data-aware replacement.
* Post GUID protection by default.
* Automatic row-level recovery snapshots before replacement.
* Recovery-point restore.
* Database table size and health information.
* Selective SQL export.
* Table optimization.
* Autoload analyzer.
* Revision, spam comment, trashed comment and transient cleanup.
* Local operation history.
* Capability checks, nonces, sanitization and escaped admin output.

No external service is required and ShiftDB does not transmit database content to a third party.

== Installation ==

1. Upload the `shiftdb` directory to `/wp-content/plugins/` or install the ZIP from Plugins > Add Plugin > Upload Plugin.
2. Activate ShiftDB.
3. Open Tools > ShiftDB.
4. Always run a dry test before a live replacement.

== Frequently Asked Questions ==

= Does ShiftDB edit WordPress files or execute custom code? =

No. ShiftDB is intentionally limited to database maintenance and diagnostics.

= Does a live search and replace create a backup? =

Yes. Version 1.0.1 creates a row-level recovery snapshot before applying database changes.

= Does ShiftDB support serialized WordPress values? =

Yes, common serialized arrays/scalar values are handled through unserialize/serialize processing with class instantiation disabled.

= Does ShiftDB send data to an external server? =

No.

== Changelog ==

= 1.0.1 =
* Improved WordPress Plugin Check compliance and security validation.
* Improved prepared database queries, nonce verification, sanitization and readme metadata.

= 1.0.0 =
* Initial release.
* Added dry-run search and replace.
* Added recovery snapshots and restore.
* Added SQL export and optimization.
* Added autoload diagnostics.
* Added database cleanup tools.
* Added operation history.

# TNTS UI upgrade

This package contains the complete PHP application, uploads, the original schema,
and the supplied current SQL export. No database migrations are required for the UI upgrade.

## Install with XAMPP

1. Back up your current project folder and database.
2. Copy this application's contents into your existing `htdocs/tnts` folder.
3. Keep the database credentials and `BASE_URL` in `config.php` appropriate for your installation. The supplied settings remain unchanged: `/tnts` and database `tnts_inventory`.
4. If you already have the database, keep it. For a fresh installation, import the supplied current SQL export in phpMyAdmin and match `DB_NAME` to your chosen database name.
5. Start Apache and MySQL. Open `http://localhost/tnts/`.
6. Hard refresh the browser (Ctrl+F5) after replacing the CSS and JavaScript files.

## Add your official logo later

Save the approved school logo as `assets/images/school-logo.png`.
Use an actual PNG image, ideally transparent and at least 256 pixels wide.
The sidebar, login page, and printable-document screen headers automatically
replace the TNTS monogram with your image. No PHP edits are needed. A changed
logo receives a modification-time URL to refresh the browser's cached image.
The monogram is a fallback identity mark, not an official school seal.
Original printed form geometry is preserved; the logo toolbar is screen-only.

## Included changes

- Consistent maroon identity, modern cards, forms, buttons, tables, navigation, and login layout.
- All custom CSS, including print styles and dynamic status/chart presentation, in `assets/css/style.css`.
- Shared interactions in `assets/js/ui.js`; existing page-specific business JavaScript remains complete.
- Accessible action dialogs for issue reporting, condemnation, lending, and property transfers.
- Escape, backdrop dismissal, focus restoration, focus containment, responsive dialog scrolling, and reduced-motion support.
- Inventory and maintenance detail dialogs show records already available to the signed-in user; they make no extra database calls.
- Responsive navigation, scrollable tables, form labels, and clearer active-page indication.
- Original database connection settings, role menus, action field names, queries, transactions, and redirect destinations retained.

## Validation and limitations

Completed locally: CSS consolidation audit (no style attributes, style blocks,
or JavaScript style assignments), JavaScript and all 27 PHP files syntax checks, stylesheet-class
reference checks, source comparison of existing PHP controller blocks,
and package integrity checks. PHP database/controller logic is unchanged;
the maintenance active navigation value was corrected as a presentation change.

PHP/MySQL runtime execution and browser rendering could not be completed in this
environment. Before adopting the update, verify the following in your local XAMPP installation:

- Sign in as Supply Officer, TVL Head, and Teacher, and confirm each role's navigation.
- Log a delivery, add items and a receipt, inspect/accept, and issue an ICS.
- Open inventory details, apply filters, and compare quantities with the existing application.
- As a Teacher, open the report dialog, select an item, verify condition/quantity limits, submit an issue, then test a repair restoration.
- Open the borrowing, condemnation, and transfer dialogs; verify their normal submissions and approvals.
- Verify account/course actions, reports, audit history, and logout.
- Print ICS, IAR, condemnation, and course summary forms with browser Print Preview.
- On a phone-size screen, test the menu, table scrolling, and modal scrolling.
- Test Escape, Tab/Shift+Tab, Cancel, and backdrop dismissal in each modal.

The original SQL exports and uploaded receipt image are included unchanged.
Git history is omitted from this runnable distribution.

# Copy-and-paste update: 5 replacement files

This update is for the successful UI-upgrade version already installed on your computer.
It contains only the files needed for the navigation, desktop sidebar, login background,
and improved maintenance/lending forms. There are no database migrations.

## Replace these files

Open each supplied file in VS Code, press Ctrl+A then Ctrl+C, open the matching file in
your installed project, press Ctrl+A then Ctrl+V, and save. Replace all five together.

| Supplied file | Destination in your project |
| --- | --- |
| includes/header.php | includes/header.php |
| assets/css/style.css | assets/css/style.css |
| assets/js/ui.js | assets/js/ui.js |
| item_maintenance.php | item_maintenance.php |
| borrow.php | borrow.php |

These are complete files, not snippets. You can alternatively copy them directly over
the matching project paths. Keep your existing includes/functions.php, config.php,
login.php, database, uploads, and official logo. The new header uses the school_logo()
helper from the successful earlier upgrade.

After saving all five, press Ctrl+F5 in your browser.

## Desktop navigation

- Dashboard remains a direct link.
- Inventory & Stock: Receiving, Inventory, Issue ICS.
- Operations & Tracking: Maintenance, Borrow & Return, Property Transfers, Condemnation.
- Reports & Records: Reports & Docs, History Logs.
- Administration: Courses, Accounts.

Only the links already allowed for the signed-in role are displayed. Empty categories
are omitted. The active page's category opens automatically. Category open/close
choices are remembered per role in this browser.

Use the burger button at the top left of the workspace to switch between a full sidebar
and a compact icon rail. Clicking a category icon in the compact rail expands the
sidebar and opens that category. The sidebar preference is remembered in this browser.
The layout is intended for desktop web use (minimum layout width: 1024px); wide tables
scroll inside the workspace. There is no mobile app or mobile navigation drawer.

## Optional login background image

Save your chosen image as:

    assets/images/login-background.jpg

No changes to login.php are required. The stylesheet applies the image behind the
transparent maroon gradients. Without that image, the gradient background remains.
A landscape photo at least 1600 pixels wide works well.

To use another filename, edit the login background declaration near the end of style.css:

    url('../images/login-background.jpg')

To reveal more of the image, reduce the gradient opacity values .86 and .63 in that
same declaration. Keep all CSS edits in style.css. Hard-refresh after changing the image.

## Improved forms

Maintenance: select equipment, review live quantities, choose Needs repair / Damaged /
Repair completed, enter affected units, and add issue/repair notes. Unavailable actions
are disabled; quantities follow the existing server-side limits. Damaged equipment
still queues the existing condemnation review. Repair completed still restores units
through the existing PHP transaction.

Lending: select equipment, review remaining lending quantity, choose the borrowing
teacher, enter units/date and purpose, and review the loan summary. Excess quantities,
missing borrowers, and empty equipment/teacher lists prevent an invalid submission.
The existing lending availability rule is retained; this UI update does not introduce
a new equipment-condition restriction or change how loans are stored.

## Validation

- PHP syntax parsing passed for all three PHP replacement files.
- JavaScript syntax checks passed for the shared UI script and both form scripts.
- Interaction tests passed for sidebar collapse/persistence and category expansion.
- Form interaction tests passed for quantity limits, repair completion, zero stock,
  excessive/fractional quantities, missing borrowers and empty lists.
- Both existing PHP submission/controller blocks are unchanged.
- No inline CSS or style blocks were introduced.

Browser visual rendering and live MySQL execution were not available here. Verify the
sidebar, both form submissions, and login background in your existing local website.

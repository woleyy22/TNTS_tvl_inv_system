TNTS Inventory System - Stage 1 (login, roles, dashboard, accounts, courses, history logs)

SETUP (XAMPP on Windows)
1. Start Apache and MySQL in the XAMPP Control Panel.
2. Open http://localhost/phpmyadmin, go to the Import tab, and import
   database/tnts_inventory_schema.sql (skip this if you already imported it).
3. Make sure these files are in C:\xampp\htdocs\tnts\ (index.php should be directly inside).
4. Open http://localhost/tnts/setup.php and create the first Supply Officer account.
5. Delete setup.php from the folder, then sign in at http://localhost/tnts/

If your folder is not named "tnts", change BASE_URL in config.php.
If MySQL has a root password, set it in config.php (DB_PASS).

ROLES
supply_officer = admin (accounts, courses, history logs)
tvl_head       = courses, history logs
teacher        = own dashboard only for now

---
sort: 1
---

## Installation

1. Confirm that your server has the following prerequisites installed:
   - Apache 2.4 or higher with mod\_rewrite enabled.
   - PHP 7.4 or higher.
   - MySQL 8.3 or higher.
2. Clone the repository to the desired location on your server:  
   ```
   git clone https://github.com/dschor5/ECHO.git
   ```
3. Create a MySQL database and import the database `delay.sql` from the root folder where the code was cloned.
4. Rename the file `server-example.inc.php` to `server.inc.php` and update the configuration settings in accordance with the comments embedded within the file and matching your server settings.
5. Modify the `.htaccess` file with the proper `RewriteBase` in accordance with the comments in the file.
6. Navigate to your server's URL to see the **ECHO** home page / login screen.
7. Click login and use the username `admin` and password `secret`. You will immediately be asked to change the password.


# libresplit

Open source web app for tracking group expenses and settling up easily.

Hosted version at: https://libresplit.luelistan.net

## Install instructions

Clone this repository to your webserver. Configure a virtual host whose 
DocumentRoot ist the root folder of the repository. At the moment libresplit 
can not be deployed to a subdirectory. Pull requests to change that are 
appreciated.

Create a mysql database and user, then import run the `install.sql` 
commands in that database.

Copy the `htconfig-sample.ini` to `.htconfig.ini` and fill in your database 
connection details.




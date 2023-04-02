Donor Press
===========
This WordPress plugin was designed as way for non-profits to track donations and send out donor receipts.
Integration with Paypal is accomplished through upload of a .csv file or through direct . Future editions will hopefully have direct API access. We also hope to integrate with Quickbooks.
## Prerequisites - at least until this is published as a authorized plugin.
1. Wordpress Installed
2. Shell Command Prompt access
3. GIT Installed
4. Optional: Composer - will give you ability to write .pdf receipts and allows for API access to QuickBooks

# Installation
## Download Plugin
Assuming you have Wordpress already installed, navigate to the following folder using a terminal/command prompt that has the git command configured:
`wp-content\plugins\`

Once inside the folder, run this command:
`git clone https://github.com/DonorPress/donor-press`

Optional: Once successfully cloned, enter this directory and type:
`composer install`
This will install required classes for PDF writer and Quickbook integrations if you woudl like this functionality.

Composer Notes:
You may have install composer if it is not already installed. If you get an error like this:
`Your lock file does not contain a compatible set of packages. Please run composer update.`

To fix this, run:
`sudo apt install php-xml`
This is required by the Quickbooks plugin. If you don't need the Quickbooks plugin, but just want the TCPDF, the composer.json file will need altered.


## Activate Plugin
[ add screenshot here ]

## Configure your site
- set site variables
- edit templates

[ add video link resources]

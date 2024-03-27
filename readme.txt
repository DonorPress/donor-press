=== Donor Press ===
Contributors: steinerd
Tags: nonprofit, donation tracker, donations, donation manager, quickbooks, paypal, donors
Requires at least: 0.1.1
Tested up to: 0.1.1
Stable tag: 0.1.1
Requires PHP: 7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

DonorPress is a free plugin for non-profits to track donations, send thank you letters and year end summaries. Integration with Quickbooks & Paypal.

== Description ==
# Intro
Donor Press was started by Denver Steiner who found himself managing the finances for 3 small non-profits.
Short on both time and funds (like many small non-profits), Steiner needed a free way to automate thank you letter receipts and year end summaries.
- One of the non-profits received 90%+ of its donation through Paypal, so early drafts were centered around Paypal integration.
- The Second Non-profit he was involved with used Quickbooks heavily because it had both payroll and expsense tracking needs. So out of that the Quickbooks integration was build out to log the donation thorugh DonorPress and then write that transaction back into Quickbooks to reconsile with bank Statements.
- The Third nonprofit received both donations, but also invoiced for services. So we are currently building out support for entering other types of income into DonorPress.

If you are non-profit similar to one of those mentioned above, then DonorPress could fill that void for you. 

# Key Features:
**Importing donations from existing system**  We currently support API integration directly with Paypal. We also allow you to upload a custom flat file. Contact the developer if you are interested in integration with other platforms.
**Thank You Generation** - Generate an immediate thank you based on custom templates. Custom templates can be set based on Donation Category and/or Transaction Type. Before sending, teh standard template can be personalized. Thank you's can be E-mailed or exported as a .pdf for printing/mailing.
**Year End Tax Deductible Summaries** - send year end summaries via e-mail or mail. There is also the ability to distinguish between tax deductible gifts and transactions that don't qualify.
**Reports** - Besides being able to see complete Donor giving history, you can also see report trends and regression reports (previous supporters that haven't given in awhile).
**Quickbooks Integration** - Sync donations from DonorPress to Quickbooks. First log the donation in Donor Press, and then sync the donor (customer) and the donations (invoice & payment in QB) to Quickbooks. When the transaction hits your bank account, then you can reconsile by linking it to the Payment synced from DonorPress.

# Pricing
Did we mention this is free!? We hope to be able to bless other small non-profits with an easy way to track their donations.

Our hope is that we will soon have documention build out well enough that you can get started on your end. However if you need help getting integration setup or custom feature or integration added, then contact the plugin author. We may ask for a donation to one of his non-profits in exchange for this help.

# Developer Contact
Denver Steiner denver@steiner7.com

== Optional 3rd Party services & Dependancies ==
Use of optional 3rd party services help extend the functionality of the Donor Press system.
Optional Depenancies include:
# Google Charts 
Google Charts allow you to view different graphs on certain report pages. To turn on this dependancy, go to:
"Settings -> Site Variables" tab and set "GoogleCharts" to "on". (By default it is off)
Turning this on utilizes the following external library: https://www.gstatic.com/charts/loader.js 
Read more about Google Charts here: https://developers.google.com/chart
Terms of Service: https://developers.google.com/chart/terms
Security and Privacy Notice: https://developers.google.com/chart/interactive/docs/security_privacy

# Paypal API
If you receive donations and payments through Paypal, the optional Paypal API settings allow you to sync transactions from Paypal. 
To enable Paypal integration, you must first get an API key from Paypal:
1. Visit:  https://developer.paypal.com/dashboard/applications/live 
2. login into your paypal account 
3. create an app using "live" if you want to use in production
4. Make sure Transaction search is checked and set the redirect to:  https://{{yoursite.url}}/wp-admin/admin.php?redirect=donor_quickBooks_redirectUrl
5. Go to your wordpress website admin page and visit "DonorPress -> Settings -> Site Variables"
    - Copy and paste in credentials into the "PaypalClientId" and "PaypalSecret"
    - For QuickBook base, selected "production" or "development"
By entering a value, it will override what is currently there. Values are encrypted on the database.
Paypal API relies on the following external API: https://api-m.paypal.com/v1/

# Optional Features that require additional libraries
The following functionality is not included with the base DonorPress installation due the size of the libraries, however they are recommended add ons.

To install these libaries you will need shell access, and composer installed.
1. Navigate to the Donor-Press plugin directory located at: [wordpressinstalldirectory]/wp-content/plugins/donor-press
2. run 'composer install'.

The following Libaries are included:

## Quickbooks API
Quickbooks expands functionality by allowing you to sync Donor and donations information to Quickbooks.
To get this setup, you must install the optional libaries (as mentiond above).

Once the libaries have been installed:
Login to to your wordpress Admin interface, and navigate to: "DonorPress -> Settings -> Site Variables"
Here you sould be able to enter api credentials. Obtaining these credentials requires sighning up for api access at: https://www.developer.intuit.com/app/developer/dashboard

Quickbooks Library information: https://github.com/intuit/PHP-Payments-SDK

## PDF Generation
Generating PDFs are helpful when you need to printout and physcially mail a donor receipt or year end receipt. However this functionaly requires external libraries to work.

TCPDF is currently the primary .pdf generations. https://tcpdf.org/ 

However development of TCPDF library has been limited, so we are in the process of switching: DOMpdf https://github.com/dompdf/dompdf 

## Faker
The faker libary allow for randomly generated donor and donations, useful for training, and experminting with DonorPress.
After the libary has been installed, you can utilize this functionality by visiting: "DonorPress -> Settings -> Backup/Restore", and then finding "Load Test Data".


== Installation ==
Once released as a plugin on wordpress, it should be simple to install and activate. However certain optional components such as Quickbooks and PDF generation required additional optional libaries. See the "`composer install`" notes below.

# Installation via command line
## Prerequisites
1. Wordpress Installed
2. Shell Command Prompt access
3. GIT Installed
4. Optional: Composer - will give you ability to write .pdf receipts and allows for API access to QuickBooks

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
== Testing ==
If you would like to run DonorPress with test date, please run

== Frequently Asked Questions ==
= How do I set this up? =

If this plugin starts to take off, I'll develop out better setup tutorials and videos. I would suggest playing with the setup. There is a "backup" and "nuke" function in the setup. So feel free to test it out.

= I'm having trouble uploading a file =
The plugin might not have proper permissions. You may need to created a "upload" folder and give it chmod 777 permissions.

== Screenshots ==

1. This screen shot description corresponds to screenshot-1.(png|jpg|jpeg|gif). Screenshots are stored in the /assets directory.
2. This is the second screen shot

== Changelog ==
= 0.1.1
* Major database and file structure changes to ensure there aren't collisions with other plugs.
= 0.0.5 
* Beta release of the plugin in Worpdress.

== Upgrade Notice ==
= 0.0.5 
Got to start somewhere right?

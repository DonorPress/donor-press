=== Donor Press ===
Contributors: steinerd
Tags: nonprofit, donation tracker, donations, donation manager, quickbooks, paypal, donors
Requires at least: 5.5
Tested up to: 6.1
Stable tag: 5.5
Requires PHP: 7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

DonorPress is a free plugin for non-profits to track donations, send thank you letters and year end summaries. Integration with Quickbooks & Paypal.

== Description ==
## Beta Notice: 
This is a functional plugin, but may take some intial setup to work well, and some things might not be as polished as they could be. If you run into bugs, please report them to the developer.
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

# Pricing
Did we mention this is free!? We hope to be able to bless other small non-profits with an easy way to track their donations.

Our hope is that we will soon have documention build out well enough that you can get started on your end. However if you need help getting integration setup or custom feature or integration added, then contact the plugin author. We may ask for a donation to one of his non-profits in exchange for this help.

For backwards compatibility, if this section is missing, the full length of the short description will be used, and
Markdown parsed.

# Developer Contact
Denver Steiner denver@steiner7.com

== Installation ==
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

== Frequently Asked Questions ==
= How do I set this up? =

If this plugin starts to take off, I'll develop out better setup tutorials and videos. I would suggest playing with the setup. There is a "backup" and "nuke" function in the setup. So feel free to test it out.

== Screenshots ==

1. This screen shot description corresponds to screenshot-1.(png|jpg|jpeg|gif). Screenshots are stored in the /assets directory.
2. This is the second screen shot

== Changelog ==

= 0.0.5 
* Beta release of the plugin in Worpdress.

== Upgrade Notice ==
= 0.0.5 
Got to start somewhere right?
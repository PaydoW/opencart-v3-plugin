Opencart v3 Paydo Payment Gateway
=====================

## Brief Description

Add the ability to accept payments in Opencart v3 via Paydo.com.

## Requirements

-  Opencart 3.0+


## Installation Guide for Paydo in OpenCart 3 (via Admin Panel)

### 1. Download the Latest Version
- Go to the [latest release](https://github.com/PaydoW/opencart-v3-plugin/releases).
- Select the latest version from the list of releases (the one at the top is the most recent).
- Download the `paydo_payment.ocmod.zip` file.

### 2. Install the Module via OpenCart Admin Panel
1. Log in to your **OpenCart admin panel**.
2. Navigate to **Extensions → Installer**.
3. Click the **Upload** button and select the downloaded `paydo_payment.ocmod.zip` file.
4. Wait for the installation to complete.

### 3. Enable and Configure the Module
1. Go to **Extensions → Extensions**.
2. In the dropdown menu, select **Payments**.
3. Find **Paydo Payment Gateway** and click the **Install** button.
4. After installation, click the **Edit** button.
5. Enter your **Public Key** and **Secret Key** (see the section below for details on how to obtain them).
6. In the module settings, the **IPN URL** field will automatically generate a link for processing payments. Copy this link.
7. Go to **Paydo.com → IPN → Add new IPN**, paste the copied link, and save it.

### 4. Refresh Modifications
1. Navigate to **Extensions → Modifications**.
2. Click the **Refresh** button at the top right to apply changes.

---

## How to get Public/Secret Keys and configure IPN in Paydo

### Public/Secret Keys
1. Log in to your account on **Paydo.com**.
2. Go to **Overview → Project (website) details**.
3. Open the **General information** tab.
4. Copy your **Public Key** and **Secret Key** and paste them into the module settings in OpenCart.

### IPN (payment notifications)
1. In your Paydo dashboard go to **IPN settings**.
2. Click **Add new IPN**.
3. Paste the **IPN URL** from the module settings in OpenCart.
4. Save.

> **Note:** Without correct IPN setup your OpenCart store will not automatically receive payment status updates.

---

## Support

* [Open an issue](https://github.com/PaydoW/opencart-v3-plugin/issues) if you are having issues with this plugin.
* [PayDo Documentation](https://github.com/PaydoW/paydo-api-doc)
* [Contact PayDo support](https://paydo.com/contacts-page-customer-support/)
  
**TIP**: When contacting support it will help us if you provide:

* WordPress and WooCommerce Version
* Other plugins you have installed
  * Some plugins do not play nice
* Configuration settings for the plugin (Most merchants take screenshots)
* Any log files that will help
  * Web server error logs
* Screenshots of error message if applicable.

## Contribute

Would you like to help with this project?  Great!  You don't have to be a developer, either.
If you've found a bug or have an idea for an improvement, please open an
[issue](https://github.com/PaydoW/opencart-v3-plugin/issues) and tell us about it.

If you *are* a developer wanting contribute an enhancement, bugfix or other patch to this project,
please fork this repository and submit a pull request detailing your changes.  We review all PRs!

This open source project is released under the [MIT license](http://opensource.org/licenses/MIT)
which means if you would like to use this project's code in your own project you are free to do so.


## License

Please refer to the [LICENSE](https://github.com/PaydoW/opencart-v3-plugin/blob/master/LICENSE) file that came with this project.

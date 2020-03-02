Opencart v3 PayDo Payment Gateway
=====================

## Brief Description

Add the ability to accept payments in Opencart v3 via Paydo.com.

## Requirements

-  Opencart 3.0+


## Installation
 1. Download latest [release](https://github.com/PaydoW/opencart-v3-plugin/releases)
 2. Unzip and upload folder to your site root directory from upload folder.   
 2. Log in to your Opencart admin dashboard, navigate to the Extentions menu and click "Install" button over against Paydo plugin.
 4. After plugin installed, click edit on Paydo plugin.
 5. Configure and save your settings accordingly.

You can issue  **Public key** and **Secret key** after register as merchant on PayDo.com.
Opencart plugin work only with POST IPN request HTTP method.

Use below parameters to configure your PayDo project:
* **Callback/IPN URL**: https://{replace-with-your-domain}/index.php?route=extension/payment/paydo/callback

## Support

* [Open an issue](https://github.com/PaydoW/opencart-v3-plugin/issues) if you are having issues with this plugin.
* [PayDo Documentation](https://paydo.com/en/documentation/common/)
* [Contact PayDo support](https://paydo.com/en/contact-us/)
  
**TIP**: When contacting support it will help us is you provide:

* Opencart Version
* Other plugins you have installed
  * Some plugins do not play nice
* Configuration settings for the plugin (Most merchants take screen grabs)
* Any log files that will help
  * Web server error logs
* Screen grabs of error message if applicable.

## Contribute

Would you like to help with this project?  Great!  You don't have to be a developer, either.
If you've found a bug or have an idea for an improvement, please open an
[issue](https://github.com/PaydoW/opencart-v3-plugin/issues) and tell us about it.

If you *are* a developer wanting contribute an enhancement, bugfix or other patch to this project,
please fork this repository and submit a pull request detailing your changes.  We review all PRs!

This open source project is released under the [MIT license](http://opensource.org/licenses/MIT)
which means if you would like to use this project's code in your own project you are free to do so.


## License

Please refer to the 
[LICENSE](https://github.com/PaydoW/opencart-v3-plugin/blob/master/LICENSE)
file that came with this project.

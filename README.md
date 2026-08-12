# LiqPay for FOSSBilling
[LiqPay](https://www.liqpay.ua) payment gateway module for [FOSSBilling](https://fossbilling.org)

## Installation

### Automated installation
```bash
git clone https://github.com/getnamingo/fossbilling-liqpay-gateway
mv fossbilling-liqpay-gateway/Liqpay /var/www/library/Payment/Adapter/
chown -R www-data:www-data /var/www/library/Payment/Adapter/Liqpay
```

1. In the FOSSBilling admin panel, go to the "Payment gateways" page, which is located under the "System" menu.
2. Click "New Payment Gateway", find Liqpay and click the gear (cog) icon to install.
3. Configure your new payment gateway.

### Manual installation
1. Download the latest release from [GitHub](https://github.com/getnamingo/fossbilling-liqpay).
2. In your FOSSBilling installation, navigate to `/library/Payment/Adapter` and create a new folder named `Liqpay`.
3. Extract the contents of the downloaded archive into the `Liqpay` folder.
4. In the FOSSBilling admin panel, go to the "Payment gateways" page, which is located under the "System" menu.
5. Click "New Payment Gateway", find Liqpay and click the gear (cog) icon to install.
6. Configure your new payment gateway.

## Licensing
This extension is licensed under the Apache 2.0 license. See the [LICENSE](LICENSE) file for more information.

## Disclaimer
This extension is not affiliated with LiqPay in any way.
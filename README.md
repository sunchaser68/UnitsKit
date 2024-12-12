# UnitsKit

[![packagist](https://img.shields.io/packagist/v/deemru/unitskit.svg)](https://packagist.org/packages/deemru/unitskit) [![php-v](https://img.shields.io/packagist/php-v/deemru/unitskit.svg)](https://packagist.org/packages/deemru/unitskit) [![GitHub](https://img.shields.io/github/actions/workflow/status/deemru/UnitsKit/php.yml?label=github%20actions)](https://github.com/deemru/UnitsKit/actions/workflows/php.yml) [![license](https://img.shields.io/packagist/l/deemru/unitskit.svg)](https://packagist.org/packages/deemru/unitskit)

[UnitsKit](https://github.com/deemru/UnitsKit) is an all-in-one Units Network development kit for the PHP language.

- All you need to work with Units Networks in a single class
- Really easy to use
- Best practices for all
- Advanced features for pros

## Installation

```bash
composer require deemru/unitskit
```

## Basic usage

```php
$uk = UnitsKit::TESTNET();
$uk->setPrivateKey( '0x33eb576d927573cff6ae50a9e09fc60b672a8dafdfbe3045c7f62955fc55ccb4' );
$tx = $uk->tx( $uk->getAddress(), $uk->hexValue( 1.1 ), $uk->getGasPrice(), $uk->getNonce() );
$tx = $uk->txEstimateGas( $tx );
$tx = $uk->txSign( $tx );
$tx = $uk->txBroadcast( $tx );
$tx = $uk->ensure( $tx );
```

## Documentation

- Consider to learn self tests: [selftest.php](https://github.com/deemru/UnitsKit/blob/master/test/selftest.php)

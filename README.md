retailCRM Public Client
=======================

This class, allows to perform a form that is only available from the public part of the browser.

## Requirements
* PHP version >5.3
* DomDocument
* DomXpath

## Example of work
```php
require 'RetailcrmPublicClient.php';

$client = new RetailcrmPublicClient('demo', 'admin', 'pass');
$client->importIcml(3);
$client->clearCatalog(3);
```

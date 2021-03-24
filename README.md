TradeMaster для WebSpace Engine
====
####(Плагин)

Плагин реализует функционал интеграции с 1С посредством протокола Commerce ML 3.

#### Установка
Поместить в папку `plugin` и подключить в `installed.php` добавив строку:
```php
// cml plugin
$plugins->register(new \Plugin\CommerceML\CommerceMLPlugin($container));
```

#### License
Licensed under the MIT license. See [License File](LICENSE.md) for more information.

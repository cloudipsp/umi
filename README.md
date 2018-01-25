# Платежный модуль Fondy для UMI CMS 
================================

## Файлы
-----

1. Скопировать файл `fondy_installer.php` в корень сайта и запустить его. `http://{адрес сайта}/fondy_installer.php` Запускать скрипт можно только один раз! после удалить его. 
2. Скопировать файл `fondy.php` в папку `/classes/components/emarket/classes/payment/systems` - для UMI версии 16, 
   `/classes/modules/emarket/classes/payment/systems/` - возможно понадобится для предыдущих версий
3. Файл `default.tpl` в папку `/tpls/emarket/payment/fondy/` (создать директорию)
4. Cодержимое файла `payment.xsl` добавить в файл
   `/templates/имя_шаблона/xslt/modules/emarket/purchase/payment.xsl` - после любого платежного средства.
5. Скопирофать файл `fondy.phtml` в папку `/templates/имя_аблона/php/emarket/payment/`

## В панеле администратора
-----------------

1. Перейти в модуль "Шаблоны данных". В списке "Способы оплаты", тип данных "fondy". Нужно добавить следующие поля данных (у всех отмечается флажок - Видимое):

| Идентификатор | Подсказка | Тип |
| --- | --- | --- |
| **merchant id** | Идентификатор Мерчанта | Число - Обязательное |
| **secret_key** | Секретный ключ | Строка - Обязательное |
| **response_url** | Страница, куда попадает пользователь после успешной оплаты, по умолчанию стандартная магазина | Строка |
| **lifetime** | Время жизни заказа (https://docs.fondy.eu/docs/page/3/) | Число - Обязательное |
| **language** | Язык платежной страницы | Строка  |

## Настройка оплаты в интернет-магазине. 
3. Перейдите в модуль Интернет-магазин, на вкладке Оплата наведите курсор на кнопку Добавить способ оплаты и в выпадающем списке выберите "Fondy". 
3. Укажите название способа оплаты (например) fondy и заполните необходимые поля, нажмите кнопку Добавить.


![Скриншот][1]
![Скриншот][2]
----

[1]: https://raw.githubusercontent.com/cloudipsp/umi/master/Screenshot_1.png
[2]: https://raw.githubusercontent.com/cloudipsp/umi/master/Screenshot_2.png
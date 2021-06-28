Шаг 1 Развернуть сервис и инициализировать приложение
(При необходимости инициализацию приложения можно выполнить отдельно см. шаг 2)
````
1) git clone https://github.com/sgonchar67-git/fsin-shop-parser.git
2) cd docker
3) docker-compose up -d --build
````
Шаг 2 Инициализировать приложение 
(установка пакетов и выполнение миграций):
````
1) cd docker
2) docker-compose exec php bash
3) make app-init
````
Шаг 3 Запустить крон:
````
docker-compose exec php bash
cd cron
php parse.php
````
Шаг 4 Экспортировать данные 
Все данные собраны в представлении view_product
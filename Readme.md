Развернуть сервис
````
1) git clone https://github.com/sgonchar67-git/fsin-shop-parser.git
2) cd docker
3) docker-compose up -d --build
````
Запустить крон:

````
docker-compose exec php bash
cd cron
php parse.php
````
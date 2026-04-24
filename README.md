# product

Это сервис товаров.

Он умеет:
- создать товар
- отдать список товаров
- отдать один товар
- отправить событие про товар в RabbitMQ
- принять событие про заказ и уменьшить остаток

Важно:
- тут есть токен `product-dev-token`
- токен, env, DSN, пароли и всё такое закоммичены только потому, что это тестовое задание и нужно было быстро всё поднять
- для нормального проекта так делать нельзя

## Как проще всего запускать

удобнее запускать всё из корня через `evotym_general`.
Тогда сразу будут и `order`, и `rabbitmq`, и mysql.

Если именно отдельно:

```bash
cd product
docker compose up -d --build
```

Но для полного сценария нужен ещё живой RabbitMQ.
Поэтому я обычно запускал не отдельно, а из корня.

## HTTP

Health:

```bash
curl -fsS http://127.0.0.1:8081/health.php
```

Список:

```bash
curl -sS \
  -H 'Authorization: Bearer product-dev-token' \
  http://127.0.0.1:8081/products
```

Создание:

```bash
curl -sS \
  -X POST http://127.0.0.1:8081/products \
  -H 'Authorization: Bearer product-dev-token' \
  -H 'Content-Type: application/json' \
  -d '{
    "name": "Coffee Mug",
    "price": 12.99,
    "quantity": 10
  }'
```

Один товар:

```bash
curl -sS \
  -H 'Authorization: Bearer product-dev-token' \
  http://127.0.0.1:8081/products/PUT_UUID_HERE
```

## Тесты

Из контейнера:

```bash
cd /workspace/product && vendor/bin/phpunit -c phpunit.dist.xml
```

С хоста, если сервис поднят через корневой compose:

```bash
docker compose -f ../docker-compose.yaml exec -T product-app vendor/bin/phpunit -c phpunit.dist.xml
```

## Что внутри

- Symfony 6.4
- MySQL
- RabbitMQ publisher/outbox
- consumer для `order.created`
- inbox для входящих событий
- bearer token auth только на `/products`

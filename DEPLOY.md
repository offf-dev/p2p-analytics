# Deployment — Hetzner VPS + CityHost MySQL

**Архитектура:** PHP+Apache в Docker на Hetzner (`135.181.254.225:8086`) → MySQL на CityHost (`venza.cityhost.com.ua:3306`, БД `ch65d751c9_p2panalytics`).

**Публичный доступ:** `http://135.181.254.225:8086`. Без домена, без SSL. Защищён паролем (bcrypt + session).

---

## Разовая настройка на сервере

### 1. Склонировать репо под юзером `deploy`

```bash
ssh root@135.181.254.225 'sudo -u deploy bash -c "cd /var/www && git clone git@github.com-p2panalytics:offf-dev/p2p-analytics.git p2p-analytics"'
```

Если deploy key для репа ещё не настроен — см. раздел **Deploy key** ниже.

### 2. Создать `.env.production`

```bash
ssh root@135.181.254.225 'sudo -u deploy bash -c "cd /var/www/p2p-analytics && cp .env.production.example .env.production && chmod 600 .env.production"'
```

Отредактировать значения:
- `DB_PASS` — реальный пароль CityHost-юзера
- `APP_PASSWORD_HASH` — bcrypt хэш пароля (см. ниже)

### 3. Сгенерировать bcrypt хэш

На локальной машине или на сервере:
```bash
docker run --rm php:8.2-cli php -r "echo password_hash('enenkoff', PASSWORD_BCRYPT).PHP_EOL;"
```

Результат вида `$2y$10$...` — вписать в `APP_PASSWORD_HASH` в `.env.production`.

### 4. Открыть порт в firewall (если включён)

```bash
ssh root@135.181.254.225 'ufw status'                     # проверить
ssh root@135.181.254.225 'ufw allow 8086/tcp'             # открыть, если нужно
```

### 5. Поднять контейнер

```bash
ssh root@135.181.254.225 'sudo -u deploy bash -c "cd /var/www/p2p-analytics && docker compose -f docker-compose.prod.yml --env-file .env.production up -d --build"'
```

### 6. Проверка

```bash
curl -s http://135.181.254.225:8086/api/me.php
# ожидаем: {"auth_required":true,"authenticated":false}
```

Открыть в браузере `http://135.181.254.225:8086` — должен редиректнуть на `/login.html`.

---

## Обновление (регулярное)

```bash
ssh root@135.181.254.225 'sudo -u deploy bash -c "cd /var/www/p2p-analytics && git pull && docker compose -f docker-compose.prod.yml --env-file .env.production up -d --build"'
```

Без `--build` — только когда правишь только файлы в `public/` (они монтируются в образ при билде, поэтому пересборка нужна).

---

## Deploy key (разово, чтобы `git pull` работал)

### На сервере:

```bash
ssh root@135.181.254.225 'sudo -u deploy bash -c "ssh-keygen -t ed25519 -C p2panalytics -f ~/.ssh/p2panalytics_deploy -N \"\" && cat ~/.ssh/p2panalytics_deploy.pub"'
```

Скопировать публичный ключ. Добавить на GitHub:
- `https://github.com/offf-dev/p2p-analytics/settings/keys` → **Add deploy key** → вставить ключ, галку «Allow write access» **НЕ** ставить.

На сервере добавить alias в `~deploy/.ssh/config`:
```
Host github.com-p2panalytics
  Hostname github.com
  User git
  IdentityFile ~/.ssh/p2panalytics_deploy
  IdentitiesOnly yes
```

```bash
ssh root@135.181.254.225 'sudo -u deploy bash -c "cat >> ~/.ssh/config <<EOF

Host github.com-p2panalytics
  Hostname github.com
  User git
  IdentityFile ~/.ssh/p2panalytics_deploy
  IdentitiesOnly yes
EOF"'
```

Проверить:
```bash
ssh root@135.181.254.225 'sudo -u deploy ssh -T git@github.com-p2panalytics'
# ожидаем: Hi offf-dev/p2p-analytics! You've successfully authenticated, but GitHub does not provide shell access.
```

---

## База данных (CityHost)

Все данные живут на CityHost, Hetzner stateless. Бэкап через CityHost-панель («Завантажити» рядом с БД) или командой:

```bash
docker run --rm mysql:8.0 mysqldump \
  -h venza.cityhost.com.ua \
  -u ch65d751c9_p2puser \
  -p'PASSWORD' \
  ch65d751c9_p2panalytics > dump_$(date +%Y%m%d).sql
```

Восстановление:
```bash
docker run --rm -i mysql:8.0 mysql \
  -h venza.cityhost.com.ua \
  -u ch65d751c9_p2puser \
  -p'PASSWORD' \
  ch65d751c9_p2panalytics < dump.sql
```

---

## Диагностика

**Приложение не отвечает:**
```bash
ssh root@135.181.254.225 'docker ps --filter name=p2p'
ssh root@135.181.254.225 'cd /var/www/p2p-analytics && docker compose -f docker-compose.prod.yml logs --tail=100 web'
```

**Проверка коннекта к CityHost с сервера:**
```bash
ssh root@135.181.254.225 'docker run --rm mysql:8.0 mysql -h venza.cityhost.com.ua -u ch65d751c9_p2puser -p"PASSWORD" ch65d751c9_p2panalytics -e "SELECT COUNT(*) FROM deals;"'
```

**Не пускает по IP с Hetzner — проверить whitelist:** в CityHost-панели «Віддалений доступ до MySQL» должен быть добавлен `135.181.254.225`.
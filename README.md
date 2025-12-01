# Проект 12na

WordPress тема для сайта 12 шагов.

## Структура проекта

```
12na/
├── themes/
│   └── na/
│       └── na/          # WordPress тема
│           ├── style.css
│           ├── functions.php
│           ├── header.php
│           ├── footer.php
│           └── ...
└── .vscode/
    └── sftp.json        # Настройки FTP (не коммитится)
```

## Технологии

- WordPress
- PHP
- SASS/SCSS
- JavaScript

## Установка

1. Установите зависимости:
```bash
cd themes/na/na
npm install
composer install
```

2. Для разработки используйте:
```bash
npm run watch          # Отслеживание изменений SASS
npm run compile:css    # Компиляция CSS
```

## Подключение к GitHub

### Если Git не установлен:

1. Скачайте и установите Git с [официального сайта](https://git-scm.com/download/win)
2. Перезапустите терминал после установки

### Инициализация репозитория:

```bash
# Инициализация Git репозитория
git init

# Добавление всех файлов
git add .

# Первый коммит
git commit -m "Initial commit"

# Добавление удаленного репозитория (замените на ваш URL)
git remote add origin https://github.com/ваш-username/12na.git

# Отправка на GitHub
git branch -M main
git push -u origin main
```

### Создание репозитория на GitHub:

1. Перейдите на [GitHub.com](https://github.com)
2. Нажмите "New repository"
3. Назовите репозиторий (например, `12na`)
4. НЕ добавляйте README, .gitignore или лицензию (они уже есть)
5. Скопируйте URL репозитория
6. Используйте команды выше для подключения

## Разработка

Тема основана на [Underscores](https://underscores.me/) - стартовой теме для WordPress.

## Лицензия

GPL-2.0-or-later


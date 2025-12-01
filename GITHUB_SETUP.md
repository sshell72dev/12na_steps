# Инструкция по подключению к GitHub

## Шаг 1: Установка Git

Git не найден в вашей системе. Необходимо его установить:

1. Скачайте Git для Windows: https://git-scm.com/download/win
2. Запустите установщик и следуйте инструкциям (можно оставить настройки по умолчанию)
3. **ВАЖНО**: После установки перезапустите VS Code или терминал

## Шаг 2: Проверка установки

Откройте новый терминал в VS Code и выполните:
```bash
git --version
```

Должна отобразиться версия Git (например, `git version 2.40.0`)

## Шаг 3: Настройка Git (первый раз)

Если вы используете Git впервые, настройте имя и email:

```bash
git config --global user.name "Ваше Имя"
git config --global user.email "ваш.email@example.com"
```

## Шаг 4: Создание репозитория на GitHub

1. Войдите в свой аккаунт на [GitHub.com](https://github.com)
2. Нажмите кнопку **"+"** в правом верхнем углу → **"New repository"**
3. Заполните:
   - **Repository name**: `12na` (или другое имя)
   - **Description**: "WordPress тема для сайта 12 шагов"
   - **Visibility**: Выберите Public или Private
   - **НЕ** ставьте галочки на "Add a README file", "Add .gitignore", "Choose a license" (они уже есть в проекте)
4. Нажмите **"Create repository"**

## Шаг 5: Инициализация локального репозитория

В терминале VS Code выполните следующие команды:

```bash
# Перейдите в корень проекта (если еще не там)
cd D:\sites\12na

# Инициализируйте Git репозиторий
git init

# Добавьте все файлы (кроме тех, что в .gitignore)
git add .

# Создайте первый коммит
git commit -m "Initial commit: WordPress тема 12na"

# Добавьте удаленный репозиторий (замените YOUR_USERNAME на ваш GitHub username)
git remote add origin https://github.com/YOUR_USERNAME/12na.git

# Переименуйте ветку в main (если нужно)
git branch -M main

# Отправьте код на GitHub
git push -u origin main
```

## Шаг 6: Авторизация на GitHub

При первом `git push` GitHub может запросить авторизацию:

### Вариант 1: Personal Access Token (рекомендуется)
1. Перейдите: https://github.com/settings/tokens
2. Нажмите **"Generate new token"** → **"Generate new token (classic)"**
3. Дайте токену имя (например, "12na project")
4. Выберите срок действия (например, "No expiration")
5. Отметьте права: **repo** (все подпункты)
6. Нажмите **"Generate token"**
7. **Скопируйте токен** (он показывается только один раз!)
8. При запросе пароля в терминале введите этот токен вместо пароля

### Вариант 2: GitHub CLI
```bash
# Установите GitHub CLI
winget install GitHub.cli

# Авторизуйтесь
gh auth login
```

## Полезные команды Git

```bash
# Проверить статус
git status

# Добавить изменения
git add .
git add имя_файла

# Создать коммит
git commit -m "Описание изменений"

# Отправить на GitHub
git push

# Получить изменения с GitHub
git pull

# Посмотреть историю
git log

# Создать новую ветку
git checkout -b название-ветки
```

## Решение проблем

### Ошибка: "remote origin already exists"
```bash
git remote remove origin
git remote add origin https://github.com/YOUR_USERNAME/12na.git
```

### Ошибка: "failed to push some refs"
```bash
git pull origin main --allow-unrelated-histories
git push -u origin main
```

### Забыли добавить файл в .gitignore
```bash
# Удалить файл из индекса, но оставить локально
git rm --cached путь/к/файлу
git commit -m "Remove file from git"
```

## Безопасность

⚠️ **Важно**: Файл `.vscode/sftp.json` содержит пароли и уже добавлен в `.gitignore`. 
Никогда не коммитьте файлы с паролями и секретными данными!


# Installation

Follow these steps to get your Laravel project up and running locally.

## Prerequisites

- PHP >= 8.2
- Composer
- MySql Database

## Steps

1. **Clone the repository**

```bash
git clone https://github.com/your-username/your-laravel-project.git
cd your-laravel-project
```

2. **Install PHP dependencies**

```bash
composer install
```

3. **Copy the environment file**

```bash
cp .env.example .env
```

4. **Generate application key & Config**
   ```bash
   php artisan key:generate
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```
5. **Configure your .env file**
```env
APP_NAME=Laravel
APP_ENV=local
APP_KEY=base64:...
APP_DEBUG=true
APP_URL=http://localhost

LOG_CHANNEL=stack

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password

BROADCAST_DRIVER=log
CACHE_DRIVER=file
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
SESSION_LIFETIME=120
```
6. **Generate JWT SECRET**
   ```bash
   php artisan jwt:secret
   ```
7. **Run database migrations (OPTIONAL)**
   ```bash
   php artisan migrate

8. **Run the development server**
   ```bash
   php artisan serve

## Create views
You may create database view manualy. You can found the create view sql in  **database/views/**

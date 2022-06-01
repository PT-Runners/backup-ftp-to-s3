# backup-ftp-to-s3

## Tool to backup ftp servers to s3.

### Requirements:

- PHP 7.4

### Installation:

- composer install
- cp config/ftp.php.example config/ftp.php #setup ftp.php
- cp .env.example .env #setup env (SENTRY_DSN not required)
- php upload-s3.php

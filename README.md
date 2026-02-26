# cPanel Full Backup to S3-Compatible Storage

Automated PHP script that triggers a cPanel full backup and uploads it to any S3-compatible object storage service (AWS S3, Cloudflare R2, MinIO, DigitalOcean Spaces, etc.). Designed to run as a cron job for hands-free, scheduled backups.

## Features

- **cPanel UAPI Integration** — triggers full backups via cPanel's API using token-based authentication
- **S3-Compatible Upload** — uses AWS Signature v4, works with any S3-compatible provider
- **Automatic Cleanup** — deletes the local backup file after a successful upload
- **Email Notifications** — sends success/failure alerts via `mail()`
- **Smart Polling** — monitors the home directory for the backup file and waits for it to finish writing before uploading

## Requirements

- PHP 7.4+ with `curl` and `hash` extensions
- A cPanel account with API token access
- An S3-compatible storage bucket
- `mail()` configured on the server (for notifications)

## Configuration

Edit the constants at the top of `cpanel_backup_s3.php`:

### cPanel Settings

| Constant           | Description                              |
| ------------------ | ---------------------------------------- |
| `CPANEL_HOST`      | cPanel server hostname                   |
| `CPANEL_PORT`      | cPanel port (default `2083`)             |
| `CPANEL_USER`      | cPanel username                          |
| `CPANEL_API_TOKEN` | cPanel API token (see below)             |

### S3 / Object Storage Settings

| Constant         | Description                                            |
| ---------------- | ------------------------------------------------------ |
| `S3_ENDPOINT`    | Storage endpoint URL                                   |
| `S3_REGION`      | Region (e.g. `us-east-1`, `auto` for R2)               |
| `S3_BUCKET`      | Bucket name                                            |
| `S3_ACCESS_KEY`  | Access key ID                                          |
| `S3_SECRET_KEY`  | Secret access key                                      |
| `S3_PATH_PREFIX` | Optional folder prefix inside the bucket (e.g. `backups/`) |

### Notification

| Constant       | Description                     |
| -------------- | ------------------------------- |
| `NOTIFY_EMAIL` | Email address for notifications |

## Generating a cPanel API Token

1. Log in to cPanel
2. Navigate to **Security → Manage API Tokens**
3. Create a new token and copy the value
4. Paste it into the `CPANEL_API_TOKEN` constant

## Usage

### Manual Run

```bash
php cpanel_backup_s3.php
```

### Cron Job (Recommended)

Add to your crontab to run automatically. Example — every day at 2:00 AM:

```bash
crontab -e
```

```cron
0 2 * * * /usr/bin/php /path/to/cpanel_backup_s3.php >> /var/log/cpanel_backup.log 2>&1
```

## How It Works

1. **Trigger** — calls the cPanel UAPI (`Backup/fullbackup_to_homedir`) to start a full backup
2. **Poll** — monitors the home directory for a new `backup-*.tar.gz` file (up to 2 min wait)
3. **Wait** — once found, watches the file size until it stabilizes (file fully written)
4. **Upload** — signs and uploads the file to S3 using AWS Signature v4 (PUT request)
5. **Cleanup** — deletes the local backup file on successful upload
6. **Notify** — sends an email with the result (success or failure)

## License

MIT

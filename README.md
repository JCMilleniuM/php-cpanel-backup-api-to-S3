# cPanel Full Backup to S3-Compatible Storage

Automated PHP script that triggers a cPanel full backup and uploads it to any S3-compatible object storage service (AWS S3, Cloudflare R2, MinIO, DigitalOcean Spaces, etc.). Designed to run as a cron job for hands-free, scheduled backups.

## Features

- **cPanel UAPI Integration** — triggers full backups via cPanel's API using token-based authentication
- **S3-Compatible Upload** — uses AWS Signature v4, works with any S3-compatible provider
- **Automatic Cleanup** — deletes the local backup file after a successful upload
- **Email Notifications** — sends detailed success/failure alerts including run time, backup size, and folder total; emails are sent from `noreply@<your-host>` with the display name `Backup to S3 - <your-host>`
- **Smart Polling** — monitors the home directory for the backup file and waits for it to finish writing before uploading
- **Run Time Reporting** — tracks and reports total script execution time in every notification email
- **Backup Size Reporting** — includes the uploaded file size and the real total size of the entire S3 backup folder in the notification email
- **Backup Retention Management** — automatically deletes the oldest backup(s) from S3 when the configured limit is exceeded; any deleted files are listed in the notification email

## Requirements

- PHP 8.1+ with `curl`, `hash`, and `simplexml` extensions
- A cPanel account with API token access
- An S3-compatible storage bucket
- `mail()` configured on the server (for notifications)

## Configuration

Edit the constants at the top of `cpanel_backup_s3.php`:

### cPanel Settings

| Constant           | Description                              |
| ------------------ | ---------------------------------------- |
| `CPANEL_HOST`      | cPanel server hostname (e.g. `4host.ca`) |
| `CPANEL_PORT`      | cPanel port (default `2083`)             |
| `CPANEL_USER`      | cPanel username                          |
| `CPANEL_API_TOKEN` | cPanel API token (see below)             |

### S3 / Object Storage Settings

| Constant         | Description                                                         |
| ---------------- | ------------------------------------------------------------------- |
| `S3_ENDPOINT`    | Storage endpoint URL                                                |
| `S3_REGION`      | Region (e.g. `us-east-1`, `auto` for Cloudflare R2)                |
| `S3_BUCKET`      | Bucket name                                                         |
| `S3_ACCESS_KEY`  | Access key ID                                                       |
| `S3_SECRET_KEY`  | Secret access key                                                   |
| `S3_PATH_PREFIX` | Optional folder prefix inside the bucket (e.g. `backups/`)         |

### Notification & Retention

| Constant       | Description                                                                 |
| -------------- | --------------------------------------------------------------------------- |
| `NOTIFY_EMAIL` | Email address for notifications                                             |
| `MAX_BACKUPS`  | Max number of backups to keep in the S3 prefix (set to `0` to keep all)    |

> **Note:** Notification emails are automatically sent from `noreply@<CPANEL_HOST>` with the sender name `Backup to S3 - <CPANEL_HOST>`, so they appear clearly identified in your inbox.

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
6. **Retention** — lists all backups in the S3 prefix; if the count exceeds `MAX_BACKUPS`, the oldest are deleted
7. **Folder Size** — queries S3 to calculate and report the real total size of all backups in the prefix
8. **Notify** — sends an email with the full result summary (see below)

## Notification Email Format

### Success

```
From: Backup to S3 - 4host.ca <noreply@4host.ca>

Backup completed successfully.

Host          : 4host.ca
Bucket        : my-backup-bucket
File          : backup-2026-02-26_02-00-01.tar.gz
Size on S3    : 3.47 GB
Folder total  : 14.21 GB (4 backup(s))
Duration      : 4m 12s
Completed at  : 2026-02-26 07:12:13 EST

Retention limit (5 max) reached. Removed old backup(s):
  - backup-2025-12-01_02-00-01.tar.gz
```

### Failure

```
From: Backup to S3 - 4host.ca <noreply@4host.ca>

Backup upload to S3 failed.

Host    : 4host.ca
Bucket  : my-backup-bucket
File    : backup-2026-02-26_02-00-01.tar.gz
Duration: 1m 03s

Please check the server logs for details.
```

## Deployment

This script is deployed directly to the cPanel server via SSH. The `.github/` directory contains deployment workflows and configuration secrets and is **excluded from the repository** — it is managed locally only and never pushed to GitHub.

## License

GNU General Public License v3.0 (GPL-3.0)

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT
ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with
this program. If not, see <https://www.gnu.org/licenses/>.

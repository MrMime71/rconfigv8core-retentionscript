a little something that makes free version of rconfig v8 core, not grow over its boundaries regarding disk.
this is the docker version:
# rConfig 90-Day Configuration Retention

Custom configuration retention for rConfig V8 Core running in Docker.

This setup provides:

- 90-day configuration retention
- Dry-run mode by default
- Weekly automatic purge
- Protection of `latest_version = 1`
- Protection of the newest configuration for each device + command
- Protection of physical configuration files still referenced by another database record
- Persistent script across Docker container upgrades/recreation
- Logging of automatic retention runs

> **Important**
>
> Replace **`<username>`** with the Linux username running the rConfig Docker installation.

---

## Directory Structure

The completed installation will look like:

```text
/home/<username>/rconfig8coredocker/
├── docker-compose.yml
├── .env
├── rconfig-retention.php
├── run-retention.sh
└── logs/
    └── retention.log
```

The PHP retention script remains on the Docker host and is mounted read-only into the rConfig application container.

---

# 1. Make the Retention Script Persistent

The retention script should exist here:

```text
/home/<username>/rconfig8coredocker/rconfig-retention.php
```

Go to the rConfig Docker directory:

```bash
cd ~/rconfig8coredocker
```

Edit:

```bash
nano docker-compose.yml
```

Find the `app:` service and its existing `volumes:` section.

It should contain something similar to:

```yaml
volumes:
  - storage_data:/var/www/html/rconfig/storage
  - ./.env:/var/www/html/rconfig/.env
```

Add the retention script as a read-only bind mount:

```yaml
  - ./rconfig-retention.php:/var/www/html/rconfig/rconfig-retention.php:ro
```

The complete section should therefore look like:

```yaml
volumes:
  - storage_data:/var/www/html/rconfig/storage
  - ./.env:/var/www/html/rconfig/.env
  - ./rconfig-retention.php:/var/www/html/rconfig/rconfig-retention.php:ro
```

Save the file:

```text
Ctrl+O
Enter
Ctrl+X
```

## Validate Docker Compose

Before recreating anything:

```bash
docker compose config --quiet
```

No output means the Compose configuration validated successfully.

Recreate the rConfig application container:

```bash
docker compose up -d app
```

## Verify the Bind Mount

Run:

```bash
docker inspect rconfig_app \
  --format '{{range .Mounts}}{{println .Source "->" .Destination}}{{end}}'
```

The output should include:

```text
/home/<username>/rconfig8coredocker/rconfig-retention.php -> /var/www/html/rconfig/rconfig-retention.php
```

The retention script is now stored on the host and mounted into `rconfig_app`.

You should no longer need to run:

```bash
docker cp rconfig-retention.php rconfig_app:/var/www/html/rconfig/
```

after recreating or upgrading the rConfig container.

---

# 2. Test the Persistent Retention Script

First check the PHP syntax:

```bash
cd ~/rconfig8coredocker

docker compose exec app \
  php -l /var/www/html/rconfig/rconfig-retention.php
```

Expected:

```text
No syntax errors detected in /var/www/html/rconfig/rconfig-retention.php
```

## Perform a Dry Run

Run:

```bash
docker compose exec app \
  php /var/www/html/rconfig/rconfig-retention.php
```

The script should show something similar to:

```text
rConfig configuration retention
================================
Mode: DRY RUN
Retention: 90 days
Cut-off: YYYY-MM-DD HH:MM:SS
Date column: created_at
Grouping: device_id, command
Path column: config_location
Latest-version protection: enabled

Old records found: 0
Protected old records: 0
Database records selected: 0
Verified files selected: 0
Files retained due to surviving DB references: 0
Unknown/missing file paths: 0

DRY RUN ONLY: No database records or files were deleted.
```

The important line is:

```text
Mode: DRY RUN
```

Running:

```bash
php /var/www/html/rconfig/rconfig-retention.php
```

without `--apply` does not perform the purge.

---

# 3. Create the Weekly Retention Wrapper

The PHP script uses dry-run mode by default.

For the scheduled weekly purge, create a wrapper that explicitly supplies `--apply`.

Run:

```bash
cat > ~/rconfig8coredocker/run-retention.sh <<'EOF'
#!/bin/bash

cd /home/<username>/rconfig8coredocker || exit 1

/usr/bin/docker compose exec -T app \
  php /var/www/html/rconfig/rconfig-retention.php --apply

EXITCODE=$?

echo "Exit code: $EXITCODE"
exit $EXITCODE
EOF
```

> Remember to replace `<username>` with the actual Linux username.

Make the wrapper executable:

```bash
chmod 750 ~/rconfig8coredocker/run-retention.sh
```

Check it:

```bash
cat ~/rconfig8coredocker/run-retention.sh
```

It should contain:

```bash
#!/bin/bash

cd /home/<username>/rconfig8coredocker || exit 1

/usr/bin/docker compose exec -T app \
  php /var/www/html/rconfig/rconfig-retention.php --apply

EXITCODE=$?

echo "Exit code: $EXITCODE"
exit $EXITCODE
```

---

# 4. Test Without Performing a Purge

Do not test `run-retention.sh` immediately because it contains:

```text
--apply
```

First verify the Docker executable:

```bash
which docker
```

Expected:

```text
/usr/bin/docker
```

Then manually run the same Docker command without `--apply`:

```bash
cd ~/rconfig8coredocker

/usr/bin/docker compose exec -T app \
  php /var/www/html/rconfig/rconfig-retention.php
```

Verify:

```text
Mode: DRY RUN
Retention: 90 days
```

and:

```text
Latest-version protection: enabled
```

The command should finish with:

```text
DRY RUN ONLY: No database records or files were deleted.
```

---

# 5. Configure Weekly Purge and Logging

Create a directory for retention logs:

```bash
mkdir -p ~/rconfig8coredocker/logs
```

Open the user's crontab:

```bash
crontab -e
```

Add:

```cron
15 3 * * 0 /home/<username>/rconfig8coredocker/run-retention.sh >> /home/<username>/rconfig8coredocker/logs/retention.log 2>&1
```

> Replace `<username>` with the actual Linux username.

This schedules the retention job:

```text
Sunday at 03:15
```

The scheduled job invokes:

```text
rconfig-retention.php --apply
```

and records its output in:

```text
/home/<username>/rconfig8coredocker/logs/retention.log
```

## Verify the Cron Job

Run:

```bash
crontab -l
```

The entry should appear as:

```cron
15 3 * * 0 /home/<username>/rconfig8coredocker/run-retention.sh >> /home/<username>/rconfig8coredocker/logs/retention.log 2>&1
```

---

# Viewing Retention Logs

View the complete log:

```bash
cat ~/rconfig8coredocker/logs/retention.log
```

View the most recent 100 lines:

```bash
tail -100 ~/rconfig8coredocker/logs/retention.log
```

Follow the log:

```bash
tail -f ~/rconfig8coredocker/logs/retention.log
```

---

# Manual Dry Run

A safe dry run can be performed at any time:

```bash
cd ~/rconfig8coredocker

docker compose exec app \
  php /var/www/html/rconfig/rconfig-retention.php
```

Look for:

```text
Mode: DRY RUN
```

No database records or configuration files are deleted in dry-run mode.

---

# Manual Purge

To manually execute the same retention job used by cron:

```bash
cd ~/rconfig8coredocker

./run-retention.sh
```

Alternatively:

```bash
docker compose exec -T app \
  php /var/www/html/rconfig/rconfig-retention.php --apply
```

> **Warning:** `--apply` performs the actual retention operation.

---

# Retention Logic

The retention period is:

```text
90 days
```

The script evaluates configurations using the following logic:

```text
Configuration older than 90 days?
        |
        +---- NO ----> KEEP
        |
       YES
        |
        v
latest_version = 1?
        |
        +---- YES ---> KEEP
        |
        NO
        |
        v
Newest config for device + command?
        |
        +---- YES ---> KEEP
        |
        NO
        |
        v
Eligible DB record
        |
        v
Is the physical file referenced
by another surviving DB record?
        |
     +--+--+
     |     |
    YES    NO
     |     |
     v     v
    KEEP  DELETE
    FILE   FILE
```

This provides two protections for the current configuration:

1. Records marked:

```text
latest_version = 1
```

are retained.

2. The newest database record for each:

```text
device_id + command
```

combination is retained.

A physical configuration file is only selected for deletion when no surviving database record references the same configuration location.

---

# Docker Persistence

The source retention script resides on the Docker host:

```text
/home/<username>/rconfig8coredocker/rconfig-retention.php
```

Docker mounts it read-only as:

```text
/var/www/html/rconfig/rconfig-retention.php
```

Conceptually:

```text
Docker Host
/home/<username>/rconfig8coredocker/
        |
        | rconfig-retention.php
        |
        | read-only bind mount
        v
rconfig_app
/var/www/html/rconfig/rconfig-retention.php
```

Therefore recreating `rconfig_app` does not remove the source retention script.

For example:

```bash
docker compose pull app
docker compose up -d
```

will mount the host copy back into the newly created application container.

---

# Quick Reference

## Dry Run

```bash
cd ~/rconfig8coredocker

docker compose exec app \
  php /var/www/html/rconfig/rconfig-retention.php
```

## Apply Retention

```bash
cd ~/rconfig8coredocker

./run-retention.sh
```

## Check Scheduled Job

```bash
crontab -l
```

## Check Retention Log

```bash
tail -100 ~/rconfig8coredocker/logs/retention.log
```

## Check Script Mount

```bash
docker inspect rconfig_app \
  --format '{{range .Mounts}}{{println .Source "->" .Destination}}{{end}}'
```

## PHP Syntax Check

```bash
docker compose exec app \
  php -l /var/www/html/rconfig/rconfig-retention.php
```

---

# Summary

The final configuration provides:

| Function | Configuration |
|---|---|
| Retention | 90 days |
| Automatic purge | Weekly |
| Schedule | Sunday 03:15 |
| Default script mode | Dry run |
| Automatic job mode | `--apply` |
| Latest configuration | Protected |
| Device + command newest config | Protected |
| Shared physical files | Protected |
| Retention script | Host-persistent |
| Container mount | Read-only |
| Logging | `logs/retention.log` |

Replace **`<username>`** throughout this document with the Linux username used to run the rConfig Docker installation.

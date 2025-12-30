#!/bin/bash

# Fleetbase Backup Script
# Adapted for Docker Environment

BACKUP_DIR="/opt/fleetbase/backups"
DATE=$(date +%Y%m%d_%H%M%S)
RETENTION_DAYS=30
DB_USER="fleetbase"
DB_PASSWORD="CHANGE_THIS_PASSWORD" # Ensure this matches your docker-compose.override.yml
DB_NAME="fleetbase"
CONTAINER_NAME="fleetbase-database-1" # Adjust if your container name is different, or use docker compose exec

# Ensure backup directories exist
mkdir -p $BACKUP_DIR/{mysql,files,config}

# Database backup
# Using docker compose to execute mysqldump inside the container
echo "Backing up database..."
cd /opt/fleetbase
docker compose exec -T database mysqldump -u $DB_USER -p$DB_PASSWORD $DB_NAME > $BACKUP_DIR/mysql/fleetbase_$DATE.sql

# File backup
# Backing up the storage directory which contains uploads and logs
echo "Backing up files..."
tar -czf $BACKUP_DIR/files/storage_$DATE.tar.gz /opt/fleetbase/storage

# Configuration backup
echo "Backing up configuration..."
cp /opt/fleetbase/api/.env $BACKUP_DIR/config/env_$DATE
cp /opt/fleetbase/Caddyfile $BACKUP_DIR/config/Caddyfile_$DATE
cp /opt/fleetbase/docker-compose.yml $BACKUP_DIR/config/docker-compose_$DATE.yml
cp /opt/fleetbase/docker-compose.override.yml $BACKUP_DIR/config/docker-compose.override_$DATE.yml

# Compress all backups into a single archive
echo "Compressing backup..."
tar -czf $BACKUP_DIR/fleetbase_full_backup_$DATE.tar.gz -C $BACKUP_DIR mysql files config

# Clean up temporary uncompressed files (optional, keeping them might be useful for quick access)
# rm $BACKUP_DIR/mysql/fleetbase_$DATE.sql
# rm $BACKUP_DIR/files/storage_$DATE.tar.gz
# rm $BACKUP_DIR/config/*_$DATE*

# Clean old backups
echo "Cleaning old backups..."
find $BACKUP_DIR -name "fleetbase_full_backup_*.tar.gz" -mtime +$RETENTION_DAYS -delete

# Log backup completion
echo "$(date): Backup completed - fleetbase_full_backup_$DATE.tar.gz" >> /var/log/fleetbase-backup.log

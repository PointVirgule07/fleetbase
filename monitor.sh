#!/bin/bash

# Fleetbase Monitoring Script
# Adapted for Docker Environment

LOG_FILE="/var/log/fleetbase-monitor.log"
DATE=$(date)

# Ensure log file exists and is writable (or log to stdout/stderr if preferred, but user asked for log file)
# Note: This script needs to run as root or a user with write access to /var/log
if [ ! -f "$LOG_FILE" ]; then
    touch "$LOG_FILE"
fi

echo "$DATE - System Monitor Check" >> $LOG_FILE

# Check disk space
DISK_USAGE=$(df -h / | awk 'NR==2 {print $5}' | sed 's/%//')
if [ "$DISK_USAGE" -gt 80 ]; then
    echo "WARNING: Disk usage is $DISK_USAGE%" >> $LOG_FILE
fi

# Check memory usage
MEM_USAGE=$(free | awk 'NR==2{printf "%.2f", $3/$2*100}')
if [ $(echo "$MEM_USAGE > 80" | bc) -eq 1 ]; then
    echo "WARNING: Memory usage is $MEM_USAGE%" >> $LOG_FILE
fi

# Check Docker Containers
cd /opt/fleetbase

# Check if all expected services are running
# Expected services: database, cache, application, console, socket, queue, scheduler, httpd
SERVICES=("database" "cache" "application" "console" "socket" "queue" "scheduler" "httpd")

for service in "${SERVICES[@]}"; do
    # Check if service is running using docker compose ps
    # We look for the service name and ensure it's "Up" or "running"
    if ! docker compose ps --format "table {{.Service}}\t{{.State}}" | grep -q "^$service.*running"; then
         # Fallback for older docker-compose or different output formats
         if ! docker compose ps | grep "$service" | grep -q "Up"; then
            echo "ERROR: Service $service is not running" >> $LOG_FILE
         fi
    fi
done

# Check for any unhealthy containers
UNHEALTHY=$(docker compose ps --format "json" | grep "unhealthy" | wc -l)
if [ "$UNHEALTHY" -gt 0 ]; then
    echo "WARNING: $UNHEALTHY containers are reported as unhealthy" >> $LOG_FILE
fi

echo "$DATE - Monitor Check Completed" >> $LOG_FILE

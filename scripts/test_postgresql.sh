#!/bin/bash

# PostgreSQL Connection Test Script
# This script tests PostgreSQL database connectivity

if [ -z "$1" ] || [ -z "$2" ] || [ -z "$3" ] || [ -z "$4" ] || [ -z "$5" ]; then
    echo "Usage: $0 <host> <port> <database> <username> <password>"
    exit 1
fi

HOST=$1
PORT=$2
DATABASE=$3
USERNAME=$4
PASSWORD=$5

echo "Testing PostgreSQL connection to ${HOST}:${PORT}/${DATABASE}"

# Set password environment variable for psql
export PGPASSWORD="$PASSWORD"

# Test connection using psql client
psql -h "$HOST" -p "$PORT" -U "$USERNAME" -d "$DATABASE" -c "SELECT 1 as test_connection;" 2>/dev/null

if [ $? -eq 0 ]; then
    echo "✓ PostgreSQL connection successful"
    exit 0
else
    echo "✗ PostgreSQL connection failed"
    exit 1
fi

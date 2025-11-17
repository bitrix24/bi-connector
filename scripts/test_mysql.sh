#!/bin/bash

# MySQL Connection Test Script
# This script tests MySQL database connectivity

if [ -z "$1" ] || [ -z "$2" ] || [ -z "$3" ] || [ -z "$4" ] || [ -z "$5" ]; then
    echo "Usage: $0 <host> <port> <database> <username> <password>"
    exit 1
fi

HOST=$1
PORT=$2
DATABASE=$3
USERNAME=$4
PASSWORD=$5

echo "Testing MySQL connection to ${HOST}:${PORT}/${DATABASE}"

# Test connection using mysql client
mysql -h"$HOST" -P"$PORT" -u"$USERNAME" -p"$PASSWORD" -e "SELECT 1 as test_connection;" "$DATABASE" 2>/dev/null

if [ $? -eq 0 ]; then
    echo "✓ MySQL connection successful"
    exit 0
else
    echo "✗ MySQL connection failed"
    exit 1
fi

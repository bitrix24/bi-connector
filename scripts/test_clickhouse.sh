#!/bin/bash

# ClickHouse Connection Test Script
# This script tests ClickHouse database connectivity over the HTTP interface

if [ -z "$1" ] || [ -z "$2" ] || [ -z "$3" ] || [ -z "$4" ] || [ -z "$5" ]; then
    echo "Usage: $0 <host> <port> <database> <username> <password>"
    exit 1
fi

HOST=$1
PORT=$2
DATABASE=$3
USERNAME=$4
PASSWORD=$5

echo "Testing ClickHouse connection to ${HOST}:${PORT}/${DATABASE}"

# Credentials travel in headers and never in the query string, the same rule the application follows
RESULT=$(curl --silent --fail --max-time 10 \
    --header "X-ClickHouse-User: ${USERNAME}" \
    --header "X-ClickHouse-Key: ${PASSWORD}" \
    --data-binary "SELECT 1 AS test_connection" \
    "http://${HOST}:${PORT}/?database=${DATABASE}" 2>/dev/null)

if [ $? -eq 0 ]; then
    echo "$RESULT"
    echo "✓ ClickHouse connection successful"
    exit 0
else
    echo "✗ ClickHouse connection failed"
    exit 1
fi

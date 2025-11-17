#!/bin/bash

# Database Setup Script
# This script helps setup database connections and test them

echo "BiConnector Extension - Database Setup Script"
echo "============================================="

# Check if required database clients are available
echo "Checking database client availability..."

if command -v mysql >/dev/null 2>&1; then
    echo "✓ MySQL client is available"
else
    echo "✗ MySQL client is not available"
fi

if command -v psql >/dev/null 2>&1; then
    echo "✓ PostgreSQL client is available"
else
    echo "✗ PostgreSQL client is not available"
fi

echo ""
echo "To test database connections, use:"
echo "  MySQL: ./test_mysql.sh <host> <port> <database> <username> <password>"
echo "  PostgreSQL: ./test_postgresql.sh <host> <port> <database> <username> <password>"
echo ""

# Check if environment variables are set
echo "Environment Variables:"
echo "APP_ENV: ${APP_ENV:-not set}"
echo "LOG_LEVEL: ${LOG_LEVEL:-not set}"
echo "LOG_PATH: ${LOG_PATH:-not set}"
echo "APP_DOMAIN: ${APP_DOMAIN:-not set}"

echo ""
echo "Setup complete!"

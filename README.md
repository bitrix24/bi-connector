# Bitrix24 BI Connector Extension

A serverless application for Bitrix24 that extends the capabilities of the built-in BI Connector with support for MySQL and PostgreSQL databases. This application integrates with Bitrix24's BI Constructor through REST API methods to provide external database connectivity.

## Features

- **Multi-Database Support**: Connect to MySQL and PostgreSQL databases
- **Connection Management**: Automatic connection validation and availability checks
- **Dynamic Table Discovery**: Search and retrieve table lists from external databases
- **Field Structure Analysis**: Get complete table schema information with data types
- **Advanced Data Export**: Support for filtering, sorting, and pagination
- **Performance Optimization**: Intelligent caching for table lists and structure data
- **Comprehensive Logging**: Detailed operation logging with configurable levels
- **Production Ready**: Docker-based deployment with FrankenPHP server
- **Security**: Built-in authentication and secure connection handling

## 🚨 Important Database Naming Requirements

### Table Names
When creating datasets, **table names must follow strict naming conventions**:
- **Must start with a letter** (a-z)
- **Only lowercase Latin letters** (a-z), numbers (0-9), and underscores (_) are allowed
- Examples: `users`, `order_items`, `customer_data_2024`
- ❌ Invalid: `Users`, `2024_data`, `order-items`, `données`

### Field Names  
Database table fields must adhere to the following rules:
- **Must start with a letter** (A-Z)
- **Only uppercase Latin letters** (A-Z), numbers (0-9), and underscores (_) are allowed
- Examples: `USER_ID`, `CREATED_AT`, `ORDER_TOTAL_2024`
- ❌ Invalid: `user_id`, `created-at`, `2024_total`, `données_client`

> ⚠️ **Critical**: Failure to follow these naming conventions will result in dataset creation errors and connection failures.

## Installation

### Prerequisites
- Docker and Docker Compose
- PHP 8.4+ (for local development)
- Access to a running Bitrix24 portal

### Quick Start
1. **Clone and configure**:
   ```bash
   git clone <repository-url>
   cd biconnector-extension
   cp .env.example .env
   ```

2. **Edit configuration**:
   Update `.env` file with your settings:
   ```env
   APP_DOMAIN=https://your-domain.com
   LOG_LEVEL=INFO
   CACHE_TTL_TABLE_LIST=3600
   CACHE_TTL_TABLE_DESCRIPTION=3600
   ```

3. **Build and start**:
   ```bash
   make build
   make start
   ```

4. **Install on Bitrix24**:
   - Upload application to your Bitrix24 portal
   - The application will automatically register MySQL and PostgreSQL connectors

## Configuration

### Environment Variables
Configure the application through the `.env` file:

| Variable | Description | Default |
|----------|-------------|---------|
| `APP_DOMAIN` | Public domain where application is hosted | Required |
| `APP_ENV` | Environment (development/production) | `development` |
| `LOG_LEVEL` | Logging level (DEBUG, INFO, WARNING, ERROR) | `DEBUG` |
| `LOG_PATH` | Directory for log files | `/var/log` |
| `LOG_ROTATION_DAYS` | Log file retention period | `7` |
| `CACHE_TTL_TABLE_LIST` | Table list cache duration (seconds) | `3600` |
| `CACHE_TTL_TABLE_DESCRIPTION` | Table structure cache duration (seconds) | `3600` |
| `DB_CONNECTION_TIMEOUT` | Database connection timeout (seconds) | `30` |

### Bitrix24 Application Settings
Required for REST API integration:
- `BITRIX24_PHP_SDK_APPLICATION_CLIENT_ID`
- `BITRIX24_PHP_SDK_APPLICATION_CLIENT_SECRET`
- `BITRIX24_PHP_SDK_APPLICATION_SCOPE`

## Development

### Using Makefile Commands
```bash
# Build and run
make build          # Build Docker image
make start          # Start application
make restart        # Restart with rebuild
make stop           # Stop application

# Development tools
make test           # Run all tests
make lint           # Run PHPStan analysis
make fix-code       # Fix code style issues
make pipeline       # Run full CI pipeline

# Utilities
make logs           # View application logs
make shell          # Access container shell
make clean          # Clean up containers and volumes
```

### Testing
```bash
# Run unit tests
make test

# Run specific test suites
composer test:unit
composer test:integration

# Code quality checks
make lint              # PHPStan analysis
composer cs-check      # Code style check
composer cs-fix        # Fix code style
```

## API Endpoints

The application provides four main endpoints that are called by Bitrix24:

### Connection Check (`/?action=check`)
**Purpose**: Validates database connection and authenticates user
**Called**: When creating/editing connections, creating datasets
**Request**: POST with connection parameters
**Response**: HTTP 200 with connection status

### Table List (`/?action=table_list`)
**Purpose**: Returns available tables matching search criteria
**Called**: During dataset creation
**Request**: 
```json
{
  "searchString": "search_value",
  "connection": {
    "host": "database_host",
    "database": "database_name",
    "username": "db_user",
    "password": "db_password"
  }
}
```
**Response**:
```json
[
  {
    "code": "dataset_name",
    "title": "external_table_name"
  }
]
```

### Table Description (`/?action=table_description`)
**Purpose**: Returns table field structure and data types
**Called**: During dataset creation and field synchronization
**Request**:
```json
{
  "name": "table_name",
  "connection": {
    "host": "database_host",
    "database": "database_name",
    "username": "db_user",
    "password": "db_password"
  }
}
```
**Response**:
```json
[
  {
    "code": "FIELD_NAME",
    "name": "Field Display Name",
    "type": "string|int|double|date|datetime"
  }
]
```

### Data Export (`/?action=data`)
**Purpose**: Exports table data with filtering, sorting, and pagination
**Called**: During dataset creation, synchronization, and BI Constructor queries
**Request**:
```json
{
  "select": ["FIELD1", "FIELD2"],
  "filter": {
    "FIELD1": "value",
    ">=FIELD2": "2024-01-01"
  },
  "limit": 1000,
  "table": "table_name",
  "connection": {
    "host": "database_host",
    "database": "database_name",
    "username": "db_user",
    "password": "db_password"
  }
}
```
**Response**:
```json
[
  ["FIELD1", "FIELD2", "FIELD3"],
  ["value1", "value2", "value3"],
  ["value4", "value5", "value6"]
]
```

## Architecture

### Core Components

- **`Application`**: Main application class handling installation and webhook processing
- **`BiConnector`**: Database connection management and query execution
- **`QueryBuilder`**: Dynamic SQL query construction with filtering and pagination
- **Caching Layer**: Symfony Cache with filesystem adapter for performance optimization
- **Logging System**: Monolog with rotating file handlers and detailed context logging

### Technology Stack

- **Runtime**: PHP 8.4+ with FrankenPHP server
- **SDK**: Bitrix24 PHP SDK 1.7.0+
- **Database**: Doctrine DBAL for MySQL and PostgreSQL
- **Caching**: Symfony Cache Component
- **Logging**: Monolog with rotation support
- **Testing**: PHPUnit with coverage reporting
- **Code Quality**: PHPStan static analysis and PHP CS Fixer

### Application Flow

1. **Installation**: User installs app on Bitrix24 portal
2. **Connector Registration**: App registers MySQL and PostgreSQL connectors via REST API
3. **Connection Setup**: User configures database connection parameters in Bitrix24
4. **Data Access**: Bitrix24 makes requests to app endpoints for data retrieval
5. **Response Processing**: App processes requests and returns formatted JSON responses

## Security Considerations

- All database connections use prepared statements to prevent SQL injection
- Connection parameters are validated and sanitized
- Authentication tokens are handled securely through Bitrix24 SDK
- Detailed logging for audit and debugging purposes
- Connection timeouts prevent resource exhaustion

## Performance Optimization

- **Caching**: Table lists and structures are cached to reduce database load
- **Connection Pooling**: Efficient database connection management
- **Query Optimization**: Smart SQL generation with proper indexing hints
- **Response Streaming**: Large datasets are processed in chunks
- **Memory Management**: Careful resource cleanup and garbage collection

## Troubleshooting

### Common Issues

1. **Database Connection Errors**
   - Verify connection parameters in .env file
   - Check database server accessibility
   - Ensure required PHP extensions are installed

2. **Cache Issues**
   - Clear cache directory: `rm -rf cache/biconnector/*`
   - Verify cache directory permissions

3. **Logging Problems**
   - Check log directory permissions
   - Verify LOG_PATH configuration

### Debug Mode
Enable detailed logging by setting `LOG_LEVEL=DEBUG` in `.env` file.

## Contributing

1. Fork the repository
2. Create feature branch: `git checkout -b feature/new-feature`
3. Make changes and add tests
4. Run quality checks: `make pipeline`
5. Submit pull request

## License

This project is licensed under the MIT License - see the LICENSE file for details.

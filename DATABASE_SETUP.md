# Quick Guide to Connecting to External Databases

## 🚀 Quick Start

1. **Run the project** (network will be created automatically):
   ```bash
   make build
   make start
   ```

3. **Check database connections**:
   ```bash
   # Automatic check of all connections
   make check-db-connectivity
   
   # Or via PHP utility
   make test-db-php
   ```

## 🔧 Main Commands

| Command | Description |
|---------|----------|
| `make create-shared-network` | Create shared_db_network |
| `make check-db-connectivity` | Comprehensive connectivity check |
| `make test-db-php` | Check via PHP utility |
| `make ping-db HOST=mysql` | Ping specific host |
| `make test-port HOST=mysql PORT=3306` | Check port availability |
| `make list-networks` | List Docker networks |

## 🎯 Usage Examples

```bash
# Check MySQL container connection
make check-mysql HOST=mysql

# Check PostgreSQL container connection
make check-postgres HOST=postgres

# Enter shell for manual diagnostics
make shell

# In container shell:
mysql -h mysql -u root -p
psql -h postgres -U postgres -d postgres
```

## 📋 Requirements

- Docker and Docker Compose
- Existing MySQL/PostgreSQL containers in `shared_db_network`
- Database containers must be running

## 🆘 Help

For a complete list of commands:
```bash
make help
```

Detailed documentation in `docs/database-connectivity.md`.

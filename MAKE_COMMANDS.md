# Make Commands Quick Reference

## 🚀 Main Commands

```bash
# Help for all commands
make help

# Initial project setup
make dev-setup

# Run application
make start

# Stop application
make stop

# View logs
make logs
```

## 🧪 Testing

```bash
# All tests
make test

# Tests with detailed output
make test-detailed

# Only unit tests
make test-unit

# Tests with coverage
make test-coverage
```

## 🔍 Code Quality

```bash
# All checks (PHPStan + CodeSniffer)
make lint

# PHPStan only
make phpstan

# Style check only
make phpcs

# Auto-fix style
make fix-code
```

## 🚀 CI/CD

```bash
# Full check (as in CI/CD)
make pipeline

# Prepare for deployment
make pre-deploy
```

## 📦 Dependencies

```bash
# Install dependencies
make install

# Update dependencies
make update

# Install new package
make require PACKAGE=vendor/package-name
```

## 🛠️ Development

```bash
# Access container
make shell

# Restart with rebuild
make restart

# Container status
make status
```

## 🧹 Cleanup

```bash
# Stop and remove containers
make clean

# Full cleanup
make clean-all

# Reset to clean state
make reset
```

## 🔄 Complex Operations

```bash
# Full rebuild
make rebuild

# Initial development setup
make dev-setup

# Reset everything to clean state
make reset
```

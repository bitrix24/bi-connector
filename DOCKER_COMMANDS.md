# Docker Compose Commands for Managing Bitrix24 BI Connector Extension Project

## 🚀 Build and Run

### Build Docker Image
```bash
docker-compose build
```

### Run Application in Dev Mode
```bash
docker-compose up -d
```

### Run with Rebuild
```bash
docker-compose up -d --build
```

### View Application Logs
```bash
docker-compose logs -f app
```

## 🧪 Testing

### Run All Unit Tests
```bash
docker-compose --profile test run --rm test
```

### Run Tests with Detailed Output
```bash
docker-compose --profile test run --rm test vendor/bin/phpunit --testdox
```

### Run Specific Test
```bash
docker-compose --profile test run --rm test vendor/bin/phpunit tests/Unit/ApplicationTest.php
```

## 🔍 Linters and Code Quality

### Run PHPStan (Static Analysis)
```bash
docker-compose --profile lint run --rm phpstan
```

### Check Code Style (PHP_CodeSniffer)
```bash
docker-compose --profile lint run --rm phpcs
```

### Auto-fix Code Style
```bash
docker-compose --profile lint run --rm phpcbf
```

### Run All Checks in Sequence
```bash
docker-compose --profile lint run --rm phpstan && \
docker-compose --profile lint run --rm phpcs && \
docker-compose --profile test run --rm test
```

## 🛠️ Dependency Management

### Install Composer Dependencies
```bash
docker-compose run --rm app composer install
```

### Update Dependencies
```bash
docker-compose run --rm app composer update
```

### Install New Dependency
```bash
docker-compose run --rm app composer require package/name
```

## 🛑 Stopping and Cleanup

### Stop Application
```bash
docker-compose down
```

### Stop with Volume Removal
```bash
docker-compose down -v
```

### Full Cleanup (containers, images, volumes)
```bash
docker-compose down -v --rmi all
```

### Clean Unused Docker Resources
```bash
docker system prune -a -f
```
docker system prune -a -f
```

## 📊 Monitoring and Debugging

### View Container Status
```bash
docker-compose ps
```

### Connect to Application Container
```bash
docker-compose exec app bash
```

### View Container Resources
```bash
docker-compose top
```

### View Logs of All Services
```bash
docker-compose logs
```

## 🔄 Complex Commands

### Full Rebuild and Run
```bash
docker-compose down && \
docker-compose build --no-cache && \
docker-compose up -d
```

### CI/CD Pipeline (checks + tests)
```bash
docker-compose build && \
docker-compose --profile lint run --rm phpstan && \
docker-compose --profile lint run --rm phpcs && \
docker-compose --profile test run --rm test && \
echo "✅ All checks passed!"
```

### Preparation for Production Deployment
```bash
docker-compose down && \
docker-compose build --no-cache && \
docker-compose --profile test run --rm test && \
docker-compose --profile lint run --rm phpstan && \
echo "🚀 Ready for deployment!"
```

## 📝 Additional Commands

### Check docker-compose Configuration
```bash
docker-compose config
```

### Force Image Rebuild
```bash
docker-compose build --no-cache --pull
```

### View Disk Space Usage
```bash
docker system df
```

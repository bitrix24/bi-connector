# Makefile for Bitrix24 BI Connector Extension
# Docker Compose based project management

.PHONY: help build start stop restart logs status clean test lint fix-code pipeline install update shell config prune

# Default target
.DEFAULT_GOAL := help

# Colors for output
YELLOW := \033[1;33m
GREEN := \033[1;32m
RED := \033[1;31m
BLUE := \033[1;34m
CYAN := \033[1;36m
MAGENTA := \033[1;35m
NC := \033[0m # No Color

## 🚀 Build and Run Commands

build: ## Build Docker image
	@echo "$(YELLOW)🔨 Building Docker image...$(NC)"
	docker-compose build

start: ## Start application in development mode
	@echo "$(GREEN)🚀 Starting application...$(NC)"
	@echo "$(BLUE)📡 Ensuring shared_db_network exists...$(NC)"
	@docker network inspect shared_db_network >/dev/null 2>&1 || docker network create shared_db_network
	docker-compose up -d
	@echo "$(GREEN)✅ Application started at http://localhost:8080$(NC)"
	@echo "$(GREEN)🌐 Container is connected to shared_db_network$(NC)"

stop: ## Stop application
	@echo "$(RED)🛑 Stopping application...$(NC)"
	docker-compose down

restart: ## Restart application with rebuild
	@echo "$(YELLOW)🔄 Restarting application...$(NC)"
	docker-compose down
	@echo "$(BLUE)📡 Ensuring shared_db_network exists...$(NC)"
	@docker network inspect shared_db_network >/dev/null 2>&1 || docker network create shared_db_network
	docker-compose up -d --build
	@echo "$(GREEN)✅ Application restarted$(NC)"
	@echo "$(GREEN)🌐 Container is connected to shared_db_network$(NC)"

logs: ## Show application logs
	@echo "$(BLUE)📋 Showing application logs...$(NC)"
	docker-compose logs -f app

status: ## Show containers status
	@echo "$(BLUE)📊 Container status:$(NC)"
	docker-compose ps

## 🧪 Testing Commands

test: ## Run all PHPUnit tests
	@echo "$(CYAN)🧪 Running tests...$(NC)"
	docker-compose --profile test run --rm test

test-detailed: ## Run tests with detailed output
	@echo "$(CYAN)🧪 Running tests with detailed output...$(NC)"
	docker-compose --profile test run --rm test vendor/bin/phpunit --testdox

test-unit: ## Run only unit tests
	@echo "$(CYAN)🧪 Running unit tests...$(NC)"
	docker-compose --profile test run --rm test vendor/bin/phpunit tests/Unit

test-integration: ## Run only integration tests
	@echo "$(CYAN)🧪 Running integration tests...$(NC)"
	docker-compose --profile test run --rm test vendor/bin/phpunit tests/Integration

test-coverage: ## Run tests with coverage report
	@echo "$(CYAN)🧪 Running tests with coverage...$(NC)"
	docker-compose --profile test run --rm test vendor/bin/phpunit --coverage-html coverage

## 🔍 Code Quality Commands

lint: ## Run all linters (PHPStan + CodeSniffer)
	@echo "$(MAGENTA)🔍 Running code quality checks...$(NC)"
	@echo "$(MAGENTA)▶️ PHPStan static analysis...$(NC)"
	docker-compose --profile lint run --rm phpstan
	@echo "$(MAGENTA)▶️ PHP_CodeSniffer style check...$(NC)"
	docker-compose --profile lint run --rm phpcs

phpstan: ## Run PHPStan static analysis
	@echo "$(MAGENTA)🔍 Running PHPStan...$(NC)"
	docker-compose --profile lint run --rm phpstan

phpcs: ## Check code style with PHP_CodeSniffer
	@echo "$(MAGENTA)🔍 Checking code style...$(NC)"
	docker-compose --profile lint run --rm phpcs

fix-code: ## Auto-fix code style issues
	@echo "$(YELLOW)🛠️ Auto-fixing code style...$(NC)"
	docker-compose --profile lint run --rm phpcbf
	@echo "$(GREEN)✅ Code style fixed$(NC)"

## 🚀 CI/CD Pipeline Commands

pipeline: ## Run full CI/CD pipeline (build + lint + test)
	@echo "$(GREEN)🚀 Running full CI/CD pipeline...$(NC)"
	@echo "$(GREEN)1️⃣ Building Docker image...$(NC)"
	docker-compose build
	@echo "$(GREEN)2️⃣ Running PHPStan analysis...$(NC)"
	docker-compose --profile lint run --rm phpstan
	@echo "$(GREEN)3️⃣ Checking code style...$(NC)"
	docker-compose --profile lint run --rm phpcs
	@echo "$(GREEN)4️⃣ Running tests...$(NC)"
	docker-compose --profile test run --rm test
	@echo "$(GREEN)✅ All checks passed successfully!$(NC)"

pre-deploy: ## Prepare for production deployment
	@echo "$(GREEN)🚀 Preparing for deployment...$(NC)"
	docker-compose down
	docker-compose build --no-cache
	docker-compose --profile test run --rm test
	docker-compose --profile lint run --rm phpstan
	@echo "$(GREEN)🚀 Ready for deployment!$(NC)"

## 📦 Dependency Management

install: ## Install Composer dependencies
	@echo "$(BLUE)📦 Installing dependencies...$(NC)"
	docker-compose run --rm app composer install

update: ## Update Composer dependencies
	@echo "$(BLUE)📦 Updating dependencies...$(NC)"
	docker-compose run --rm app composer update

require: ## Install new dependency (usage: make require PACKAGE=vendor/package)
	@echo "$(BLUE)📦 Installing $(PACKAGE)...$(NC)"
	docker-compose run --rm app composer require $(PACKAGE)

autoload: ## Dump Composer autoloader
	@echo "$(BLUE)📦 Dumping autoloader...$(NC)"
	docker-compose run --rm app composer dump-autoload

## 🛠️ Development Tools

shell: ## Access application container shell
	@echo "$(BLUE)💻 Accessing container shell...$(NC)"
	docker-compose exec app bash

shell-new: ## Create new container and access shell
	@echo "$(BLUE)💻 Creating new container and accessing shell...$(NC)"
	docker-compose run --rm app bash

config: ## Validate docker-compose configuration
	@echo "$(BLUE)⚙️ Validating docker-compose configuration...$(NC)"
	docker-compose config

## 🗄️ Database Connection Tools

check-mysql: ## Check MySQL database connection (usage: make check-mysql HOST=mysql_container)
	@echo "$(BLUE)🔍 Checking MySQL connection to $(HOST)...$(NC)"
	docker-compose exec app mysql -h $(HOST) -u root -p -e "SELECT 1;"

check-postgres: ## Check PostgreSQL database connection (usage: make check-postgres HOST=postgres_container)
	@echo "$(BLUE)🔍 Checking PostgreSQL connection to $(HOST)...$(NC)"
	docker-compose exec app psql -h $(HOST) -U postgres -c "SELECT 1;"

ping-db: ## Ping database host (usage: make ping-db HOST=container_name)
	@echo "$(BLUE)🏓 Pinging $(HOST)...$(NC)"
	docker-compose exec app ping -c 3 $(HOST)

test-port: ## Test port connectivity (usage: make test-port HOST=container_name PORT=3306)
	@echo "$(BLUE)🔌 Testing connection to $(HOST):$(PORT)...$(NC)"
	docker-compose exec app nc -zv $(HOST) $(PORT)

list-networks: ## List Docker networks
	@echo "$(BLUE)🌐 Docker networks:$(NC)"
	docker network ls

create-shared-network: ## Create shared_db_network if it doesn't exist
	@echo "$(BLUE)🌐 Creating shared_db_network...$(NC)"
	@docker network inspect shared_db_network >/dev/null 2>&1 || docker network create shared_db_network
	@echo "$(GREEN)✅ Network shared_db_network is ready$(NC)"

check-db-connectivity: ## Run comprehensive database connectivity check
	@echo "$(BLUE)🔍 Running database connectivity check...$(NC)"
	docker-compose exec app bash /app/scripts/check_db_connectivity.sh

test-db-php: ## Test database connections using PHP utility
	@echo "$(BLUE)🐘 Testing database connections with PHP...$(NC)"
	docker-compose exec app php /app/scripts/test_db_connections.php

## 🧹 Cleanup Commands

clean: ## Stop and remove containers
	@echo "$(RED)🧹 Cleaning containers...$(NC)"
	docker-compose down -v

clean-all: ## Full cleanup (containers, images, volumes)
	@echo "$(RED)🧹 Full cleanup...$(NC)"
	docker-compose down -v --rmi all
	@echo "$(GREEN)✅ Cleanup completed$(NC)"

prune: ## Clean unused Docker resources
	@echo "$(RED)🧹 Pruning unused Docker resources...$(NC)"
	docker system prune -a -f
	@echo "$(GREEN)✅ Docker resources pruned$(NC)"

clean-logs: ## Clean application logs
	@echo "$(RED)🧹 Cleaning logs...$(NC)"
	rm -rf logs/*.log
	@echo "$(GREEN)✅ Logs cleaned$(NC)"

clean-cache: ## Clean application cache
	@echo "$(RED)🧹 Cleaning cache...$(NC)"
	rm -rf cache/*
	@echo "$(GREEN)✅ Cache cleaned$(NC)"

## 🔄 Complex Operations

rebuild: ## Full rebuild (clean + build + start)
	@echo "$(YELLOW)🔄 Full rebuild...$(NC)"
	$(MAKE) clean
	@echo "$(BLUE)📡 Ensuring shared_db_network exists...$(NC)"
	@docker network inspect shared_db_network >/dev/null 2>&1 || docker network create shared_db_network
	$(MAKE) build
	$(MAKE) start

reset: ## Reset everything to clean state
	@echo "$(RED)🔄 Resetting to clean state...$(NC)"
	$(MAKE) clean-all
	$(MAKE) prune
	$(MAKE) build
	$(MAKE) install
	$(MAKE) start

dev-setup: ## Initial development setup
	@echo "$(GREEN)🔧 Setting up development environment...$(NC)"
	$(MAKE) build
	$(MAKE) install
	$(MAKE) start
	@echo "$(GREEN)✅ Development environment ready!$(NC)"

## 📊 Monitoring and Debug

top: ## Show container resource usage
	@echo "$(BLUE)📊 Container resource usage:$(NC)"
	docker-compose top

stats: ## Show real-time container statistics
	@echo "$(BLUE)📊 Real-time container statistics:$(NC)"
	docker stats

disk-usage: ## Show Docker disk usage
	@echo "$(BLUE)💾 Docker disk usage:$(NC)"
	docker system df

## 📝 Documentation and Help

help: ## Show this help message
	@echo "$(GREEN)🚀 Bitrix24 BI Connector Extension - Docker Management$(NC)"
	@echo "$(GREEN)Available commands:$(NC)\n"
	@awk 'BEGIN {FS = ":.*##"; printf ""} /^[a-zA-Z_-]+:.*?##/ { printf "  $(CYAN)%-18s$(NC) %s\n", $$1, $$2 } /^##@/ { printf "\n$(YELLOW)%s$(NC)\n", substr($$0, 5) } ' $(MAKEFILE_LIST)
	@echo "\n$(YELLOW)Examples:$(NC)"
	@echo "  make dev-setup    # Initial setup for development"
	@echo "  make pipeline     # Run full CI/CD checks"
	@echo "  make test         # Run all tests"
	@echo "  make lint         # Check code quality"
	@echo "  make logs         # View application logs"
	@echo "\n$(BLUE)For more details see DOCKER_COMMANDS.md$(NC)"

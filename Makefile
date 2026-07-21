.PHONY: build up down restart logs logs-app logs-db ps shell psql migrate seed configure sync-run sync-status transactions clean

COMPOSE := docker compose

build: ## Build the app image
	$(COMPOSE) build

up: ## Start postgres + app in the background
	$(COMPOSE) up -d

down: ## Stop and remove containers
	$(COMPOSE) down

restart: ## Recreate app container, picking up .env changes
	$(COMPOSE) up -d --force-recreate app

logs: ## Tail all container logs
	$(COMPOSE) logs -f

logs-app: ## Tail app container logs only
	$(COMPOSE) logs -f app

logs-db: ## Tail postgres container logs only
	$(COMPOSE) logs -f postgres

ps: ## Show container status
	$(COMPOSE) ps

shell: ## Shell into the running app container
	$(COMPOSE) exec app sh

psql: ## Open a psql prompt against the app's postgres
	$(COMPOSE) exec postgres psql -U $${POSTGRES_USER:-finance} -d $${POSTGRES_DB:-finance_hub}

migrate: ## Run drizzle migrations inside the app container
	$(COMPOSE) exec app npm run db:migrate

seed: ## Seed the categories table
	$(COMPOSE) exec app npm run seed:categories

configure: ## Print the URL to connect a bank via SimpleFin
	$(COMPOSE) exec app npm run configure

sync-run: ## Trigger a SimpleFin transactions sync now
	$(COMPOSE) exec app npm run cli -- sync run

sync-status: ## Check status of the last sync run
	$(COMPOSE) exec app npm run cli -- sync status

transactions: ## List synced transactions
	$(COMPOSE) exec app npm run cli -- transactions list

clean: ## Stop containers and remove volumes (DESTROYS DB DATA)
	$(COMPOSE) down -v

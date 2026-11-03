SHELL := /bin/bash

.PHONY: db-create nats-dev minio-dev setup migrate web-dev gateway-dev worker-dev queue test lint test-e2e

db-create:
	createdb chat_app || true

nats-dev:
	mkdir -p .data/nats
	nats-server -js -sd .data/nats -m 8222

minio-dev:
	mkdir -p .data/minio
	minio server .data/minio --console-address :9001

setup:
	test -f apps/web/.env || cp apps/web/.env.example apps/web/.env
	test -f apps/gateway/.env || cp apps/gateway/.env.example apps/gateway/.env
	test -f apps/worker/.env || cp apps/worker/.env.example apps/worker/.env
	cd apps/web && composer install --no-interaction
	cd apps/web && php artisan key:generate
	cd apps/web && npm install
	cd apps/gateway && go mod download
	cd apps/worker && go mod download

migrate:
	cd apps/web && php artisan migrate

web-dev:
	cd apps/web && composer run dev

gateway-dev:
	cd apps/gateway && go run ./cmd/gateway

worker-dev:
	cd apps/worker && go run ./cmd/worker

queue:
	cd apps/web && php artisan queue:work

test:
	cd apps/web && composer test
	cd apps/gateway && go test ./...
	cd apps/worker && go test ./...

lint:
	cd apps/web && composer lint
	cd apps/gateway && go vet ./...
	cd apps/worker && go vet ./...

test-e2e:
	cd apps/web && npm run test:e2e

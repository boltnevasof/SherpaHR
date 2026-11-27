.PHONY: up test down
up:
	docker compose up --build -d --wait
test:
	docker compose exec -T app php backend/tests/smoke.php
	docker compose exec -T app php backend/tests/attention.php
down:
	docker compose down

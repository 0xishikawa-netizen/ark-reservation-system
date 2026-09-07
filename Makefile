# ARK 予約・決済システム — 1 人保守向けショートカット
# Laravel 本体 + Sail 導入後に有効（README「ローカル開発環境のセットアップ」参照）。

SAIL := ./vendor/bin/sail

.PHONY: help up down restart migrate fresh test tinker logs queue schedule probe

help:
	@echo "make up        - コンテナ起動 (sail up -d)"
	@echo "make down      - コンテナ停止"
	@echo "make migrate   - php artisan migrate"
	@echo "make fresh     - migrate:fresh --seed （ローカルのみ）"
	@echo "make test      - php artisan test"
	@echo "make queue     - queue:work（前景）"
	@echo "make schedule  - schedule:work（前景）"
	@echo "make logs      - tail storage/logs/laravel-*.log"
	@echo "make probe     - お名前.com サーバー能力プローブの使い方"

up:        ; $(SAIL) up -d
down:      ; $(SAIL) down
restart:   ; $(SAIL) down && $(SAIL) up -d
migrate:   ; $(SAIL) artisan migrate
fresh:     ; $(SAIL) artisan migrate:fresh --seed
test:      ; $(SAIL) artisan test
tinker:    ; $(SAIL) artisan tinker
queue:     ; $(SAIL) artisan queue:work --tries=3
schedule:  ; $(SAIL) artisan schedule:work
logs:      ; tail -n 200 -f storage/logs/laravel-*.log

probe:
	@echo "サーバー上で PHP が使える場合:"
	@echo "  php tools/server-probe.php > tools/probe-output.txt"
	@echo "SSH/シェルのみの場合:"
	@echo "  bash tools/server-probe.sh | tee tools/probe-output.txt"
	@echo "結果を docs/PHASE0_REPORT.md の『実測結果』へ貼り付ける。"

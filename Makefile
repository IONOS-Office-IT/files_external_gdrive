app_name=files_external_gdrive
build_dir=$(CURDIR)/build
sign_dir=$(build_dir)/sign
appstore_dir=$(build_dir)/appstore
cert_dir=$(HOME)/.nextcloud/certificates

all: composer

composer:
	composer install --no-dev --prefer-dist

composer-dev:
	composer install --prefer-dist

clean:
	rm -rf $(build_dir)
	rm -rf vendor

appstore: clean composer
	mkdir -p $(sign_dir)/$(app_name)
	rsync -a \
		--exclude='.git' \
		--exclude='.github' \
		--exclude='build' \
		--exclude='tests' \
		--exclude='.gitignore' \
		--exclude='composer.json' \
		--exclude='composer.lock' \
		--exclude='Makefile' \
		--exclude='phpcs.xml' \
		--exclude='*.orig' \
		$(CURDIR)/ $(sign_dir)/$(app_name)/
	tar czf $(appstore_dir)/$(app_name).tar.gz \
		-C $(sign_dir) $(app_name)
	@echo "App package: $(appstore_dir)/$(app_name).tar.gz"

.PHONY: all composer composer-dev clean appstore

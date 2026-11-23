.PHONY: help pack push tag release

help:
	@printf '%s\n' \
		'Release: make pack → commit → make release' \
		'' \
		'  make pack     Create a ZIP from the committed sources' \
		'  make push     Push main to GitHub' \
		'  make tag      Create and push the committed package version tag' \
		'  make release  Pack, push and tag for Packagist'

pack:
	composer validate --no-check-lock
	mkdir -p dist
	git archive --format=zip --output=dist/laravel-mediaforge.zip HEAD

push:
	git push origin main

tag:
	@set -e; \
	version=$$(git show HEAD:composer.json | php -r '$$package = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo $$package["extra"]["release-version"] ?? "";'); \
	test -n "$$version"; \
	git tag -a "$$version" -m "Release $$version"; \
	git push origin "refs/tags/$$version"

release:
	@test "$$(git branch --show-current)" = main || { echo "Release from main." >&2; exit 1; }
	@test -z "$$(git status --porcelain)" || { echo "Commit the release changes first." >&2; exit 1; }
	$(MAKE) pack
	$(MAKE) push
	$(MAKE) tag

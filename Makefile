.PHONY: help push tag

help:
	@printf '%s\n' \
		'Release: set release version → commit → make push → make tag' \
		'' \
		'  make push     Push main to GitHub' \
		'  make tag      Create and push the committed package version tag'

push:
	git push origin main

tag:
	@set -e; \
	version=$$(git show HEAD:composer.json | php -r '$$package = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo $$package["extra"]["release-version"] ?? "";'); \
	test -n "$$version"; \
	git tag -a "$$version" -m "Release $$version"; \
	git push origin "refs/tags/$$version"

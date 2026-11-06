# Shakewell Error Reporting for Sentry

Free, GPL-2.0-or-later WordPress plugin that reports PHP errors to Sentry with the official `sentry/sentry` SDK. Setup, constants, filters and the External services statement are in `readme.txt`.

## Work on it

```sh
composer install
npm ci
npm run env:start      # WordPress at http://localhost:8896
npm test               # PHPUnit in the test container, fake transport, no network
composer lint
npm run plugin-check
```

## Release

1. Bump the version in the plugin header, `readme.txt` (`Stable tag`) and the `VERSION` constant, and add a changelog line.
2. Tag `vX.Y.Z` and push. The release workflow runs `composer install --no-dev`, so the zip includes the production `vendor/` folder (the Sentry SDK), and leaves out whatever `.distignore` lists.

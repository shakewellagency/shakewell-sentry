# Shakewell Error Reporting for Sentry

> **Made by [Shakewell](https://shakewell.agency).** Free and open source, built and maintained by the Shakewell team.

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

## Made by Shakewell

This plugin is one of a family of free WordPress plugins from [Shakewell](https://shakewell.agency). Each one does one job, keeps its dependencies small and is released under GPL-2.0-or-later.

Use it, fork it and send fixes. To have it set up on your site, or to have a plugin built, [talk to Shakewell](https://shakewell.agency).

More from Shakewell:

- [Shakewell Activity Log](https://github.com/shakewellagency/shakewell-activity-log)
- [Shakewell Multiple Authors](https://github.com/shakewellagency/shakewell-authors)
- [Shakewell Clone Posts](https://github.com/shakewellagency/shakewell-clone-posts)
- [Shakewell Connector for Klaviyo and Gravity Forms](https://github.com/shakewellagency/shakewell-klaviyo-connector)
- [Shakewell Media Offload for Amazon S3](https://github.com/shakewellagency/shakewell-media-offload)
- [Shakewell Redirects](https://github.com/shakewellagency/shakewell-redirects)
- [Shakewell Two-way Relationships for ACF](https://github.com/shakewellagency/shakewell-relationship-sync)
- [Shakewell Single Sign-On (SAML)](https://github.com/shakewellagency/shakewell-saml-sso)
- [Shakewell Mailer for Amazon SES](https://github.com/shakewellagency/shakewell-ses-mailer)
- [Shakewell Sitemap](https://github.com/shakewellagency/shakewell-sitemap)
- [Shakewell plugin template](https://github.com/shakewellagency/shakewell-plugin-template), the starting point for every plugin

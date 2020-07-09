# Change Log
All notable changes to this package should be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).


## [Unreleased]
### Fixed:
 - Memory limit calculations where PHP returns values in shortened form e.g. "256M"
 - Some WPCS and PHPCS improvements
 - Timeout check does not abort process when running from `WP_CLI`
 - Timeout increased for non-blocking requests to increase reliability
 - Close FastCGI connection during handle to avoid blocking requests during processing
 - Health-check could start processing even when another process may have been running.

### Added:
 - Option to use REST API instead of WP AJAX
 - filters for `WP_Async_Request::wp_remote_post()` arguments.
 - CHANGELOG.md, composer.lock
 
### Changed:
 - PHPDoc documentation improved with more detailed descriptions

## [1.0.1] (2019-11-03)
### Fixed:
 - Fix session locking on slow running background processes
 - Fix session locking on async requests
 - Escape underscores in queries to avoid wildcard query
 - Fix cron_interval property support
 - Memory limit check failed for "-1" as string

### Changed:
 - License in Composer is modified from GPLv2+ to GPL-2.0-only

## [1.0.0] (2016-08-07)
First release as composer package.


[Unreleased]: https://github.com/BenceSzalai/wp-background-processing/compare/1.0.1...HEAD
[1.0.1]: https://github.com/a5hleyrich/wp-background-processing/compare/1.0...1.0.1
[1.0.0]: https://github.com/a5hleyrich/wp-background-processing/tree/1.0

# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Add option to schedule single logs.

### Changed

- Rename herbicide form to spraying.

## 1.0.0-alpha2 - 2025-07-01

### Added

- Add support for multiple logs in herbicide quick form.
- Add link to material type taxonomy.

### Changed

- Load previously submitted log values when scheduling logs.

## 1.0.0-alpha1 - 2025-06-30

Initial alpha release of `scd_riparian` module. Includes the following features:
- Adds `site` and `segment` land types
- Provides a KML importer to create parent site assets with child segments
- Mowing, Watering and Herbicide quick forms to schedule and record
  maintenance activities.

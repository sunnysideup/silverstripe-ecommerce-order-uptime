# Upgrade Guide: Moving to Silverstripe CMS 6

This document outlines the necessary steps and breaking changes required to upgrade your project to be compatible with `sunnysideup/ecommerce-order-uptime` for Silverstripe CMS 6.

## New Requirements

-   **Silverstripe CMS 6:** This module now requires Silverstripe CMS `^6.0`.
-   **Silverstripe Admin 3:** The dependency on `silverstripe/admin` has been updated to `^3.0`.

## ⚠️ BREAKING CHANGES

### Composer Dependencies

The `composer.json` file has been updated to require Silverstripe 6 and Admin 3. You must update your project's `composer.json` to match these new requirements.

-   **`silverstripe/framework`**: `^5.0` → `^6.0`
-   **`silverstripe/admin`**: `^2.0` → `^3.0`

### PHP Code Changes

-   The `init()` method in `OrderStatusLogSubmittedCreationMonitorController` now uses the `#[Override]` attribute, which is standard practice in the newer version.

## Developer Follow-up

-   No other breaking API changes were detected in the diff. However, given the major version jump of the Silverstripe framework, a full regression test of your project is strongly recommended.

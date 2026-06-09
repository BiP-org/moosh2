---
name: release
description: Prepare next release — bump minor version in Application.php and add a debian/changelog entry. Use when cutting a new moosh2 release.
---

# Prepare next release

Read the current version from @src/Application.php , look for public const VERSION
Increase the minor version by 1 (for example from 2.9 to 2.10) - update in @src/Application.php
Add entry to @debian/changelog in format:
-----------
moosh2 (2.X-1) noble; urgency=medium

  * Upstream release 2.X

 -- Tomasz Muras <nexor1984@gmail.com>  Tue, 09 Jun 2026 13:16:50 +0200
-----------
Replace X with new version and date and time at the end with the current date and time.

---
description: Cut a new SecureMessage Mail MCP release (version bump, tag, PHAR build via CI)
---

# SecureMessage Mail MCP Release Workflow

## Prerequisites
- All changes committed and pushed to `master`
- Tests passing locally: `phpunit` in repo root
- CHANGELOG entry written for the new version

## Steps

1. Bump version in `system/app.conf.php`
   - Update `APPLICATION_VERSION` to the new version string (e.g., `'1.1.0'`)

2. Add CHANGELOG entry in `CHANGELOG.md`
   - Add `## [X.Y.Z] - YYYY-MM-DD` section at the top
   - Document Added/Changed/Fixed/Removed sections as appropriate

3. Update PHAR download URLs in documentation
   - These three files have versioned download URLs that must match the new version:
     - `README.md` — Quick Start section
     - `docs/SETUP.md` — Installation section
     - `docs/AGENT_SKILL.md` — Install section
   - Change `/releases/download/vOLD/mail-mcp.phar` → `/releases/download/vNEW/mail-mcp.phar`
   - **Why:** Forgejo does not support GitHub's `/releases/latest/download/` shortcut (returns 404).
     The versioned URL pattern `/releases/download/{tag}/{filename}` is required.

4. Commit and push the version bump
   ```sh
   git add -A && git commit -m "chore: release vX.Y.Z"
   git push origin master
   ```

5. Create and push annotated tag
   ```sh
   git tag -a vX.Y.Z -m "vX.Y.Z: <brief description>"
   git push origin vX.Y.Z
   ```
   - This triggers the Forgejo Actions release workflow (tests + PHAR build + Forgejo release)
   - Verify at: https://pacyworld.dev/pacyworld/mail-mcp/actions

6. Wait for CI release workflow to complete
   - Confirm release created at: https://pacyworld.dev/pacyworld/mail-mcp/releases
   - Should have: `mail-mcp.phar` attached as release asset
   - Verify download URL works:
     ```sh
     curl -sI -o /dev/null -w '%{http_code}' https://pacyworld.dev/pacyworld/mail-mcp/releases/download/vX.Y.Z/mail-mcp.phar
     ```
     Must return `200`.

## Key Details

- **Repo**: pacyworld.dev/pacyworld/mail-mcp (origin: https://pacyworld.dev/pacyworld/mail-mcp.git)
- **Version file**: `system/app.conf.php` — `APPLICATION_VERSION`
- **CI runner**: runner01.pacyworld.com (FreeBSD), label: FreeBSD
- **CI release workflow**: `.forgejo/workflows/release.yml` — runs tests, builds PHAR, creates Forgejo release with asset
- **PHAR builder**: `bin/build-phar.php`
- **Tests**: `phpunit` (66 tests, 271 assertions as of v1.0.0)
- **No FreeBSD port yet** — PHAR-only distribution for now
- **Download URL pattern**: `https://pacyworld.dev/pacyworld/mail-mcp/releases/download/{tag}/mail-mcp.phar`

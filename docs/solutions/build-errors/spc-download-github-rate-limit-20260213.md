---
date: 2026-02-13
problem_type: build-error
component: .github/workflows/build-cli.yml
severity: moderate
symptoms:
  - "curl: (56) The requested URL returned error: 403"
  - "Download failed: Failed to fetch https://api.github.com/repos/static-php/static-php-cli-hosted/releases"
root_cause: GitHub API rate limit for unauthenticated requests in CI
tags: [ci, static-php-cli, rate-limit, github-actions]
---

# static-php-cli Download Fails with 403 in GitHub Actions

## Symptom

macOS static binary build fails at "Download PHP and extension sources":

```
curl: (56) The requested URL returned error: 403
✗ Download failed: Failed to fetch "https://api.github.com/repos/static-php/static-php-cli-hosted/releases"
```

Linux builds may succeed due to different rate limit buckets per runner.

## Root Cause

`spc download --prefer-pre-built` fetches pre-built PHP extensions from GitHub releases. Without authentication, the GitHub API rate limit (60 req/hr per IP) is quickly exhausted on shared CI runners.

## Solution

Pass `GITHUB_TOKEN` to the download step:

```yaml
- name: Download PHP and extension sources
  env:
    GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
  run: |
    ./spc download \
      --with-php=8.4 \
      --for-extensions="..." \
      --prefer-pre-built
```

`spc` automatically uses the `GITHUB_TOKEN` environment variable for authenticated API requests.

## Prevention

- Always pass `GITHUB_TOKEN` when using static-php-cli in GitHub Actions
- The `GITHUB_TOKEN` is automatically available in all GitHub Actions workflows

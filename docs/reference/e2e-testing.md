# E2E Testing

## Desktop Flow Test

The CLI web app includes an E2E test that replicates the desktop workflow (create project, track broadcasts, delete project):

```bash
# SSH into the remote server
ssh orbit@ai

# Run the E2E test
cd ~/projects/orbit-cli/web
php tests/e2e-desktop-flow-test.php
```

**What it tests:**

1. Creates a project via `POST /api/projects`
2. Tracks provisioning broadcasts until `ready`
3. Deletes via `DELETE /api/projects/{slug}`
4. Tracks deletion broadcasts until `deleted`

**Expected output:**

```
Provision: provisioning -> creating_repo -> cloning -> ... -> ready
Deletion:  deleting -> removing_orchestrator -> removing_files -> deleted
ALL TESTS PASSED
```

## WebSocket Broadcasting Architecture

```
CLI (ReverbBroadcaster) -> Pusher HTTP API -> Reverb container -> Caddy -> WebSocket -> Desktop
```

**Important:** Reverb WebSocket traffic is proxied through Caddy. When Caddy reloads, WebSocket connections are briefly dropped. The CLI must broadcast final status BEFORE triggering a Caddy reload.

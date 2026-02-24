---
date: 2026-02-17
problem_type: infrastructure
component: Docker services, ComposeGenerator
severity: moderate
symptoms:
  - "Reverb can't connect to Redis"
  - "Services can't communicate despite being running"
  - "Connection refused between Docker containers"
root_cause: Legacy containers created before ComposeGenerator added orbit network
tags: [docker, networking, redis, reverb, postgres, orbit-network]
---

# Docker Services on Wrong Network (Legacy Containers)

## Symptom

Reverb (on `orbit` network at 172.20.0.x) cannot connect to Redis (on default `bridge` network at 172.17.0.x). Services are running but can't reach each other.

## Root Cause

The `ComposeGenerator` correctly adds `networks: [orbit]` to all services in the generated `docker-compose.yaml`. However, containers created before this was implemented remain on the default `bridge` network. Docker does not automatically migrate containers to new networks when the compose file changes — the containers must be recreated.

## Solution

### Quick fix (no downtime)

Connect existing containers to the orbit network without recreation:

```bash
docker network connect orbit orbit-redis
docker network connect orbit orbit-postgres
# Verify
docker network inspect orbit --format '{{range .Containers}}{{.Name}} {{end}}'
```

### Permanent fix (brief downtime)

Recreate containers from the generated compose file:

```bash
cd ~/.config/orbit
docker compose down
docker compose up -d
```

## Diagnosis

Check which network each container is on:

```bash
docker ps --format '{{.Names}}\t{{.Networks}}'
```

Expected: all orbit services should show `orbit` (possibly alongside `bridge`).

## Prevention

- After upgrading orbit or regenerating docker-compose, run `docker compose up -d` to recreate containers with correct network config
- The `ComposeGenerator` (line 134) already handles this for new containers:
  ```php
  $serviceConfig['networks'] = ['orbit'];
  ```
- `orbit service:restart {name}` should trigger container recreation

## Related

- `packages/cli/app/Services/ComposeGenerator.php` — generates docker-compose with orbit network

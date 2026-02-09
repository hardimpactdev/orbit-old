# Hub-and-Spoke Architecture Implementation

**Date:** 2026-02-09
**Status:** ✅ Complete
**Context:** Orbit node type system for distributed installations

## Problem

Orbit treated all nodes (local/remote) equally, but different deployment scenarios require different capabilities:
- **Local dev**: Full tooling (Bun, Composer, CLI)
- **Gateway**: Orchestration hub (PHP, Horizon, VPN, DNS)
- **Client nodes**: Minimal runtime (PHP-FPM, Caddy only)

## Solution

Implemented a three-tier node type system:

```
┌─────────────────┐
│  Gateway Node   │  ← Central orchestration hub
│  (Full Stack)   │     - PHP, Caddy, Horizon
└────────┬────────┘     - VPN, DNS management
         │              - Manages client nodes
    ┌────┴────┬──────────────────┐
    │         │                  │
┌───▼────┐ ┌──▼─────┐ ┌─────────▼───┐
│ Client │ │ Client │ │    Local    │
│ Node 1 │ │ Node 2 │ │   (Laptop)  │
└────────┘ └────────┘ └─────────────┘
```

### Node Types

| Type | Use Case | Components | CLI Installed? |
|------|----------|------------|----------------|
| **Local** | Developer laptop | Full dev stack | Yes |
| **Gateway** | Central hub | PHP, Caddy, Horizon, VPN, DNS | Yes |
| **Client** | Managed spoke | PHP-FPM, Caddy, Docker | No |

## Implementation

### 1. Core: Database & Models

**NodeType Enum:**
```php
enum NodeType: string {
    case Local = 'local';
    case Gateway = 'gateway';
    case Client = 'client';
}
```

**Migration:** `2026_02_10_000001_add_node_type_to_nodes_table.php`
- Adds `node_type` column (default: 'local')
- Backward compatible with existing nodes

**Node Model Updates:**
```php
public function isGateway(): bool
public function isClient(): bool
public function isLocalType(): bool
```

### 2. Core: Service Layer

**NodeService:**
```php
public function addNode(string $host, string $user, int $port, NodeType $type, ?string $name): Node
public function testConnection(Node $node): bool
public function getByType(NodeType $type): Collection
public function setActive(Node $node): void
```

### 3. CLI: Install Actions

**New Actions:**
- `InstallHorizon` - Systemd service for queue workers (gateway only)
- Updated `InitializeNode` - Sets node_type during installation

**Updated InstallContext:**
```php
public NodeType $nodeType = NodeType::Local
public bool $skipOrbitCli = false
```

### 4. CLI: Templates

**ClientNodeTemplate:**
```php
// Minimal Linux-only template
// Components: PHP-FPM, Caddy, Docker services
// Skips: Orbit CLI, Horizon, Bun, Composer
```

**GatewayTemplate (Updated):**
```php
// Added: PHP, Caddy, Horizon, Redis
// Keeps: WG Easy VPN, Gateway DNS
```

### 5. CLI: Commands

**node:add**
```bash
orbit node:add 203.0.113.50 --type=client --name="Client 1"
```

**list:nodes**
```bash
orbit list:nodes --type=gateway
```

**node:provision**
```bash
orbit node:provision 3  # Infers template from node type
```

**setup:remote (Updated)**
```bash
orbit setup:remote 203.0.113.10 root --template=gateway
# Now creates Node record with correct type
```

## Usage Examples

### Setup Gateway Node
```bash
# Provision gateway with full orchestration
orbit setup:remote 203.0.113.10 root --template=gateway
```

### Add Client Nodes
```bash
# Add node to database
orbit node:add 203.0.113.50 --type=client --name="Production 1"

# Provision client node
orbit node:provision 3
```

### List All Nodes
```bash
orbit list:nodes

# Output:
# ID  Name         Type     Host            Status   Active
# 1   localhost    local    127.0.0.1       active   ✓
# 2   Gateway      gateway  203.0.113.10    active
# 3   Client 1     client   203.0.113.50    active
```

## Database Migration

### Before (environments)
```
environments table:
- id, name, host, user, port
- is_local (computed from host)
```

### After (nodes)
```
nodes table:
- id, name, host, user, port
- node_type (enum: local/gateway/client)
- is_local removed (replaced by node_type)
```

**Migration:** `2026_02_09_000001_rename_environments_to_nodes.php`
- Renames `environments` → `nodes`
- Drops `is_local` column
- Updates foreign keys in `projects`, `workspaces` tables

## Testing

### Verified Functionality
✅ Database migration (environments → nodes)
✅ NodeType enum casting in model
✅ node:add command (JSON and interactive)
✅ list:nodes command (table and JSON output)
✅ Node service methods (addNode, testConnection, getByType)
✅ Template registration (ClientNodeTemplate)
✅ GatewayTemplate updates (PHP, Caddy, Horizon)
✅ Backward compatibility (existing nodes as 'local')

### Test Commands
```bash
# Add test gateway node
php orbit node:add 203.0.113.100 --type=gateway --name="Test Gateway" --json

# List all nodes
php orbit list:nodes

# Filter by type
php orbit list:nodes --type=gateway --json
```

## Backward Compatibility

✅ **Existing local installations** work unchanged (`orbit install`)
✅ **Existing nodes** default to `node_type = 'local'`
✅ **Remote provisioning** continues to work (`orbit setup:remote`)
✅ **No breaking changes** to existing workflows

## Future Work

### Gateway-to-Client Orchestration
- [ ] Horizon jobs for remote client management
- [ ] Gateway CLI commands for client operations
- [ ] Centralized monitoring dashboard

### Desktop App Updates
- [ ] Update UI terminology (Environment → Node)
- [ ] Node type indicators in interface
- [ ] Gateway/client-specific features

### Client Node Management
- [ ] Remote SSH provisioning from gateway
- [ ] Automated client updates
- [ ] Health monitoring

## Files Changed

### Core Package
- `src/Enums/NodeType.php` (new)
- `src/Models/Node.php` (renamed from Environment)
- `src/Services/NodeService.php` (new)
- `database/migrations/2026_02_09_000001_rename_environments_to_nodes.php` (new)
- `database/migrations/2026_02_10_000001_add_node_type_to_nodes_table.php` (new)
- `database/factories/NodeFactory.php` (updated)

### CLI Package
- `app/Commands/Node/NodeAddCommand.php` (new)
- `app/Commands/Node/NodeListCommand.php` (new)
- `app/Commands/Node/NodeProvisionCommand.php` (new)
- `app/Commands/SetupRemoteCommand.php` (updated)
- `app/Templates/ClientNodeTemplate.php` (new)
- `app/Templates/GatewayTemplate.php` (updated)
- `app/Actions/Install/Linux/InstallHorizon.php` (new)
- `app/Actions/Install/Shared/InitializeNode.php` (updated)
- `app/Data/Install/InstallContext.php` (updated)
- `app/Services/TemplateRegistry.php` (updated)

## Commit

```
feat: implement hub-and-spoke architecture with node types

SHA: 094b353
Date: 2026-02-09
```

## References

- Original plan: Implementation plan from plan mode session
- Related docs: `docs/architecture/node-types.md` (TBD)
- Related issues: None (direct implementation from plan)

# Gateway VPN Auto-Registration Complete

**Date:** 2026-02-09
**Status:** ✅ Complete

## Overview

Implemented automatic VPN registration for client nodes during provisioning. When a client node is provisioned, it's automatically registered with the gateway's WG Easy VPN and assigned a VPN IP.

## Changes Implemented

### 1. Database Schema

**Migration:** `2026_02_10_000002_add_vpn_fields_to_nodes_table.php`

Added three new columns to `nodes` table:
- `vpn_ip` (nullable string) - Assigned VPN IP address
- `gateway_id` (nullable bigint) - Gateway this node connects through
- `vpn_registered_at` (nullable timestamp) - VPN registration timestamp
- Index on `vpn_ip` for faster lookups

**Node Model Updates:**
- Added fields to `$fillable` and `$casts`
- Added `hasVpn()` helper method
- Updated PHPDoc with new properties

### 2. Core Services

**GatewayManager** (`packages/core/src/Services/Gateway/GatewayManager.php`)
- Added `registerVpnClient()` method
- Integrates with WgEasyService to create VPN clients
- Returns assigned VPN IP or null on failure
- *Note: Moved from `packages/cli/app/Services/` to core on 2026-02-13*

**WgEasyService** (no changes needed)
- Already had `createClient()` method for API integration

### 3. Installation Pipeline

**RegisterWithGatewayVpn** (`packages/cli/app/Actions/Install/Shared/RegisterWithGatewayVpn.php`)
- New installation action
- Only runs for client nodes with gateway configured
- Stores VPN IP in context metadata
- Non-fatal failures (continues provisioning)

**InstallContext** (`packages/cli/app/Data/Install/InstallContext.php`)
- Added `gatewayId`, `nodeName`, `hostIp`, `metadata` properties
- Updated `fromOptions()` to parse new fields

**ClientNodeTemplate** (`packages/cli/app/Templates/ClientNodeTemplate.php`)
- Added `RegisterWithGatewayVpn` step at end of installation

### 4. Primary Provisioning Flow (setup:remote)

**SetupCommand** (`packages/cli/app/Commands/SetupCommand.php`)
- Added gateway selection for client nodes
- Prompts user to select gateway or skip VPN registration
- Shows warning if no gateways configured

**SetupRemoteCommand** (`packages/cli/app/Commands/SetupRemoteCommand.php`)
- Added `--gateway` option
- Passes gateway_id when creating Node record
- Calls `registerWithVpn()` after template installation
- Displays VPN IP in completion message

### 5. Deprecated Flow (node:provision)

**NodeProvisionCommand** (`packages/cli/app/Commands/Node/NodeProvisionCommand.php`)
- Added deprecation warning directing users to `orbit setup`
- Still functional but will be removed in future version
- Updates node with VPN IP after provisioning

**NodeAddCommand** (`packages/cli/app/Commands/Node/NodeAddCommand.php`)
- Added gateway selection for client nodes
- Still useful for adding node records without provisioning

## Usage

### Recommended: Via Setup Wizard

```bash
orbit setup
# Select: Remote machine
# Select: Client Node
# Select gateway (or skip)
# Enter IP, user details
# Provisioning runs with VPN registration
```

### Direct Command

```bash
orbit setup:remote 203.0.113.50 root --template=client --gateway=1
```

### Legacy (Deprecated)

```bash
orbit node:add 203.0.113.50 --type=client --gateway=1
orbit node:provision <id>
```

## Verification Steps

1. **Check database**:
   ```bash
   sqlite3 ~/.config/orbit/database.sqlite \
     "SELECT name, vpn_ip, gateway_id FROM nodes WHERE id=<id>"
   ```

2. **Check gateway VPN clients**:
   ```bash
   ssh gateway@<gateway-ip> 'orbit gateway:clients'
   ```

3. **Test VPN connectivity**:
   ```bash
   ping <vpn-ip>
   ssh orbit@<vpn-ip> 'hostname'
   ```

## Architecture Decisions

### Why setup:remote over node:provision?

- **User-friendly**: Interactive wizard with sensible defaults
- **Complete flow**: Handles SSH setup, user creation, hardening
- **Single command**: No need to pre-create node records
- **Consistent**: Same flow for all node types (gateway, client, php-dev)

### Why VPN registration is non-fatal?

- Node provisioning should succeed even if VPN registration fails
- Gateway might be temporarily unreachable
- VPN is convenience, not requirement for basic functionality
- User can manually register later if needed

### Why gateway must be up for provisioning?

- VPN registration requires WG Easy API access
- Enforces hub-and-spoke architecture
- Prevents orphaned client nodes
- Ensures centralized control

## Future Enhancements (Out of Scope)

- [ ] VPN client cleanup on node deletion
- [ ] Import existing VPN clients as nodes
- [ ] VPN health monitoring (last handshake tracking)
- [ ] Multi-gateway support per client
- [ ] Automatic TLD assignment for VPN clients

## Migration Path

**Current users of `node:provision`:**
1. Deprecation warning added (no breaking changes)
2. Migrate to `orbit setup` at your convenience
3. `node:provision` will be removed in v1.0.0

**Benefits of migrating:**
- Better UX with interactive wizard
- Gateway selection during setup
- VPN registration in one flow
- No need to pre-create node records

## Related Files

### Modified
- `packages/core/src/Models/Node.php`
- `packages/core/src/Services/Gateway/GatewayManager.php` *(moved from cli)*
- `packages/cli/app/Data/Install/InstallContext.php`
- `packages/cli/app/Templates/ClientNodeTemplate.php`
- `packages/cli/app/Commands/SetupCommand.php`
- `packages/cli/app/Commands/SetupRemoteCommand.php`
- `packages/cli/app/Commands/Node/NodeProvisionCommand.php`
- `packages/cli/app/Commands/Node/NodeAddCommand.php`

### Created
- `packages/core/database/migrations/2026_02_10_000002_add_vpn_fields_to_nodes_table.php`
- `packages/cli/app/Actions/Install/Shared/RegisterWithGatewayVpn.php`

## Testing Recommendations

1. **Fresh client provisioning**:
   ```bash
   orbit setup
   # Remote → Client → Select gateway → Provision
   ```

2. **Client without gateway**:
   ```bash
   orbit setup
   # Remote → Client → Skip VPN
   ```

3. **Gateway unavailable**:
   - Stop gateway
   - Provision client (should complete with warning)

4. **VPN connectivity**:
   - Ping VPN IP
   - SSH via VPN IP
   - Verify WG Easy shows client

## Completion Checklist

- [x] Database migration created and applied
- [x] Node model updated with new fields
- [x] GatewayManager has registerVpnClient()
- [x] RegisterWithGatewayVpn action created
- [x] ClientNodeTemplate includes VPN registration
- [x] SetupCommand prompts for gateway selection
- [x] SetupRemoteCommand handles VPN registration
- [x] NodeProvisionCommand deprecated with warning
- [x] NodeAddCommand supports gateway selection
- [x] PHPStan errors resolved
- [x] Documentation updated

**Status:** Ready for testing and deployment
